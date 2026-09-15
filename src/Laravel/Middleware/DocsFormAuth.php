<?php

declare(strict_types=1);

namespace Geni\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Protect documentation routes with an isolated session-based form auth gate.
 *
 * Does NOT touch or interact with the host application's user auth or guards.
 */
final class DocsFormAuth
{
    public function handle(Request $request, Closure $next): mixed
    {
        $username = config('geni.docs_auth.username');
        $password = config('geni.docs_auth.password');

        if ($username === null || $password === null) {
            return $next($request);
        }

        if ($request->session()->get('geni_docs_authenticated') === true) {
            return $next($request);
        }

        $jsonPath = config('geni.docs_json_path', 'docs/api.json');
        $uiPath = config('geni.docs_ui_path', 'docs/api');

        $jsonPaths = [$jsonPath];

        foreach ((array) config('geni.apis', []) as $name => $apiConfig) {
            if (is_array($apiConfig)) {
                $jsonPaths[] = $apiConfig['docs_json_path'] ?? ($uiPath.'/'.$name.'.json');
            }
        }

        foreach ($jsonPaths as $path) {
            if ($request->expectsJson() || $request->is($path) || $request->is('*/'.$path)) {
                return response()->json(['message' => 'Unauthorized.'], 401);
            }
        }

        return redirect()->guest(url($uiPath.'/login'));
    }
}
