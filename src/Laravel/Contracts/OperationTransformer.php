<?php

declare(strict_types=1);

namespace Geni\Laravel\Contracts;

use Geni\Inference\Document\Operation;
use Geni\Laravel\DiscoveredRoute;

/**
 * Changes one documented operation, with access to the route it came from.
 */
interface OperationTransformer
{
    public function handle(Operation $operation, DiscoveredRoute $route, string $apiName): void;
}
