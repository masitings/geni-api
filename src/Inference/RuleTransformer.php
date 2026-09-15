<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Schema;

/**
 * Extension point for custom single-rule transformation to JSON Schema.
 *
 * Framework-free.
 */
interface RuleTransformer
{
    /**
     * Determine if this transformer can handle the given rule.
     */
    public function matches(string $rule, string $field): bool;

    /**
     * Transform the rule into updates on the field's schema.
     *
     * @param  string  $rule  The rule string (e.g. 'custom_rule:value')
     * @param  string  $field  The field name
     * @param  Schema  $schema  The current schema for this field
     * @param  string  $file  Context file for diagnostics
     * @param  int  $line  Context line for diagnostics
     * @return array{schema: Schema, required?: bool, diagnostics?: list<InferenceDiagnostic>}
     */
    public function transform(string $rule, string $field, Schema $schema, string $file, int $line): array;
}
