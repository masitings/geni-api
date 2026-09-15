<?php

declare(strict_types=1);

namespace Geni\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Restrict documentation routes to local environment by default.
 * Override via config('geni.middleware') to allow in production.
 */
final class RestrictToLocalEnv
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! app()->environment('local')) {
            abort(404, 'Documentation not available');
        }

        return $next($request);
    }
}
