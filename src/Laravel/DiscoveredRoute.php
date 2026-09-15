<?php

declare(strict_types=1);

namespace Geni\Laravel;

final class DiscoveredRoute
{
    public string $uri;

    /** @var list<string> */
    public array $methods;

    /** @var array{type: 'controller', class: string, method: string}|array{type: 'closure', file: string, line: int}|array{type: 'unknown', description: string} */
    public array $action;

    /** @var list<string> */
    public array $middleware;

    public ?string $name;

    /**
     * @param  list<string>  $methods
     * @param  array{type: 'controller', class: string, method: string}|array{type: 'closure', file: string, line: int}|array{type: 'unknown', description: string}  $action
     * @param  list<string>  $middleware
     */
    public function __construct(
        string $uri,
        array $methods,
        array $action,
        array $middleware = [],
        ?string $name = null,
    ) {
        $this->uri = $uri;
        $this->methods = array_values(array_filter($methods, fn ($m) => $m !== 'HEAD'));
        $this->action = $action;
        $this->middleware = $middleware;
        $this->name = $name;
    }
}
