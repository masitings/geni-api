<?php

declare(strict_types=1);

namespace Geni\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protect documentation routes with HTTP Basic Auth using
 * config('geni.docs_auth') credentials, not tied to the app's
 * own user table/guard. Triggers the browser's native sign-in prompt.
 */
final class BasicAuthDocs
{
    public function handle(Request $request, Closure $next): mixed
    {
        $username = config('geni.docs_auth.username');
        $password = config('geni.docs_auth.password');

        if ($username === null || $password === null) {
            return $next($request);
        }

        $providedUser = $request->getUser();
        $providedPass = $request->getPassword();

        if (hash_equals($username, (string) $providedUser) && hash_equals($password, (string) $providedPass)) {
            return $next($request);
        }

        return response('Unauthorized', Response::HTTP_UNAUTHORIZED)
            ->header('WWW-Authenticate', 'Basic realm="API Documentation"');
    }
}
