<?php

declare(strict_types=1);

namespace Geni\Laravel\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Header
{
    public function __construct(
        public string $name,
        public ?string $description = null,
        public mixed $type = null,
    ) {}
}
