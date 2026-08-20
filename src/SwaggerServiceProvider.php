<?php

namespace Foziluff\Swagger;

use Foziluff\Swagger\Console\ClearSwaggerDocs;
use Foziluff\Swagger\Console\GenerateSwaggerDocs;
use Illuminate\Support\ServiceProvider;

class SwaggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([GenerateSwaggerDocs::class, ClearSwaggerDocs::class]);
    }

    public function boot(): void
    {
        //
    }
}
