# Laravel Swagger Generator

Auto-generate OpenAPI/Swagger 3.0 specs from Laravel Routes & Request classes without writing extensive annotations.

## Features

- **Zero Annotations**: Reads your existing `FormRequest` classes and controller methods to generate Swagger documentation automatically.
- **Validation Rules to Schema**: Converts Laravel validation rules (`required`, `string`, `integer`, `mimes`, `min`, `max`, `in:`, etc.) into OpenAPI schemas.
- **Automatic Content-Types**: Detects file uploads (`multipart/form-data`) vs JSON payloads (`application/json`).
- **Response Codes**: Inspects your controller code and services for `abort()`, `response()->json()`, and throws exceptions to determine possible HTTP response codes.
- **Security Schemes**: Automatically adds Bearer Authentication for routes protected by `auth`, `auth:sanctum`, `auth:api`, etc.
- **Examples**: Supports adding an `example()` method to your `FormRequest` to provide payload examples.
- **Built-in UI**: Generates a self-contained `docs.html` file using Swagger UI.

## Installation

You can install the package via composer:

```bash
composer require foziluff/swagger
```

*(Note: Adjust the installation command if the package is published or required from a custom repository).*

For Laravel 11+, the service provider is automatically registered.

## Usage

To generate the Swagger documentation, simply run:

```bash
php artisan swagger
```

This will parse your `api` routes (routes using the `api` middleware) and generate two files in your `public` directory:
- `public/api-docs.json`: The OpenAPI 3.0 specification.
- `public/docs.html`: The Swagger UI interface.

You can view the documentation by navigating to `{APP_URL}/docs.html` (e.g., `http://localhost:8000/docs.html`).

> [!NOTE]  
> The **Base API URL** in the generated Swagger UI is automatically pulled from your `.env` file's `APP_URL` configuration (`config('app.url')`). Ensure this is set correctly so that you can execute API requests directly from the Swagger UI.

### Clearing Documentation

To remove the generated files:

```bash
php artisan swagger:clear
```

## Advanced Features

### Adding Request Examples

You can add an `example()` method to your `FormRequest` to provide default payload examples in the generated documentation:

```php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ];
    }

    public function example(): array
    {
        return [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
        ];
    }
}
```

### Supported Validation Rules

The generator maps the following rules to OpenAPI types:
- `integer`, `numeric`, `boolean`, `array`, `string`
- `file`, `image`, `mimes`, `mimetypes` (sets type to `file` and uses `multipart/form-data`)
- `min:`, `max:`, `between:`
- `in:` or PHP 8.1 Enums (using `Rule::enum()`)

### Exception to Response Code Mapping

The generator inspects your `bootstrap/app.php` file to see how custom exceptions are mapped to HTTP status codes, and includes them in the route's possible responses. It also scans your controller methods for `abort(404)` or `response()->json(..., 201)`.

## Requirements

- PHP 8.2 or higher
- Laravel 11.0, 12.0, or 13.0

## License

The MIT License (MIT).
