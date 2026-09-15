<?php

declare(strict_types=1);

namespace Geni\Laravel\Attributes;

abstract class BaseParameter
{
    /**
     * @param  array<string, mixed>|null  $examples
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?bool $required = null,
        public ?bool $deprecated = null,
        public mixed $type = null,
        public ?string $format = null,
        public bool $infer = true,
        public mixed $default = null,
        public mixed $example = null,
        public ?array $examples = null,
    ) {}
}
