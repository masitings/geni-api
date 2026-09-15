<?php

declare(strict_types=1);

namespace Geni\Laravel\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class Hidden {}
