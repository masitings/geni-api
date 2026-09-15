<?php

declare(strict_types=1);

namespace Geni\Laravel;

use Geni\Laravel\Commands\AnalyzeCommand;
use Geni\Laravel\Commands\CacheCommand;
use Geni\Laravel\Commands\CheckCommand;
use Geni\Laravel\Commands\ClearCommand;
use Geni\Laravel\Commands\ExportCommand;
use Geni\Laravel\Commands\McpCommand;
use Geni\Laravel\Commands\McpServeCommand;
use Geni\Laravel\Middleware\BasicAuthDocs;
use Geni\Laravel\Middleware\DocsFormAuth;
use Geni\Laravel\Middleware\RestrictToLocalEnv;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GeniServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('geni')
            ->setBasePath(dirname(__DIR__))
            ->hasConfigFile()
            ->hasCommands([
                ExportCommand::class,
                AnalyzeCommand::class,
                CheckCommand::class,
                CacheCommand::class,
                ClearCommand::class,
                McpCommand::class,
                McpServeCommand::class,
            ])
            ->hasViews();
    }

    public function packageBooted(): void
    {
        $this->loadViewsFrom(dirname(__DIR__, 2).'/resources/views', 'geni');
        $this->registerDocumentationRoutes();
    }

    /**
     * Register the JSON document + docs UI routes, gated to local env by default.
     */
    protected function registerDocumentationRoutes(): void
    {
        $baseMiddleware = config('geni.middleware', []);

        if (config('geni.restrict_to_local', true) !== false) {
            $baseMiddleware[] = RestrictToLocalEnv::class;
        }

        $authMode = config('geni.docs_auth.mode', 'basic');
        $hasCredentials = config('geni.docs_auth.username') !== null && config('geni.docs_auth.password') !== null;

        $uiPath = config('geni.docs_ui_path', 'docs/api');
        $jsonPath = config('geni.docs_json_path', 'docs/api.json');
        $mcpPath = config('geni.mcp.route_path', 'docs/api/mcp');

        $apis = config('geni.apis', []);

        if ($hasCredentials && $authMode === 'form') {
            // Include 'web' middleware group so sessions and CSRF cookies are available
            $webMiddleware = array_merge(['web'], $baseMiddleware);

            // 1. Auth routes (login form, submit, logout)
            Route::middleware($webMiddleware)->group(function (Router $router) use ($uiPath) {
                $router->get($uiPath.'/login', '\Geni\Laravel\Controllers\DocsAuthController@loginForm')->name('geni.docs.login');
                $router->post($uiPath.'/login', '\Geni\Laravel\Controllers\DocsAuthController@login');
                $router->post($uiPath.'/logout', '\Geni\Laravel\Controllers\DocsAuthController@logout')->name('geni.docs.logout');
            });

            // 2. Protected default docs UI, JSON, and MCP routes
            $docsMiddleware = array_merge($webMiddleware, [DocsFormAuth::class]);

            Route::middleware($docsMiddleware)->group(function (Router $router) use ($uiPath, $jsonPath, $mcpPath) {
                $router->get($jsonPath, '\Geni\Laravel\Controllers\DocumentationController@document');
                $router->get($uiPath, '\Geni\Laravel\Controllers\DocumentationController@ui');
                if ($mcpPath !== null && config('geni.mcp.enabled', true)) {
                    $router->match(['GET', 'POST'], $mcpPath, '\Geni\Laravel\Controllers\DocumentationController@mcp');
                }
            });

            // 3. Register additional named API routes if configured (FR-004)
            if (! empty($apis)) {
                Route::middleware($docsMiddleware)->group(function (Router $router) use ($apis, $uiPath) {
                    foreach ($apis as $name => $apiConfig) {
                        $apiUiPath = $apiConfig['docs_ui_path'] ?? ($uiPath.'/'.$name);
                        $apiJsonPath = $apiConfig['docs_json_path'] ?? ($uiPath.'/'.$name.'.json');
                        $apiMcpPath = $apiConfig['mcp_route_path'] ?? ($uiPath.'/'.$name.'/mcp');

                        $router->get($apiJsonPath, ['uses' => '\Geni\Laravel\Controllers\DocumentationController@document', 'api' => $name]);
                        $router->get($apiUiPath, ['uses' => '\Geni\Laravel\Controllers\DocumentationController@ui', 'api' => $name]);
                        if (config('geni.mcp.enabled', true)) {
                            $router->match(['GET', 'POST'], $apiMcpPath, ['uses' => '\Geni\Laravel\Controllers\DocumentationController@mcp', 'api' => $name]);
                        }
                    }
                });
            }

            return;
        }

        // Basic auth or no auth
        $middleware = $baseMiddleware;
        if ($hasCredentials && $authMode !== 'form') {
            $middleware[] = BasicAuthDocs::class;
        }

        Route::middleware($middleware)
            ->group(function (Router $router) use ($uiPath, $jsonPath, $mcpPath) {
                $router->get($jsonPath, '\Geni\Laravel\Controllers\DocumentationController@document');
                $router->get($uiPath, '\Geni\Laravel\Controllers\DocumentationController@ui');
                if ($mcpPath !== null && config('geni.mcp.enabled', true)) {
                    $router->match(['GET', 'POST'], $mcpPath, '\Geni\Laravel\Controllers\DocumentationController@mcp');
                }
            });

        // Register additional named API routes for basic/public auth (FR-004)
        if (! empty($apis)) {
            Route::middleware($middleware)->group(function (Router $router) use ($apis, $uiPath) {
                foreach ($apis as $name => $apiConfig) {
                    $apiUiPath = $apiConfig['docs_ui_path'] ?? ($uiPath.'/'.$name);
                    $apiJsonPath = $apiConfig['docs_json_path'] ?? ($uiPath.'/'.$name.'.json');
                    $apiMcpPath = $apiConfig['mcp_route_path'] ?? ($uiPath.'/'.$name.'/mcp');

                    $router->get($apiJsonPath, ['uses' => '\Geni\Laravel\Controllers\DocumentationController@document', 'api' => $name]);
                    $router->get($apiUiPath, ['uses' => '\Geni\Laravel\Controllers\DocumentationController@ui', 'api' => $name]);
                    if (config('geni.mcp.enabled', true)) {
                        $router->match(['GET', 'POST'], $apiMcpPath, ['uses' => '\Geni\Laravel\Controllers\DocumentationController@mcp', 'api' => $name]);
                    }
                }
            });
        }
    }
}
