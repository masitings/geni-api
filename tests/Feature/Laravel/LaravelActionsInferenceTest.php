<?php

declare(strict_types=1);

use Geni\Laravel\RouteDiscoverer;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\App\Actions\CreateUserAction;
use Tests\Fixtures\App\Actions\DualMethodAction;
use Tests\Fixtures\App\Actions\SimpleInvokableAction;
use Tests\TestCase;

class LaravelActionsInferenceTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        Route::prefix('api')->group(function () {
            Route::post('/users', CreateUserAction::class);
            Route::get('/simple', SimpleInvokableAction::class);
            Route::get('/dual', DualMethodAction::class);
        });
    }

    public function test_single_action_route_registration_resolves_method(): void
    {
        $routes = RouteDiscoverer::discover();
        $uris = array_column($routes, 'uri');

        expect($uris)->toContain('api/users');
        expect($uris)->toContain('api/simple');
        expect($uris)->toContain('api/dual');

        foreach ($routes as $route) {
            if ($route->uri === 'api/users') {
                expect($route->action['type'])->toBe('controller');
                expect($route->action['class'])->toBe(CreateUserAction::class);
                expect($route->action['method'])->toBe('asController');
            }
            if ($route->uri === 'api/simple') {
                expect($route->action['type'])->toBe('controller');
                expect($route->action['class'])->toBe(SimpleInvokableAction::class);
                expect($route->action['method'])->toBe('handle');
            }
        }
    }

    public function test_method_precedence_prefers_as_controller_over_handle(): void
    {
        $routes = RouteDiscoverer::discover();
        $dualRoute = null;
        foreach ($routes as $route) {
            if ($route->uri === 'api/dual') {
                $dualRoute = $route;
                break;
            }
        }

        expect($dualRoute)->not->toBeNull();
        expect($dualRoute->action['method'])->toBe('asController');
    }

    public function test_validation_rules_extracted_from_rules_method_into_request_body(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        expect($spec['paths'])->toHaveKey('/api/users');

        $postOp = $spec['paths']['/api/users']['post'];
        expect($postOp)->toHaveKey('requestBody');

        $content = $postOp['requestBody']['content']['application/json']['schema'];
        expect($content['type'])->toBe('object');
        expect($content['properties'])->toHaveKey('name');
        expect($content['properties'])->toHaveKey('email');
        expect($content['required'])->toContain('name', 'email');
    }

    public function test_automatic_403_response_generated_when_authorize_method_present(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        $postOp = $spec['paths']['/api/users']['post'];

        expect($postOp['responses'])->toHaveKey('403');
        expect($postOp['responses']['403']['description'])->toBe('This action is unauthorized.');
    }

    public function test_generated_operation_summary_formatting(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        expect($spec['paths']['/api/users']['post']['summary'])->toBe('Create user');
        expect($spec['paths']['/api/simple']['get']['summary'])->toBe('Simple invokable');
    }

    public function test_graceful_noop_behavior_for_standard_routes(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        expect($spec['paths'])->toHaveKey('/api/posts');
        expect($spec['paths']['/api/posts']['get']['responses'])->toHaveKey('200');
    }
}
