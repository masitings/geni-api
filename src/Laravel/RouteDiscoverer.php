<?php

declare(strict_types=1);

namespace Geni\Laravel;

use Geni\Inference\LaravelActionDetector;
use Illuminate\Support\Facades\Route;

/**
 * Discovers routes to document. Avoids leaking Illuminate routing types into Geni\Inference.
 *
 * Only one place (this class) may call Route::getRoutes() directly, per FR-004's bounded
 * exception in architecture.md.
 */
final class RouteDiscoverer
{
    /**
     * @return array<int, DiscoveredRoute>
     */
    public static function discover(?string $apiName = null): array
    {
        $routes = self::getRoutesFromConfigOrFacades($apiName);

        $result = [];
        foreach ($routes as $route) {
            $discovered = self::routeToDiscovered($route);
            if ($discovered !== null) {
                $result[] = $discovered;
            }
        }

        return $result;
    }

    /**
     * Get the list of routes to consider for documentation.
     *
     * If config('geni.routes') is a closure, call it (FR-004 bounded exception).
     * Otherwise, use Route::getRoutes() and filter by prefix (FR-003).
     */
    private static function getRoutesFromConfigOrFacades(?string $apiName = null): array
    {
        $apiConfig = ($apiName !== null && $apiName !== 'default')
            ? (config("geni.apis.{$apiName}") ?: [])
            : [];

        $routesConfig = $apiConfig['routes'] ?? config('geni.routes');

        if ($routesConfig instanceof \Closure) {
            return iterator_to_array($routesConfig());
        }

        $prefix = $apiConfig['api_path'] ?? config('geni.api_path', 'api');

        $allRoutes = Route::getRoutes();

        $filtered = [];
        foreach ($allRoutes as $route) {
            if (str_starts_with($route->uri(), $prefix)) {
                $filtered[] = $route;
            }
        }

        return $filtered;
    }

    /**
     * Convert an Illuminate route to a plain DiscoveredRoute DTO.
     *
     * This method contains the ONLY place where Illuminate routing objects are read
     * (via FR-004's bounded exception). The resulting DTO contains only plain data
     * (strings, arrays) so that Geni\Inference remains framework-free.
     *
     * @return DiscoveredRoute|null null if the route has no methods (e.g. fallback routes)
     */
    private static function routeToDiscovered(\Illuminate\Routing\Route $route): ?DiscoveredRoute
    {
        $methods = $route->methods();

        // Skip routes with no HTTP verbs (e.g. fallback routes)
        if ($methods === []) {
            return null;
        }

        $action = $route->getAction();

        // Resolve action to plain DTO: either controller@method or closure
        $resolvedAction = self::resolveAction($action);

        return new DiscoveredRoute(
            uri: $route->uri(),
            methods: $methods,
            action: $resolvedAction,
            middleware: $route->middleware(),
            name: $route->getName() ?: null,
        );
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{type: 'controller', class: string, method: string}|array{type: 'closure', file: string, line: int}|array{type: 'unknown', description: string}
     */
    private static function resolveAction(array $action): array
    {
        $uses = $action['uses'] ?? $action['controller'] ?? null;

        if (is_string($uses) && str_contains($uses, '@')) {
            [$class, $method] = explode('@', $uses, 2);

            return ['type' => 'controller', 'class' => $class, 'method' => $method];
        }

        if (is_string($uses) && str_contains($uses, '::')) {
            // [Controller::class], single-action controller
            [$class] = explode('::class', $uses, 2);

            $detector = new LaravelActionDetector;
            $actionInfo = $detector->detectForClass($class);
            $method = ($actionInfo !== null && $actionInfo['isAction'] && $actionInfo['method'] !== null)
                ? $actionInfo['method']
                : '__invoke';

            return ['type' => 'controller', 'class' => $class, 'method' => $method];
        }

        if (is_string($uses) && ! empty($uses)) {
            $detector = new LaravelActionDetector;
            $actionInfo = $detector->detectForClass($uses);
            if ($actionInfo !== null && $actionInfo['isAction']) {
                $method = $actionInfo['method'] ?? 'asController';

                return ['type' => 'controller', 'class' => $uses, 'method' => $method];
            }

            if (class_exists($uses) || method_exists($uses, '__invoke')) {
                return ['type' => 'controller', 'class' => $uses, 'method' => '__invoke'];
            }
        }

        if (isset($action['file'])) {
            return [
                'type' => 'closure',
                'file' => $action['file'],
                'line' => $action['line'] ?? 0,
            ];
        }

        return ['type' => 'unknown', 'description' => 'Could not resolve route action'];
    }
}
