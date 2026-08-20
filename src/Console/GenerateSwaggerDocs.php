<?php

namespace Foziluff\Swagger\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum as EnumRule;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use UnitEnum;

class GenerateSwaggerDocs extends Command
{
    protected $signature = 'swagger';

    /**
     * @throws ReflectionException
     */
    public function handle(): void
    {
        $paths = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (
                ! Str::startsWith($route->getActionName(), 'App\\Http\\Controllers') ||
                ! in_array('api', $route->gatherMiddleware())
            ) {
                continue;
            }

            $uri = '/'.ltrim($route->uri(), '/');
            $methods = array_filter($route->methods(), fn ($m) => $m !== 'HEAD');

            foreach ($methods as $httpMethod) {
                $schema = null;

                $action = $route->getActionName();
                [$controller, $method] = explode('@', $action);
                $formRequest = $this->getFormRequest($controller, $method);

                $pathItem = [
                    'tags' => [$this->humanReadableTag($controller)],
                    'summary' => $this->humanReadableSummary($controller, $method),
                    'parameters' => $this->extractPathParameters($uri),
                    'responses' => $this->extractResponseCodes($controller, $method, $route),
                ];

                $originalMethod = strtolower($httpMethod);

                if (in_array($originalMethod, ['put', 'patch'])) {
                    $httpMethod = 'POST';
                }

                if ($formRequest) {
                    $requestBody = $this->requestBodyFromFormRequest($formRequest);

                    $contentTypes = array_keys($requestBody['content']);
                    $firstContentType = $contentTypes[0] ?? 'application/json';
                    $rawSchema = $requestBody['content'][$firstContentType]['schema'] ?? [];

                    if (strtoupper($originalMethod) === 'GET') {
                        foreach ($rawSchema['properties'] ?? [] as $name => $prop) {
                            $pathItem['parameters'][] = [
                                'name' => $name,
                                'in' => 'query',
                                'required' => in_array($name, $rawSchema['required'] ?? []),
                                'schema' => $prop,
                            ];
                        }
                    } else {
                        $schema = json_decode(json_encode($rawSchema), true);

                        if (in_array($originalMethod, ['put', 'patch'])) {
                            $schema['properties']['_method'] = [
                                'type' => 'string',
                                'enum' => [strtoupper($originalMethod)],
                            ];
                            $schema['required'][] = '_method';
                        }

                        $pathItem['requestBody'] = [
                            'content' => [
                                $firstContentType => [
                                    'schema' => $schema,
                                ],
                            ],
                        ];
                    }
                } elseif (in_array($originalMethod, ['put', 'patch'])) {
                    $pathItem['requestBody'] = [
                        'content' => [
                            'multipart/form-data' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        '_method' => [
                                            'type' => 'string',
                                            'enum' => [strtoupper($originalMethod)],
                                        ],
                                    ],
                                    'required' => ['_method'],
                                ],
                            ],
                        ],
                    ];
                }

                if ($this->requiresAuth($route)) {
                    $pathItem['security'] = [['bearerAuth' => []]];
                }

                $paths[$uri][strtolower($httpMethod)] = $pathItem;
            }
        }

        $yaml = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Documentation',
                'version' => '1.0.0',
            ],
            'servers' => [
                [
                    'url' => config('app.url'),
                    'description' => 'Base API URL',
                ],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ],
                ],
            ],
        ];

        $outputPath = public_path('api-docs.json');

        $htmlOutputPath = public_path('docs.html');

        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0777, true);
        }

        if (! is_dir(dirname($htmlOutputPath))) {
            mkdir(dirname($htmlOutputPath), 0777, true);
        }

        file_put_contents($outputPath, json_encode($yaml, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        file_put_contents($htmlOutputPath, $this->getHtml());
        $this->info('Swagger JSON generated: '.config('app.url').'/docs.html');
    }

    private function getHtml(): string
    {
        return <<<'PHP'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Swagger UI</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist/swagger-ui.css" />
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://unpkg.com/swagger-ui-dist/swagger-ui-bundle.js"></script>
<script>
    SwaggerUIBundle({
        url: '/api-docs.json',
        dom_id: "#swagger-ui",
        docExpansion: "none"
    });
</script>
</body>
</html>
PHP;
    }

    protected function humanReadableTag(string $controller): string
    {
        $name = str_replace('Controller', '', class_basename($controller));

        return Str::title(Str::snake($name, ' '));
    }

    protected function humanReadableSummary(string $controller, string $method): string
    {
        $actionMap = [
            'store' => 'create',
            'update' => 'update',
            'destroy' => 'delete',
            'index' => 'list of',
            'show' => 'get',
        ];

        $resource = Str::snake(str_replace('Controller', '', class_basename($controller)), ' ');

        if ($method === 'index') {
            if (str_ends_with($resource, 'y')) {
                $resource = substr($resource, 0, -1).'ies';
            } else {
                $resource .= 's';
            }
        }
        $action = $actionMap[$method] ?? $method;

        $actionValue = "$action $resource";

        if (empty($actionMap[$method])) {
            $actionValue = $action;
        }

        return $actionValue;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function extractPathParameters(string $uri): array
    {
        preg_match_all('/{(\w+)}/', $uri, $matches);

        return collect($matches[1])->map(function ($param) {
            return [
                'name' => $param,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        })->toArray();
    }

    /**
     * @return class-string<FormRequest>|null
     */
    protected function getFormRequest(string $controllerClass, string $method): ?string
    {
        if (! method_exists($controllerClass, $method)) {
            return null;
        }
        $reflection = new ReflectionMethod($controllerClass, $method);

        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ReflectionException
     */
    protected function requestBodyFromFormRequest(string $formRequestClass): array
    {
        $instance = (new ReflectionClass($formRequestClass))->newInstanceWithoutConstructor();

        if (method_exists($instance, 'setContainer')) {
            $instance->setContainer(app())->setRedirector(app('redirect'));
        }

        $rules = method_exists($instance, 'rules') ? $instance->rules() : [];

        $properties = [];
        $requiredFields = [];
        $hasFile = false;

        foreach ($rules as $field => $rule) {
            //            $parsed = is_array($rule) ? array_filter($rule, fn($r) => is_string($r)) : explode('|', $rule);
            $parsed = is_array($rule)
                ? $rule
                : explode('|', $rule);

            $type = $this->guessType($parsed);

            if ($type === 'file') {
                $hasFile = true;
            }

            if (in_array('required', $parsed)) {
                $requiredFields[] = $field;
            }

            $prop = ['type' => $type];

            if (in_array('nullable', $parsed)) {
                $prop['nullable'] = true;
            }

            if ($enum = $this->extractEnum($parsed)) {
                $prop['enum'] = $enum;
            }
            if ($type === 'boolean') {
                $prop['enum'] = [0, 1];
            }

            foreach ($parsed as $rulePart) {
                if (! is_string($rulePart)) {
                    continue;
                }
                if (Str::startsWith($rulePart, 'min:')) {
                    $value = (int) Str::after($rulePart, 'min:');
                    if ($type === 'string') {
                        $prop['minLength'] = $value;
                    } elseif (in_array($type, ['integer', 'number'])) {
                        $prop['minimum'] = $value;
                    }
                }

                if (Str::startsWith($rulePart, 'max:')) {
                    $value = (int) Str::after($rulePart, 'max:');
                    if ($type === 'string') {
                        $prop['maxLength'] = $value;
                    } elseif (in_array($type, ['integer', 'number'])) {
                        $prop['maximum'] = $value;
                    }
                }

                if (Str::startsWith($rulePart, 'between:')) {
                    [$min, $max] = str($rulePart)->after('between:')->explode(',')->map(fn ($v) => (int) $v);

                    if ($type === 'string') {
                        $prop['minLength'] = $min;
                        $prop['maxLength'] = $max;
                    } elseif (in_array($type, ['integer', 'number'])) {
                        $prop['minimum'] = $min;
                        $prop['maximum'] = $max;
                    }
                }
            }

            $this->addPropertyToNestedArray($properties, $field, $prop);
        }

        $contentType = $hasFile ? 'multipart/form-data' : 'application/json';

        if ($contentType === 'multipart/form-data') {
            $newProperties = [];
            foreach ($properties as $key => $prop) {
                if (isset($prop['type']) && $prop['type'] === 'array') {
                    $newKey = $key.'[]';
                    $newProperties[$newKey] = $prop;

                    $index = array_search($key, $requiredFields);
                    if ($index !== false) {
                        $requiredFields[$index] = $newKey;
                    }
                } else {
                    $newProperties[$key] = $prop;
                }
            }
            $properties = $newProperties;
        }

        $example = method_exists($instance, 'example') ? $instance->example() : null;

        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'required' => $requiredFields,
        ];

        if (is_array($example)) {
            $schema['example'] = $example;
        }

        return [
            'content' => [
                $contentType => [
                    'schema' => $schema,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $prop
     */
    public function addPropertyToNestedArray(array &$properties, string $field, array $prop): void
    {
        if (Str::contains($field, '.*')) {
            $arrayName = Str::before($field, '.*');
            $nestedField = trim(Str::after($field, '.*'), '.');

            if (! isset($properties[$arrayName])) {
                $properties[$arrayName] = [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                ];
            } else {
                if (! isset($properties[$arrayName]['items'])) {
                    $properties[$arrayName]['items'] = ['type' => 'object'];
                }
                if (! isset($properties[$arrayName]['items']['properties'])) {
                    $properties[$arrayName]['items']['properties'] = [];
                }
            }
            if ($nestedField == '') {
                $properties[$arrayName]['items']['type'] = $prop['type'];

                return;
            }
            $this->addPropertyToNestedArray($properties[$arrayName]['items']['properties'], $nestedField, $prop);
        } else {
            $properties[$field] = $prop;
        }
    }

    /**
     * @param  array<string, mixed>  $prop
     * @param  array<string, mixed>  $properties
     */
    protected function processNestedField(string $field, array $prop, array &$properties): void
    {
        $segments = explode('.*.', $field);
        $currentLevel = &$properties;

        foreach ($segments as $segment) {
            if (! isset($currentLevel[$segment])) {
                $currentLevel[$segment] = [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                ];
            }
            $currentLevel = &$currentLevel[$segment]['items']['properties'];
        }

        $currentLevel[$segments[count($segments) - 1]] = $prop;
    }

    /**
     * @param  array<int|string, mixed>  $rules
     * @return list<int|string>|null
     */
    protected function extractEnum(array $rules): ?array
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && Str::startsWith($rule, 'in:')) {
                return explode(',', Str::after($rule, 'in:'));
            }

            if ($rule instanceof EnumRule) {
                $reflection = new ReflectionClass($rule);
                $property = $reflection->getProperty('type');
                $property->setAccessible(true);
                /** @var class-string<UnitEnum> $enumClass */
                $enumClass = $property->getValue($rule);

                return array_map(
                    fn ($case) => $case->value ?? $case->name,
                    $enumClass::cases()
                );
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $rules
     */
    protected function guessType(array $rules): string
    {
        $rules = array_filter($rules, fn ($r) => is_string($r));
        $rules = collect($rules)->map(fn ($r) => Str::lower($r))->all();

        if ($this->containsMime($rules)) {
            return 'file';
        }

        if (in_array('file', $rules) || in_array('image', $rules)) {
            return 'file';
        }
        if (in_array('integer', $rules)) {
            return 'integer';
        }
        if (in_array('numeric', $rules)) {
            return 'number';
        }
        if (in_array('boolean', $rules)) {
            return 'boolean';
        }
        if (in_array('array', $rules)) {
            return 'array';
        }

        return 'string';
    }

    /**
     * @param  array<int|string, mixed>  $rules
     */
    protected function containsMime(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            if (Str::startsWith($rule, 'mimes') || Str::startsWith($rule, 'mimetypes')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int|string, array<string, string>>
     */
    protected function defaultResponses(): array
    {
        return [
            '200' => ['description' => 'Success'],
            '403' => ['description' => 'Forbidden'],
            '422' => ['description' => 'Validation error'],
        ];
    }

    /**
     * @param  \Illuminate\Routing\Route|null  $route
     * @return array<int|string, mixed>
     *
     * @throws ReflectionException
     */
    protected function extractResponseCodes(string $controller, string $method, $route = null): array
    {
        $file = (new ReflectionClass($controller))->getFileName();
        $lines = file($file);
        $refMethod = new ReflectionMethod($controller, $method);

        $start = $refMethod->getStartLine() - 1;
        $end = $refMethod->getEndLine() - 1;

        $codeLines = array_slice($lines, $start, $end - $start + 1);
        $code = implode('', $codeLines);

        preg_match_all('/response\\(\\)->json\\(.*?,\\s*(\\d{3})\\)/s', $code, $responseMatches);
        preg_match_all('/response\(\)->json\([^)]*\)/', $code, $jsonCalls);
        preg_match_all('/abort\((\d{3})\)/', $code, $abortMatches);

        $statusCodes = [];

        if (! empty($responseMatches[1])) {
            $statusCodes = array_merge($statusCodes, $responseMatches[1]);
        }

        if (Str::contains($code, 'OrFail')) {
            $statusCodes[] = '404';
        }

        if (count($jsonCalls[0]) > count($responseMatches[0])) {
            $statusCodes[] = '200';
        }

        if (! empty($abortMatches[1])) {
            $statusCodes = array_merge($statusCodes, $abortMatches[1]);
        }

        $usesFormRequest = $this->getFormRequest($controller, $method);
        if ($usesFormRequest) {
            $statusCodes[] = '422';
        }

        if ($route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && Str::startsWith($middleware, 'throttle')) {
                    $statusCodes[] = '429';
                    break;
                }
            }
        }

        $exceptionMap = $this->getExceptionMap();
        $statusCodes = array_merge($statusCodes, $this->extractExceptionsFromCode($code, $exceptionMap));

        $refClass = new ReflectionClass($controller);
        preg_match_all('/\$this->([a-zA-Z0-9_]+)->([a-zA-Z0-9_]+)\(/', $code, $serviceMatches, PREG_SET_ORDER);
        foreach ($serviceMatches as $match) {
            $propertyName = $match[1];
            $methodName = $match[2];

            if ($refClass->hasProperty($propertyName)) {
                $prop = $refClass->getProperty($propertyName);
                $type = $prop->getType();
                if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                    $serviceClass = $type->getName();
                    if (class_exists($serviceClass)) {
                        $refService = new ReflectionClass($serviceClass);
                        if ($refService->hasMethod($methodName)) {
                            $serviceMethod = $refService->getMethod($methodName);
                            if ($serviceMethod->isUserDefined() && ! $serviceMethod->isAbstract()) {
                                $serviceStart = $serviceMethod->getStartLine() - 1;
                                $serviceEnd = $serviceMethod->getEndLine() - 1;
                                if ($serviceStart >= 0 && $serviceEnd >= $serviceStart) {
                                    $serviceLines = file($refService->getFileName());
                                    $serviceCode = implode('', array_slice($serviceLines, $serviceStart, $serviceEnd - $serviceStart + 1));

                                    preg_match_all('/abort\((\d{3})\)/', $serviceCode, $svcAbortMatches);
                                    if (! empty($svcAbortMatches[1])) {
                                        $statusCodes = array_merge($statusCodes, $svcAbortMatches[1]);
                                    }

                                    if (Str::contains($serviceCode, 'OrFail')) {
                                        $statusCodes[] = '404';
                                    }

                                    $statusCodes = array_merge($statusCodes, $this->extractExceptionsFromCode($serviceCode, $exceptionMap));
                                }
                            }
                        }
                    }
                }
            }
        }

        $codes = collect($statusCodes)
            ->filter()
            ->unique()
            ->mapWithKeys(function ($code) {
                return [
                    $code => [
                        'description' => "Response $code",
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                ],
                            ],
                        ],
                    ],
                ];
            })
            ->toArray();

        $example = $this->exampleFromFormRequest($controller, $method);
        if ($example) {
            $codes['1'] = [
                'description' => 'Example',
                'content' => [
                    'application/json' => [
                        'example' => $example,
                    ],
                ],
            ];
        }

        return count($codes) > 0 ? $codes : ['200' => ['description' => 'OK']];
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws ReflectionException
     */
    protected function exampleFromFormRequest(string $controllerClass, string $method): ?array
    {
        $formRequestClass = $this->getFormRequest($controllerClass, $method);

        if (! $formRequestClass) {
            return null;
        }

        $instance = (new ReflectionClass($formRequestClass))->newInstanceWithoutConstructor();

        if (method_exists($instance, 'example')) {
            return $instance->example();
        }

        return null;
    }

    /** @var array<string, string>|null */
    protected ?array $exceptionMapCache = null;

    /**
     * @return array<string, string>
     */
    protected function getExceptionMap(): array
    {
        if ($this->exceptionMapCache !== null) {
            return $this->exceptionMapCache;
        }

        $this->exceptionMapCache = [];
        $appPhp = base_path('bootstrap/app.php');
        if (file_exists($appPhp)) {
            $content = file_get_contents($appPhp);
            preg_match_all('/\$e\s+instanceof\s+([A-Za-z0-9_\\\\]+)\s*=>\s*\[(?:[^,]*?::([A-Z0-9_]+)|(\d{3}))/', $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $exceptionClass = class_basename($match[1]);
                if (! empty($match[2])) {
                    $constantName = '\Symfony\Component\HttpFoundation\Response::'.$match[2];
                    if (defined($constantName)) {
                        $this->exceptionMapCache[$exceptionClass] = (string) constant($constantName);
                    }
                } elseif (! empty($match[3])) {
                    $this->exceptionMapCache[$exceptionClass] = $match[3];
                }
            }
        }

        return $this->exceptionMapCache;
    }

    /**
     * @param  array<string, string>  $exceptionMap
     * @return array<int, string>
     */
    protected function extractExceptionsFromCode(string $code, array $exceptionMap): array
    {
        $codes = [];
        preg_match_all('/throw\s+new\s+([a-zA-Z0-9_\\\\]+)/', $code, $throwMatches);
        if (! empty($throwMatches[1])) {
            foreach ($throwMatches[1] as $exceptionClassFullName) {
                $exceptionClass = class_basename($exceptionClassFullName);
                if (isset($exceptionMap[$exceptionClass])) {
                    $codes[] = $exceptionMap[$exceptionClass];
                }
            }
        }

        return $codes;
    }

    /**
     * @param  \Illuminate\Routing\Route  $route
     */
    protected function requiresAuth($route): bool
    {
        $middlewares = $route->gatherMiddleware();

        foreach ($middlewares as $middleware) {
            if (Str::contains($middleware, ['auth', 'auth:sanctum', 'auth:api', 'auth:jwt', 'optional.sanctum'])) {
                return true;
            }
        }

        return false;
    }
}
