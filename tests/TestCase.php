<?php

declare(strict_types=1);

namespace Tests;

use Geni\Laravel\GeniServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            GeniServiceProvider::class,
        ];
    }

    protected function defineRoutes($router): void
    {
        $fixtureRoutes = __DIR__.'/Fixtures/App/routes/api.php';
        if (is_file($fixtureRoutes)) {
            require $fixtureRoutes;
        }
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'Laravel API');
        // Testbench forces the runtime environment to 'testing' regardless of app.env,
        // so RestrictToLocalEnv would 404 every docs route unless explicitly disabled here.
        $app['config']->set('geni.restrict_to_local', false);
        $app['config']->set('geni.api_path', 'api');
        $app['config']->set('geni.migration_paths', [__DIR__.'/Fixtures/App/database/migrations']);
        $app['config']->set('cache.default', 'array');
    }
}
