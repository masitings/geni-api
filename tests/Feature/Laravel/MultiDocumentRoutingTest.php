<?php

declare(strict_types=1);

use Geni\Laravel\Controllers\DocumentationController;
use Geni\Laravel\RouteDiscoverer;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MultiDocumentRoutingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('geni.apis', [
            'v1' => [
                'title' => 'API v1 (Legacy)',
                'version' => '1.0.0',
                'description' => 'Version 1 endpoints.',
                'api_path' => 'api/v1',
            ],
            'v2' => [
                'title' => 'API v2 (Latest)',
                'version' => '2.0.0',
                'description' => 'Version 2 endpoints.',
                'api_path' => 'api/v2',
            ],
        ]);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        Route::prefix('api/v1')->group(function () {
            Route::get('users', function () {
                return response()->json(['version' => 'v1']);
            })->name('v1.users');
        });

        Route::prefix('api/v2')->group(function () {
            Route::get('users', function () {
                return response()->json(['version' => 'v2']);
            })->name('v2.users');
            Route::post('orders', function () {
                return response()->json(['version' => 'v2']);
            })->name('v2.orders');
        });
    }

    public function test_multi_document_routes_are_registered_and_return_200(): void
    {
        // UI routes
        $this->get('/docs/api/v1')->assertOk();
        $this->get('/docs/api/v2')->assertOk();

        // JSON routes
        $this->get('/docs/api/v1.json')->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->get('/docs/api/v2.json')->assertOk()->assertHeader('Content-Type', 'application/json');

        // MCP routes
        $this->get('/docs/api/v1/mcp')->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->get('/docs/api/v2/mcp')->assertOk()->assertHeader('Content-Type', 'application/json');
    }

    public function test_versioned_json_documents_are_scoped_to_their_api_paths(): void
    {
        // v1 document
        $v1Resp = $this->get('/docs/api/v1.json');
        $v1Data = $v1Resp->json();
        expect($v1Data['info']['title'])->toBe('API v1 (Legacy)');
        expect($v1Data['info']['version'])->toBe('1.0.0');
        expect($v1Data['paths'])->toHaveKey('/api/v1/users');
        expect($v1Data['paths'])->not->toHaveKey('/api/v2/users');
        expect($v1Data['paths'])->not->toHaveKey('/api/v2/orders');

        // v2 document
        $v2Resp = $this->get('/docs/api/v2.json');
        $v2Data = $v2Resp->json();
        expect($v2Data['info']['title'])->toBe('API v2 (Latest)');
        expect($v2Data['info']['version'])->toBe('2.0.0');
        expect($v2Data['paths'])->toHaveKey('/api/v2/users');
        expect($v2Data['paths'])->toHaveKey('/api/v2/orders');
        expect($v2Data['paths'])->not->toHaveKey('/api/v1/users');
    }

    public function test_get_available_apis_returns_registry_list(): void
    {
        $controller = app(DocumentationController::class);
        $apis = $controller->getAvailableApis('v1');

        expect($apis)->toHaveCount(2);
        expect($apis[0]['key'])->toBe('v1');
        expect($apis[0]['title'])->toBe('API v1 (Legacy)');
        expect($apis[0]['current'])->toBeTrue();

        expect($apis[1]['key'])->toBe('v2');
        expect($apis[1]['title'])->toBe('API v2 (Latest)');
        expect($apis[1]['current'])->toBeFalse();
    }

    public function test_route_discoverer_filters_by_named_api(): void
    {
        $v1Routes = RouteDiscoverer::discover('v1');
        $v1Uris = array_column($v1Routes, 'uri');
        expect($v1Uris)->toContain('api/v1/users');
        expect($v1Uris)->not->toContain('api/v2/users');

        $v2Routes = RouteDiscoverer::discover('v2');
        $v2Uris = array_column($v2Routes, 'uri');
        expect($v2Uris)->toContain('api/v2/users', 'api/v2/orders');
        expect($v2Uris)->not->toContain('api/v1/users');
    }

    public function test_versioned_mcp_route_filters_tools_by_version(): void
    {
        $v1Mcp = $this->get('/docs/api/v1/mcp')->json();
        $toolNames = array_column($v1Mcp['tools'], 'name');

        expect($toolNames)->toContain('get_api_v1_users');
        expect($toolNames)->not->toContain('get_api_v2_users');
    }
}
