<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiOptionCommandsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('geni.apis', [
            'v2' => [
                'title' => 'API v2',
                'version' => '2.0.0',
                'api_path' => 'api/v2',
            ],
        ]);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        Route::get('api/v2/widgets', fn () => response()->json([]));
    }

    public function test_cache_command_stores_the_requested_api_document(): void
    {
        $this->artisan('geni:cache', ['--api' => 'v2'])->assertSuccessful();

        $cached = json_decode((string) Cache::get('geni.openapi.v2'), true);

        expect($cached['info']['title'])->toBe('API v2');
        expect(array_keys($cached['paths']))->toBe(['/api/v2/widgets']);
    }

    public function test_export_command_writes_the_requested_api_document(): void
    {
        $path = sys_get_temp_dir().'/geni-export-v2-'.uniqid().'.json';

        try {
            $this->artisan('geni:export', ['--api' => 'v2', '--path' => $path])->assertSuccessful();

            $exported = json_decode((string) file_get_contents($path), true);

            expect($exported['info']['version'])->toBe('2.0.0');
            expect(array_keys($exported['paths']))->toBe(['/api/v2/widgets']);
        } finally {
            @unlink($path);
        }
    }

    public function test_check_command_compares_against_the_requested_api_document(): void
    {
        $path = sys_get_temp_dir().'/geni-check-v2-'.uniqid().'.json';

        try {
            $this->artisan('geni:export', ['--api' => 'v2', '--path' => $path])->assertSuccessful();
            $this->artisan('geni:check', ['--api' => 'v2', '--path' => $path])->assertSuccessful();
            $this->artisan('geni:check', ['--path' => $path])->assertFailed();
        } finally {
            @unlink($path);
        }
    }

    public function test_commands_reject_unknown_api_names(): void
    {
        foreach (['geni:cache', 'geni:clear', 'geni:export', 'geni:analyze', 'geni:mcp'] as $command) {
            $this->artisan($command, ['--api' => 'nope'])
                ->expectsOutputToContain('Unknown API "nope"')
                ->assertFailed();
        }
    }
}
