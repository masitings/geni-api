<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Fixtures\App\Http\Controllers\QueryBuilderTestController;
use Tests\TestCase;

class QueryBuilderInferenceTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        Route::prefix('api')->group(function () {
            Route::get('/query-builder-posts', [QueryBuilderTestController::class, 'index']);
            Route::get('/query-builder-dynamic', [QueryBuilderTestController::class, 'dynamic']);
        });
    }

    public function test_query_builder_extracts_filters_with_database_types(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        expect($spec['paths'])->toHaveKey('/api/query-builder-posts');

        $op = $spec['paths']['/api/query-builder-posts']['get'];
        $paramNames = array_column($op['parameters'], 'name');

        // Check all filter parameters
        expect($paramNames)->toContain('filter[title]');
        expect($paramNames)->toContain('filter[views]');
        expect($paramNames)->toContain('filter[is_published]');
        expect($paramNames)->toContain('filter[published]');
        expect($paramNames)->toContain('filter[trashed]');

        // Check type mappings
        $paramsByName = [];
        foreach ($op['parameters'] as $p) {
            $paramsByName[$p['name']] = $p;
        }

        expect($paramsByName['filter[title]']['schema']['type'])->toBe('string');
        // views column is integer in articles migration
        expect($paramsByName['filter[views]']['schema']['type'])->toBe('integer');
        // published column is boolean in articles migration
        expect($paramsByName['filter[is_published]']['schema']['type'])->toBe('boolean');
        // trashed has enum ['with', 'only']
        expect($paramsByName['filter[trashed]']['schema']['enum'])->toBe(['with', 'only']);
    }

    public function test_query_builder_extracts_sorts_with_default(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        $op = $spec['paths']['/api/query-builder-posts']['get'];

        $sortParam = null;
        foreach ($op['parameters'] as $p) {
            if ($p['name'] === 'sort') {
                $sortParam = $p;
                break;
            }
        }

        expect($sortParam)->not->toBeNull();
        expect($sortParam['schema']['type'])->toBe('string');
        expect($sortParam['schema']['default'])->toBe('-created_at');
        expect($sortParam['schema']['enum'])->toContain('title', '-title', 'created_at', '-created_at');
    }

    public function test_query_builder_extracts_includes(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        $op = $spec['paths']['/api/query-builder-posts']['get'];

        $includeParam = null;
        foreach ($op['parameters'] as $p) {
            if ($p['name'] === 'include') {
                $includeParam = $p;
                break;
            }
        }

        expect($includeParam)->not->toBeNull();
        expect($includeParam['schema']['type'])->toBe('string');
        expect($includeParam['description'])->toContain('user', 'comments');
    }

    public function test_query_builder_extracts_fields(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        $op = $spec['paths']['/api/query-builder-posts']['get'];
        $paramNames = array_column($op['parameters'], 'name');

        expect($paramNames)->toContain('fields[articles]');
        expect($paramNames)->toContain('fields[user]');
    }

    public function test_query_builder_extracts_appends(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        $op = $spec['paths']['/api/query-builder-posts']['get'];

        $appendParam = null;
        foreach ($op['parameters'] as $p) {
            if ($p['name'] === 'append') {
                $appendParam = $p;
                break;
            }
        }

        expect($appendParam)->not->toBeNull();
        expect($appendParam['schema']['type'])->toBe('string');
        expect($appendParam['schema']['enum'])->toContain('full_title');
    }

    public function test_query_builder_emits_diagnostic_on_dynamic_argument(): void
    {
        $response = $this->get('/docs/api.json');
        $response->assertOk();

        $spec = $response->json();
        expect($spec['paths'])->toHaveKey('/api/query-builder-dynamic');

        $op = $spec['paths']['/api/query-builder-dynamic']['get'];
        expect($op)->toHaveKey('x-geni-unresolved');
        $diagnostics = $op['x-geni-unresolved']['x-geni-unresolved'] ?? $op['x-geni-unresolved'];
        $reasons = array_column($diagnostics, 'reason');

        $found = false;
        foreach ($reasons as $reason) {
            if (str_contains($reason, 'Dynamic expression in allowedFilters()')) {
                $found = true;
                break;
            }
        }
        expect($found)->toBeTrue();
    }
}
