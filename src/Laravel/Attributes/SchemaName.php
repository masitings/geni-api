<?php

declare(strict_types=1);

namespace Geni\Laravel\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class SchemaName
{
    public function __construct(
        public string $name,
        public ?string $input = null,
    ) {}
}
