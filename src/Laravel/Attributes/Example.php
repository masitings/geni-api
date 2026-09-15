<?php

declare(strict_types=1);

namespace Geni\Laravel\Attributes;

final class Example
{
    public function __construct(
        public mixed $value = null,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $externalValue = null,
    ) {}
}
