<?php

namespace Tests\Fixtures;

use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config()->set('app.name', 'Laravel API');
        config()->set('geni.restrict_to_local', false);
        config()->set('geni.api_path', 'api');
        config()->set('geni.migration_paths', [__DIR__.'/App/database/migrations']);
        config()->set('cache.default', 'array');

        $fixtureRoutes = __DIR__.'/App/routes/api.php';
        if (is_file($fixtureRoutes)) {
            require $fixtureRoutes;
        }
    }
}
