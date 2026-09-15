<?php

declare(strict_types=1);

namespace Geni\Laravel\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Group
{
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?int $weight = null,
    ) {}
}
