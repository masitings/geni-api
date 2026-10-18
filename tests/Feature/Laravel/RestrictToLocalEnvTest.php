<?php

declare(strict_types=1);

use Geni\Laravel\Middleware\RestrictToLocalEnv;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

test('a passing viewApiDocs gate opens the docs outside local', function () {
    app()->detectEnvironment(fn () => 'production');
    Gate::define('viewApiDocs', fn ($user = null) => true);

    $request = request();

    expect((new RestrictToLocalEnv)->handle($request, fn ($req) => $req))->toBe($request);
});

test('a denying viewApiDocs gate forbids the docs outside local', function () {
    app()->detectEnvironment(fn () => 'production');
    Gate::define('viewApiDocs', fn ($user = null) => false);

    $status = null;
    try {
        (new RestrictToLocalEnv)->handle(request(), fn ($req) => $req);
    } catch (HttpException $e) {
        $status = $e->getStatusCode();
    }

    expect($status)->toBe(403);
});

test('the gate receives the authenticated user', function () {
    app()->detectEnvironment(fn () => 'production');
    Gate::define('viewApiDocs', fn ($user = null) => $user?->email === 'admin@example.com');

    $request = Request::create('/docs/api');
    $user = new User;
    $user->email = 'admin@example.com';
    $request->setUserResolver(fn () => $user);

    expect((new RestrictToLocalEnv)->handle($request, fn ($req) => $req))->toBe($request);
});
