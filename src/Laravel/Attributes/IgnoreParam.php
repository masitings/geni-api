<?php

declare(strict_types=1);

namespace Geni\Laravel\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class IgnoreParam
{
    public function __construct(
        public string $name,
        public ?string $in = null,
    ) {}
}
