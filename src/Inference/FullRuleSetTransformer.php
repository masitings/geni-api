<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Schema;

/**
 * Extension point for full-ruleset transformation across fields (e.g. `confirmed`).
 *
 * Framework-free.
 */
interface FullRuleSetTransformer
{
    /**
     * Transform or augment the full set of resolved field schemas for a request.
     *
     * @param  array<string, Schema>  $properties  Field name => Schema
     * @param  list<string>  $required  List of required field names
     * @param  array<string, list<string>>  $rawRules  Field name => raw rule strings
     * @param  string  $file  Context file for diagnostics
     * @param  int  $line  Context line for diagnostics
     * @return array{properties: array<string, Schema>, required: list<string>, diagnostics: list<InferenceDiagnostic>}
     */
    public function transform(array $properties, array $required, array $rawRules, string $file, int $line): array;
}
