<?php

declare(strict_types=1);

namespace Geni\Laravel\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class HeaderParameter extends BaseParameter {}
