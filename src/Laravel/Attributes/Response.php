<?php

declare(strict_types=1);

namespace Geni\Laravel\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Response
{
    /**
     * @param  array<string, mixed>|null  $examples
     */
    public function __construct(
        public int $status = 200,
        public ?string $description = null,
        public ?string $mediaType = null,
        public mixed $type = null,
        public ?string $format = null,
        public ?array $examples = null,
    ) {}
}
