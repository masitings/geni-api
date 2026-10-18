<?php

declare(strict_types=1);

namespace Geni\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Docs routes are open in the `local` environment. Elsewhere they are
 * allowed when the application defines a `viewApiDocs` gate that passes
 * (the same convention as Scramble), forbidden when that gate denies, and
 * hidden (404) when no gate is defined.
 */
final class RestrictToLocalEnv
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (app()->environment('local')) {
            return $next($request);
        }

        if (! Gate::has('viewApiDocs')) {
            abort(404, 'Documentation not available');
        }

        if (Gate::forUser($request->user())->allows('viewApiDocs')) {
            return $next($request);
        }

        abort(403);
    }
}
