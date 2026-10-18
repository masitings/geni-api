<?php

declare(strict_types=1);

namespace Geni\Laravel\Middleware;

use Closure;
use Geni\Laravel\DocsCredentials;
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
        if (! DocsCredentials::configured()) {
            return $next($request);
        }

        if (DocsCredentials::matches($request->getUser(), $request->getPassword())) {
            return $next($request);
        }

        return response('Unauthorized', Response::HTTP_UNAUTHORIZED)
            ->header('WWW-Authenticate', 'Basic realm="API Documentation"');
    }
}
