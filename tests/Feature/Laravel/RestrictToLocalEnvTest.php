<?php

declare(strict_types=1);

use Geni\Laravel\Middleware\RestrictToLocalEnv;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('middleware aborts with 404 outside local env', function () {
    app()->detectEnvironment(fn () => 'production');

    $middleware = new RestrictToLocalEnv;

    expect(fn () => $middleware->handle(request(), fn ($req) => $req))
        ->toThrow(NotFoundHttpException::class);
});

test('middleware allows the request through in local env', function () {
    app()->detectEnvironment(fn () => 'local');

    $middleware = new RestrictToLocalEnv;
    $request = request();

    expect($middleware->handle($request, fn ($req) => $req))->toBe($request);
});
