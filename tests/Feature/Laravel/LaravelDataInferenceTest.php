<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Fixtures\App\Http\Controllers\DataCollectionController;
use Tests\Fixtures\App\Http\Controllers\DataPostsController;
use Tests\Fixtures\App\Http\Controllers\DataUsersController;
use Tests\TestCase;

class LaravelDataInferenceTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        Route::prefix('api')->group(function () {
            Route::post('/data/users', [DataUsersController::class, 'store']);
            Route::get('/data/users/show', [DataUsersController::class, 'show']);
            Route::get('/data/users/list', [DataUsersController::class, 'list']);
            Route::post('/data/posts', [DataPostsController::class, 'store']);
            Route::get('/data/posts/show', [DataPostsController::class, 'show']);
            Route::get('/data/users/collection', [DataCollectionController::class, 'list']);
        });
    }

    // AC-001: Simple Data DTO request injection generates request body with schema
    public function test_data_dto_request_injection_generates_request_body(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        expect($spec['paths'])->toHaveKey('/api/data/users');

        $postOp = $spec['paths']['/api/data/users']['post'];
        expect($postOp)->toHaveKey('requestBody');

        $content = $postOp['requestBody']['content']['application/json']['schema'];
        expect($content['type'])->toBe('object');
        expect($content['properties'])->toHaveKey('name');
        expect($content['properties'])->toHaveKey('email');
        expect($content['properties'])->toHaveKey('address');
        expect($content['required'])->toContain('name', 'email');
        expect($content['required'])->not->toContain('address');
    }

    // AC-002: #[Required] and non-nullable types are in the required array
    public function test_required_and_nullable_fields_in_required_array(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        $postOp = $spec['paths']['/api/data/users']['post'];
        $schema = $postOp['requestBody']['content']['application/json']['schema'];

        $required = $schema['required'] ?? [];
        expect($required)->toContain('name', 'email');
        expect($required)->not->toContain('address', 'slug');
    }

    // AC-003: Optional and nullable properties are not required, nullable types get "null"
    public function test_optional_and_nullable_properties(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        $postOp = $spec['paths']['/api/data/users']['post'];
        $schema = $postOp['requestBody']['content']['application/json']['schema'];

        $required = $schema['required'] ?? [];
        expect($required)->not->toContain('address');

        $addressProp = $schema['properties']['address'] ?? null;
        expect($addressProp)->not->toBeNull();
        if (isset($addressProp['type']) && is_array($addressProp['type'])) {
            expect($addressProp['type'])->toContain('null');
        }
    }

    // AC-004: Validation attributes map to JSON Schema constraints
    public function test_validation_attribute_mapping(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        $postOp = $spec['paths']['/api/data/posts']['post'];
        $schema = $postOp['requestBody']['content']['application/json']['schema'];

        $titleProp = $schema['properties']['title'] ?? null;
        expect($titleProp)->not->toBeNull();
        expect($titleProp['minLength'] ?? $titleProp['minimum'] ?? null)->toBe(5);
        expect($titleProp['maxLength'] ?? $titleProp['maximum'] ?? null)->toBe(100);

        $slugProp = $schema['properties']['slug'] ?? null;
        expect($slugProp)->not->toBeNull();
    }

    // AC-005: Nested Data classes resolve via $ref
    public function test_nested_data_class_resolution(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        $postOp = $spec['paths']['/api/data/users']['post'];
        $schema = $postOp['requestBody']['content']['application/json']['schema'];

        $addressProp = $schema['properties']['address'] ?? null;
        expect($addressProp)->not->toBeNull();
        // Should reference AddressData component or contain inline object
        $hasRef = isset($addressProp['$ref']) || isset($addressProp['properties']);
        expect($hasRef)->toBeTrue();
    }

    // AC-006: Single Data response transformation
    public function test_single_data_response_transformation(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        expect($spec['paths'])->toHaveKey('/api/data/users/show');

        $getOp = $spec['paths']['/api/data/users/show']['get'];
        expect($getOp['responses'])->toHaveKey('200');

        $respSchema = $getOp['responses']['200']['content']['application/json']['schema'] ?? null;
        expect($respSchema)->not->toBeNull();
        if (isset($respSchema['$ref'])) {
            // Schema is a reference to a component, valid
            expect($respSchema['$ref'])->toContain('SimpleUserData');
        } else {
            expect($respSchema['type'])->toBe('object');
            expect($respSchema['properties'])->toHaveKey('name');
            expect($respSchema['properties'])->toHaveKey('email');
        }
    }

    // AC-007: Data::collect() produces array response
    public function test_collect_data_array_response(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        $listOp = $spec['paths']['/api/data/users/list']['get'];

        $respSchema = $listOp['responses']['200']['content']['application/json']['schema'] ?? null;
        expect($respSchema)->not->toBeNull();
        expect($respSchema['type'])->toBe('array');
        if (isset($respSchema['items']['properties'])) {
            expect($respSchema['items']['properties'])->toHaveKey('name');
        }
    }

    // AC-008: Opt-in behavior - graceful handling when Data is type-hinted
    public function test_opt_in_data_support_without_errors(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();
        $response->assertHeader('content-type', 'application/json');

        $spec = $response->json();
        expect($spec)->toHaveKey('openapi');
        expect($spec['openapi'])->toBe('3.1.0');
    }
}
