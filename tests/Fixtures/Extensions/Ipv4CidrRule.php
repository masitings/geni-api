<?php

declare(strict_types=1);

namespace Tests\Fixtures\Extensions;

use Geni\Inference\Document\Schema;
use Geni\Inference\RuleTransformer;

class Ipv4CidrRule implements RuleTransformer
{
    public function matches(string $rule, string $field): bool
    {
        return $rule === 'ipv4_cidr';
    }

    public function transform(string $rule, string $field, Schema $schema, string $file, int $line): array
    {
        $schema->type = 'string';
        $schema->format = 'ipv4-cidr';

        return ['schema' => $schema];
    }
}
