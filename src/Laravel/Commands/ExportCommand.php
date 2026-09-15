<?php

declare(strict_types=1);

namespace Geni\Laravel\Commands;

use Geni\Inference\DocumentAssembler;
use Geni\Laravel\Commands\Concerns\BuildsInfoOptions;
use Geni\Laravel\Commands\Concerns\BuildsSecurityConfig;
use Geni\Laravel\RouteDiscoverer;
use Geni\SchemaReader\SchemaReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class ExportCommand extends Command
{
    use BuildsInfoOptions;
    use BuildsSecurityConfig;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geni:export
                            {--path= : The destination file path for the OpenAPI document}
                            {--api=default : The API configuration name to export}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export the generated OpenAPI specification to a file';

    public function handle(): int
    {
        $apiName = $this->option('api') ?: 'default';
        $path = $this->option('path') ?: 'openapi.json';

        $cacheEnabled = config('geni.cache.enabled', false);
        $cacheKey = (config('geni.cache.key', 'geni.openapi')).'.'.$apiName;
        $cacheStore = config('geni.cache.store');

        $json = null;
        if ($cacheEnabled && Cache::store($cacheStore)->has($cacheKey)) {
            $json = Cache::store($cacheStore)->get($cacheKey);
        }

        if ($json === null) {
            // 1. Discover routes
            $routes = RouteDiscoverer::discover();

            $plainRoutes = array_map(function ($route) {
                return [
                    'uri' => $route->uri,
                    'methods' => $route->methods,
                    'action' => $route->action,
                    'middleware' => $route->middleware,
                    'name' => $route->name,
                ];
            }, $routes);

            // 2. Read schema if migration paths are configured
            $migrationPaths = config('geni.migration_paths', [database_path('migrations')]);
            $dbSchema = null;
            if (class_exists(SchemaReader::class)) {
                $schemaReader = new SchemaReader;
                $dbSchema = $schemaReader->read($migrationPaths);
            }

            // 3. Assemble document
            $assembler = new DocumentAssembler;
            $document = $assembler->assemble(
                routes: $plainRoutes,
                infoOptions: $this->buildInfoOptions(),
                dbSchema: $dbSchema,
                schemaOverrides: config('geni.schema_overrides', []),
                securityConfig: $this->buildSecurityConfig(),
                apiName: $apiName
            );

            $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode OpenAPI document to JSON.');

                return self::FAILURE;
            }

            if ($cacheEnabled) {
                Cache::store($cacheStore)->put($cacheKey, $json);
            }
        }

        // 4. Write to file
        $destination = file_exists($path) || str_starts_with($path, '/') ? $path : base_path($path);
        $directory = dirname($destination);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($destination, $json);

        $this->info(sprintf('OpenAPI document successfully exported to %s', $path));

        return self::SUCCESS;
    }
}
