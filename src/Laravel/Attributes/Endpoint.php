<?php

declare(strict_types=1);

namespace Geni\Laravel\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class Endpoint
{
    public function __construct(
        public ?string $operationId = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $method = null,
    ) {}
}
