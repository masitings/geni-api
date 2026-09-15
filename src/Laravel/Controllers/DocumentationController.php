<?php

declare(strict_types=1);

namespace Geni\Laravel\Controllers;

use Geni\Inference\Document\OpenApiDocument;
use Geni\Inference\DocumentAssembler;
use Geni\Laravel\Commands\Concerns\BuildsInfoOptions;
use Geni\Laravel\Commands\Concerns\BuildsSecurityConfig;
use Geni\Laravel\Mcp\McpManifestGenerator;
use Geni\Laravel\Mcp\McpProtocolHandler;
use Geni\Laravel\Renderer;
use Geni\Laravel\Renderers\BladeRenderer;
use Geni\Laravel\RouteDiscoverer;
use Geni\SchemaReader\SchemaReader;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

final class DocumentationController extends Controller
{
    use BuildsInfoOptions;
    use BuildsSecurityConfig;

    /**
     * Resolve the requested API name from route parameter or query string.
     */
    public function resolveApiName(): string
    {
        return (string) (request()->route('api')
            ?? request()->route()?->getAction('api')
            ?? request()->query('api', 'default'));
    }

    /**
     * Get a list of all available APIs for switcher dropdown and navigation.
     *
     * @return list<array{key: string, title: string, version: string, label: string, ui_url: string, json_url: string, mcp_url: string, current: bool}>
     */
    public function getAvailableApis(?string $currentApi = null): array
    {
        $current = $currentApi ?? $this->resolveApiName();
        $apis = config('geni.apis', []);
        $uiBasePath = config('geni.docs_ui_path', 'docs/api');

        if (empty($apis)) {
            $defaultTitle = config('geni.title') ?: config('app.name', 'Laravel API');
            $defaultVersion = config('geni.version', '1.0.0');

            return [
                [
                    'key' => 'default',
                    'title' => $defaultTitle,
                    'version' => $defaultVersion,
                    'label' => $defaultTitle,
                    'ui_url' => url($uiBasePath),
                    'json_url' => url(config('geni.docs_json_path', 'docs/api.json')),
                    'mcp_url' => url(config('geni.mcp.route_path', 'docs/api/mcp')),
                    'current' => true,
                ],
            ];
        }

        $list = [];
        foreach ($apis as $key => $apiConfig) {
            $title = $apiConfig['title'] ?? config('geni.title') ?? config('app.name', 'Laravel API').' '.$key;
            $version = $apiConfig['version'] ?? config('geni.version', '1.0.0');
            $uiPath = $apiConfig['docs_ui_path'] ?? ($uiBasePath.'/'.$key);
            $jsonPath = $apiConfig['docs_json_path'] ?? ($uiBasePath.'/'.$key.'.json');
            $mcpPath = $apiConfig['mcp_route_path'] ?? ($uiBasePath.'/'.$key.'/mcp');

            $list[] = [
                'key' => (string) $key,
                'title' => $title,
                'version' => $version,
                'label' => $title,
                'ui_url' => url($uiPath),
                'json_url' => url($jsonPath),
                'mcp_url' => url($mcpPath),
                'current' => ($current === (string) $key),
            ];
        }

        return $list;
    }

    /**
     * Serve the OpenAPI document as JSON, reading from cache when enabled
     * and populated (skips re-running the pipeline entirely on a hit).
     */
    public function document(): Response
    {
        $apiName = $this->resolveApiName();

        $cacheEnabled = config('geni.cache.enabled', false);
        $cacheKey = (config('geni.cache.key', 'geni.openapi')).'.'.$apiName;
        $cacheStore = config('geni.cache.store');

        if ($cacheEnabled) {
            $cachedJson = Cache::store($cacheStore)->get($cacheKey);
            if (is_string($cachedJson)) {
                return response($cachedJson, 200, ['Content-Type' => 'application/json']);
            }
        }

        $document = $this->generateDocument($apiName);
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return response($json, 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Serve the Model Context Protocol (MCP) tool manifest JSON (GET)
     * or execute JSON-RPC 2.0 requests including tools/call (POST).
     */
    public function mcp(): Response
    {
        $apiName = $this->resolveApiName();
        $document = $this->generateDocument($apiName);
        $mcpConfig = config('geni.mcp', []);

        // POST request: Handle JSON-RPC 2.0 (initialize, tools/list, tools/call, ping)
        if (request()->isMethod('POST')) {
            $payload = request()->json()->all();
            $handler = new McpProtocolHandler;
            $response = $handler->handle($payload, $document, [
                'mcp' => $mcpConfig,
            ]);

            if ($response === null) {
                return response()->noContent();
            }

            return response($response, 200, ['Content-Type' => 'application/json']);
        }

        // GET request: Return declarative MCP tools manifest (Phase 1 behavior)
        $generator = new McpManifestGenerator;
        $manifest = $generator->generate($document, $mcpConfig);

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return response($json, 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Serve the docs UI.
     */
    public function ui(): Response
    {
        $apiName = $this->resolveApiName();
        $renderer = $this->resolveRenderer($apiName);
        $document = $this->generateDocument($apiName);

        $rendererKey = config("geni.apis.{$apiName}.renderer") ?? config('geni.renderer', 'blade');
        $config = config('geni.renderers.'.$rendererKey, []);
        $config['current_api'] = $apiName;
        $config['available_apis'] = $this->getAvailableApis($apiName);

        return $renderer->render($document, $config);
    }

    /**
     * Generate the OpenAPI document from live routes, respecting caching and multi-document config.
     */
    public function generateDocument(?string $apiName = 'default'): OpenApiDocument
    {
        $apiName = $apiName ?? 'default';
        $cacheEnabled = config('geni.cache.enabled', false);
        $cacheKey = (config('geni.cache.key', 'geni.openapi')).'.'.$apiName;
        $cacheStore = config('geni.cache.store');

        $routes = RouteDiscoverer::discover($apiName);

        $plainRoutes = array_map(function ($route) {
            return [
                'uri' => $route->uri,
                'methods' => $route->methods,
                'action' => $route->action,
                'middleware' => $route->middleware,
                'name' => $route->name,
            ];
        }, $routes);

        $apiConfig = ($apiName !== 'default') ? (config("geni.apis.{$apiName}") ?: []) : [];

        $migrationPaths = $apiConfig['migration_paths'] ?? config('geni.migration_paths', [database_path('migrations')]);
        $dbSchema = null;

        if (class_exists(SchemaReader::class)) {
            $schemaReader = new SchemaReader;
            $dbSchema = $schemaReader->read($migrationPaths);
        }

        $schemaOverrides = $apiConfig['schema_overrides'] ?? config('geni.schema_overrides', []);

        $assembler = new DocumentAssembler;
        $document = $assembler->assemble(
            routes: $plainRoutes,
            infoOptions: $this->buildInfoOptions($apiName),
            dbSchema: $dbSchema,
            schemaOverrides: $schemaOverrides,
            securityConfig: $this->buildSecurityConfig(),
            apiName: $apiName
        );

        if ($cacheEnabled) {
            $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            Cache::store($cacheStore)->put($cacheKey, $json);
        }

        return $document;
    }

    /**
     * Resolve the configured renderer. `config('geni.renderer')` may be the
     * built-in 'blade' key, or a fully-qualified class name implementing
     * Geni\Laravel\Renderer for a custom docs UI. Falls back to
     * BladeRenderer for an unset/unknown/invalid value.
     */
    public function resolveRenderer(?string $apiName = null): Renderer
    {
        $renderer = ($apiName !== null && $apiName !== 'default')
            ? (config("geni.apis.{$apiName}.renderer") ?? config('geni.renderer', 'blade'))
            : config('geni.renderer', 'blade');

        if ($renderer === 'blade' || $renderer === 'default' || $renderer === null) {
            return new BladeRenderer;
        }

        if (is_string($renderer) && class_exists($renderer) && is_subclass_of($renderer, Renderer::class)) {
            return app($renderer);
        }

        return new BladeRenderer;
    }
}
