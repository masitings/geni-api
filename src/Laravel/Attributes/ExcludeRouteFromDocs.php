<?php

declare(strict_types=1);

namespace Geni\Laravel\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class ExcludeRouteFromDocs {}
