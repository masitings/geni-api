<?php

declare(strict_types=1);

namespace Geni\Laravel\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class Api
{
    /**
     * @param  string|list<string>  $only
     */
    public function __construct(
        public string|array $only = [],
    ) {}
}
