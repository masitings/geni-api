<?php

declare(strict_types=1);

namespace Geni\Laravel;

use Geni\Inference\Document\OpenApiDocument;
use Geni\Inference\DocumentAssembler;
use Geni\Laravel\Commands\Concerns\BuildsInfoOptions;
use Geni\Laravel\Commands\Concerns\BuildsSecurityConfig;
use Geni\SchemaReader\SchemaReader;

/**
 * Single entry point that assembles the OpenAPI document for a named API.
 *
 * The docs controller and every geni:* command go through this class so a
 * given API name always resolves the same routes, migration paths, schema
 * overrides and info block, whether the document is served over HTTP,
 * cached, exported, checked for drift or analyzed.
 */
final class DocumentGenerator
{
    use BuildsInfoOptions;
    use BuildsSecurityConfig;

    /**
     * Determine whether the given name is a documented API ("default" or a key of config('geni.apis')).
     */
    public static function apiExists(string $apiName): bool
    {
        if ($apiName === 'default') {
            return true;
        }

        $apis = config('geni.apis', []);

        return is_array($apis) && array_key_exists($apiName, $apis) && is_array($apis[$apiName]);
    }

    /**
     * Assemble the document for the given API. Pass an assembler to read its
     * recorded diagnostics afterwards (used by geni:analyze).
     */
    public function generate(string $apiName = 'default', ?DocumentAssembler $assembler = null): OpenApiDocument
    {
        $plainRoutes = array_map(fn (DiscoveredRoute $route): array => [
            'uri' => $route->uri,
            'methods' => $route->methods,
            'action' => $route->action,
            'middleware' => $route->middleware,
            'name' => $route->name,
        ], RouteDiscoverer::discover($apiName));

        $apiConfig = ($apiName !== 'default') ? (config("geni.apis.{$apiName}") ?: []) : [];

        $migrationPaths = $apiConfig['migration_paths'] ?? config('geni.migration_paths', [database_path('migrations')]);
        $dbSchema = (new SchemaReader)->read($migrationPaths);

        $schemaOverrides = $apiConfig['schema_overrides'] ?? config('geni.schema_overrides', []);

        return ($assembler ?? new DocumentAssembler)->assemble(
            routes: $plainRoutes,
            infoOptions: $this->buildInfoOptions($apiName),
            dbSchema: $dbSchema,
            schemaOverrides: $schemaOverrides,
            securityConfig: $this->buildSecurityConfig(),
            apiName: $apiName
        );
    }

    /**
     * Cache key under which the serialized JSON document for an API is stored.
     */
    public static function cacheKey(string $apiName): string
    {
        return config('geni.cache.key', 'geni.openapi').'.'.$apiName;
    }
}
