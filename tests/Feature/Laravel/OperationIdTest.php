<?php

declare(strict_types=1);

use Geni\Laravel\DocumentGenerator;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Extensions\OperationIdController;
use Tests\TestCase;

class OperationIdTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        Route::prefix('api')->group(function () {
            Route::get('things', [OperationIdController::class, 'index'])->name('api.things.index');
            Route::get('things/explicit', [OperationIdController::class, 'explicit'])->name('things.explicit');
            Route::get('things/unnamed', [OperationIdController::class, 'unnamed']);
            Route::match(['PUT', 'PATCH'], 'things/{thing}', [OperationIdController::class, 'update'])->name('things.update');
            Route::get('closure', fn () => []);
        });
    }

    public function test_operation_ids_follow_the_documented_order(): void
    {
        $paths = json_decode((string) json_encode(app(DocumentGenerator::class)->generate()), true)['paths'];

        expect($paths['/api/things']['get']['operationId'])->toBe('things.index')
            ->and($paths['/api/things/explicit']['get']['operationId'])->toBe('fetchEverything')
            ->and($paths['/api/things/unnamed']['get']['operationId'])->toBe('operationId.unnamed')
            ->and($paths['/api/things/{thing}']['put']['operationId'])->toBe('things.update')
            ->and($paths['/api/things/{thing}']['patch']['operationId'])->toBe('things.update_patch')
            ->and($paths['/api/closure']['get'])->not->toHaveKey('operationId')
            ->and($paths['/api/posts']['get']['operationId'])->toBe('posts.index');
    }

    public function test_generated_ids_do_not_rename_mcp_tools(): void
    {
        $this->get('/docs/api/mcp')
            ->assertOk()
            ->assertJsonPath('tools.0.name', 'get_api_posts');
    }
}
