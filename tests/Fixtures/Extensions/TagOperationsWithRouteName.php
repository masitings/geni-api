<?php

declare(strict_types=1);

namespace Tests\Fixtures\Extensions;

use Geni\Inference\Document\Operation;
use Geni\Laravel\Contracts\OperationTransformer;
use Geni\Laravel\DiscoveredRoute;

class TagOperationsWithRouteName implements OperationTransformer
{
    public function handle(Operation $operation, DiscoveredRoute $route, string $apiName): void
    {
        $operation->extensions['x-route-name'] = $route->name;
    }
}
