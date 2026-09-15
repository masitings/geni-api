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

final class CacheCommand extends Command
{
    use BuildsInfoOptions;
    use BuildsSecurityConfig;

    protected $signature = 'geni:cache
                            {--api=default : The API configuration name to cache}';

    protected $description = 'Pre-generate and cache the OpenAPI documentation specification';

    public function handle(): int
    {
        $apiName = $this->option('api') ?: 'default';
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

        $migrationPaths = config('geni.migration_paths', [database_path('migrations')]);
        $dbSchema = null;
        if (class_exists(SchemaReader::class)) {
            $schemaReader = new SchemaReader;
            $dbSchema = $schemaReader->read($migrationPaths);
        }

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

        $cacheKey = (config('geni.cache.key', 'geni.openapi')).'.'.$apiName;
        $cacheStore = config('geni.cache.store');

        Cache::store($cacheStore)->put($cacheKey, $json);

        $this->info(sprintf('OpenAPI specification for "%s" successfully cached under key "%s".', $apiName, $cacheKey));

        return self::SUCCESS;
    }
}
