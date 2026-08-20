<?php

namespace Foziluff\Swagger\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ClearSwaggerDocs extends Command
{
    protected $signature = 'swagger:clear';

    protected $description = 'Remove the generated Swagger JSON and HTML files';

    public function handle(): void
    {
        $jsonPath = public_path('api-docs.json');
        $htmlPath = public_path('docs.html');

        $deleted = false;

        if (File::exists($jsonPath)) {
            File::delete($jsonPath);
            $this->info('Deleted api-docs.json');
            $deleted = true;
        }

        if (File::exists($htmlPath)) {
            File::delete($htmlPath);
            $this->info('Deleted docs.html');
            $deleted = true;
        }

        if (! $deleted) {
            $this->info('No Swagger files found to delete.');
        } else {
            $this->info('Swagger documentation cleared successfully.');
        }
    }
}
