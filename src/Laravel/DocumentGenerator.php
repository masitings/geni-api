<?php

declare(strict_types=1);

namespace Geni\Laravel;

use Geni\Inference\Document\OpenApiDocument;
use Geni\Inference\Document\Server;
use Geni\Inference\DocumentAssembler;
use Geni\Inference\ErrorResponseInferer;
use Geni\Inference\ValidationRuleSchemaMapper;
use Geni\Laravel\Commands\Concerns\BuildsInfoOptions;
use Geni\Laravel\Commands\Concerns\BuildsSecurityConfig;
use Geni\Laravel\Contracts\DocumentTransformer;
use Geni\Laravel\Contracts\OperationTransformer;
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
     * An assembler wired with the API's rule transformers and exception
     * mappers. Pass it to generate() to read its recorded diagnostics.
     */
    public function assemblerFor(string $apiName = 'default'): DocumentAssembler
    {
        $configuration = app(GeniConfigurationRegistry::class)->resolved($apiName);

        return new DocumentAssembler(
            schemaMapper: new ValidationRuleSchemaMapper(customRuleTransformers: $configuration->ruleTransformers()),
            errorInferer: new ErrorResponseInferer(customExceptionMappers: $configuration->exceptionMappers()),
        );
    }

    /**
     * Assemble the document for the given API, then apply configured servers
     * and the operation / document transformers registered for it.
     */
    public function generate(string $apiName = 'default', ?DocumentAssembler $assembler = null): OpenApiDocument
    {
        $routes = RouteDiscoverer::discover($apiName);
        $plainRoutes = array_map(fn (DiscoveredRoute $route): array => [
            'uri' => $route->uri,
            'methods' => $route->methods,
            'action' => $route->action,
            'middleware' => $route->middleware,
            'name' => $route->name,
        ], $routes);

        $apiConfig = ($apiName !== 'default') ? (config("geni.apis.{$apiName}") ?: []) : [];

        $migrationPaths = $apiConfig['migration_paths'] ?? config('geni.migration_paths', [database_path('migrations')]);
        $dbSchema = (new SchemaReader)->read($migrationPaths);

        $schemaOverrides = $apiConfig['schema_overrides'] ?? config('geni.schema_overrides', []);

        $document = ($assembler ?? $this->assemblerFor($apiName))->assemble(
            routes: $plainRoutes,
            infoOptions: $this->buildInfoOptions($apiName),
            dbSchema: $dbSchema,
            schemaOverrides: $schemaOverrides,
            securityConfig: $this->buildSecurityConfig(),
            apiName: $apiName
        );

        $servers = $apiConfig['servers'] ?? config('geni.servers');
        foreach (is_array($servers) ? $servers : [] as $description => $url) {
            $document->servers->add(new Server([
                'url' => str_starts_with((string) $url, 'http') ? (string) $url : url((string) $url),
                'description' => is_string($description) ? $description : null,
            ]));
        }

        $this->applyTransformers($document, $routes, $apiName);

        return $document;
    }

    /**
     * @param  array<int, DiscoveredRoute>  $routes
     */
    private function applyTransformers(OpenApiDocument $document, array $routes, string $apiName): void
    {
        $configuration = app(GeniConfigurationRegistry::class)->resolved($apiName);
        $operationTransformers = $configuration->operationTransformers();

        if ($operationTransformers !== []) {
            foreach ($routes as $route) {
                $pathItem = $document->paths->items['/'.ltrim($route->uri, '/')] ?? null;

                foreach ($route->methods as $method) {
                    $operation = $pathItem?->operations[strtolower($method)] ?? null;
                    if ($operation === null) {
                        continue;
                    }

                    foreach ($operationTransformers as $transformer) {
                        if ($transformer instanceof OperationTransformer) {
                            $transformer->handle($operation, $route, $apiName);
                        } else {
                            $transformer($operation, $route, $apiName);
                        }
                    }
                }
            }
        }

        foreach ($configuration->documentTransformers() as $transformer) {
            if ($transformer instanceof DocumentTransformer) {
                $transformer->handle($document, $apiName);
            } else {
                $transformer($document, $apiName);
            }
        }
    }

    /**
     * Cache key under which the serialized JSON document for an API is stored.
     */
    public static function cacheKey(string $apiName): string
    {
        return config('geni.cache.key', 'geni.openapi').'.'.$apiName;
    }
}
