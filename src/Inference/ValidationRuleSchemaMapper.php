<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Schema;
use Geni\Inference\Transformers\ConfirmedRuleTransformer;
use Geni\SchemaReader\ColumnType;
use Geni\SchemaReader\DatabaseSchema;

/**
 * Maps Laravel validation rules to OpenAPI 3.1.0 JSON Schema.
 *
 * Framework-free.
 */
final class ValidationRuleSchemaMapper
{
    private BackedEnumParser $enumParser;

    /** @var list<RuleTransformer> */
    private array $customRuleTransformers;

    /** @var list<FullRuleSetTransformer> */
    private array $fullRuleSetTransformers;

    /**
     * @param  list<RuleTransformer>  $customRuleTransformers
     * @param  list<FullRuleSetTransformer>  $fullRuleSetTransformers
     */
    public function __construct(
        ?BackedEnumParser $enumParser = null,
        array $customRuleTransformers = [],
        array $fullRuleSetTransformers = [],
    ) {
        $this->enumParser = $enumParser ?? new BackedEnumParser;
        $this->customRuleTransformers = $customRuleTransformers;
        $this->fullRuleSetTransformers = $fullRuleSetTransformers !== []
            ? $fullRuleSetTransformers
            : [new ConfirmedRuleTransformer];
    }

    /**
     * @param  array<string, string|list<string>>  $fields  Field name => rules
     * @param  string  $file  Context filename for diagnostics
     * @param  int  $line  Context line number for diagnostics
     * @param  DatabaseSchema|null  $dbSchema  For resolving `exists` rules
     * @return array{schema: Schema, required: list<string>, hasFile: bool, diagnostics: list<InferenceDiagnostic>}
     */
    public function map(
        array $fields,
        string $file = 'unknown',
        int $line = 0,
        ?DatabaseSchema $dbSchema = null,
    ): array {
        $properties = [];
        $requiredFields = [];
        $diagnostics = [];
        $hasFile = false;
        $parsedRawRules = [];

        foreach ($fields as $fieldName => $rules) {
            $parsed = $this->parseRules($rules);
            $parsedRawRules[$fieldName] = $parsed;

            $mapped = $this->mapFieldRules($fieldName, $parsed, $file, $line, $dbSchema);

            $properties[$fieldName] = $mapped['schema'];
            if ($mapped['required']) {
                $requiredFields[] = $fieldName;
            }
            if ($mapped['hasFile']) {
                $hasFile = true;
            }

            foreach ($mapped['diagnostics'] as $diagnostic) {
                $diagnostics[] = $diagnostic;
            }
        }

        // Apply full-ruleset transformers (e.g. ConfirmedRuleTransformer)
        foreach ($this->fullRuleSetTransformers as $transformer) {
            $transResult = $transformer->transform($properties, $requiredFields, $parsedRawRules, $file, $line);
            $properties = $transResult['properties'];
            $requiredFields = $transResult['required'];
            foreach ($transResult['diagnostics'] as $d) {
                $diagnostics[] = $d;
            }
        }

        $objectSchema = new Schema;
        $objectSchema->type = 'object';
        $objectSchema->properties = $properties;

        if ($requiredFields !== []) {
            $objectSchema->extensions['required'] = $requiredFields;
        }

        return [
            'schema' => $objectSchema,
            'required' => $requiredFields,
            'hasFile' => $hasFile,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param  string|list<string>  $rules
     * @return list<string>
     */
    private function parseRules($rules): array
    {
        if (is_array($rules)) {
            $list = [];
            foreach ($rules as $r) {
                if (is_string($r)) {
                    foreach (explode('|', $r) as $part) {
                        $trimmed = trim($part);
                        if ($trimmed !== '') {
                            $list[] = $trimmed;
                        }
                    }
                }
            }

            return $list;
        }

        $list = [];
        foreach (explode('|', (string) $rules) as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $list[] = $trimmed;
            }
        }

        return $list;
    }

    /**
     * @param  list<string>  $rules
     * @return array{schema: Schema, required: bool, hasFile: bool, diagnostics: list<InferenceDiagnostic>}
     */
    private function mapFieldRules(
        string $fieldName,
        array $rules,
        string $file,
        int $line,
        ?DatabaseSchema $dbSchema
    ): array {
        $schema = new Schema;
        $required = false;
        $isNullable = false;
        $hasFile = false;
        $diagnostics = [];

        foreach ($rules as $rule) {
            // Check custom transformers first
            $matchedCustom = false;
            foreach ($this->customRuleTransformers as $customTransformer) {
                if ($customTransformer->matches($rule, $fieldName)) {
                    $customResult = $customTransformer->transform($rule, $fieldName, $schema, $file, $line);
                    $schema = $customResult['schema'];
                    if (isset($customResult['required']) && $customResult['required']) {
                        $required = true;
                    }
                    if (isset($customResult['diagnostics'])) {
                        foreach ($customResult['diagnostics'] as $d) {
                            $diagnostics[] = $d;
                        }
                    }
                    $matchedCustom = true;
                    break;
                }
            }
            if ($matchedCustom) {
                continue;
            }

            $ruleName = $rule;
            $ruleParam = null;

            if (str_contains($rule, ':')) {
                [$ruleName, $ruleParam] = explode(':', $rule, 2);
            }

            $ruleLower = strtolower($ruleName);

            if ($ruleLower === 'required') {
                $required = true;

                continue;
            }

            if ($ruleLower === 'nullable') {
                $isNullable = true;

                continue;
            }

            if ($ruleLower === 'string') {
                $schema->type = 'string';

                continue;
            }

            if ($ruleLower === 'int' || $ruleLower === 'integer') {
                $schema->type = 'integer';

                continue;
            }

            if ($ruleLower === 'numeric') {
                $schema->type = 'number';

                continue;
            }

            if ($ruleLower === 'bool' || $ruleLower === 'boolean') {
                $schema->type = 'boolean';

                continue;
            }

            if ($ruleLower === 'array') {
                $schema->type = 'array';

                continue;
            }

            if ($ruleLower === 'email') {
                $schema->type = 'string';
                $schema->format = 'email';

                continue;
            }

            if ($ruleLower === 'uuid') {
                $schema->type = 'string';
                $schema->format = 'uuid';

                continue;
            }

            if ($ruleLower === 'ulid') {
                $schema->type = 'string';
                $schema->format = 'ulid';

                continue;
            }

            if ($ruleLower === 'date') {
                $schema->type = 'string';
                $schema->format = 'date';

                continue;
            }

            if ($ruleLower === 'date_format') {
                $schema->type = 'string';
                $schema->format = 'date-time';

                continue;
            }

            if ($ruleLower === 'file' || $ruleLower === 'image') {
                $hasFile = true;
                $schema->type = 'string';
                $schema->format = 'binary';

                continue;
            }

            if ($ruleLower === 'regex' && $ruleParam !== null) {
                $schema->type = 'string';
                $pattern = trim($ruleParam);
                if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
                    $pattern = substr($pattern, 1, -1);
                }
                $schema->extensions['pattern'] = $pattern;

                continue;
            }

            if ($ruleLower === 'min' && $ruleParam !== null && is_numeric($ruleParam)) {
                $val = (int) $ruleParam;
                if ($schema->type === 'string') {
                    $schema->minLength = $val;
                } elseif ($schema->type === 'integer' || $schema->type === 'number') {
                    $schema->minimum = $val;
                } elseif ($schema->type === 'array') {
                    $schema->extensions['minItems'] = $val;
                } else {
                    $schema->minLength = $val;
                }

                continue;
            }

            if ($ruleLower === 'max' && $ruleParam !== null && is_numeric($ruleParam)) {
                $val = (int) $ruleParam;
                if ($schema->type === 'string') {
                    $schema->maxLength = $val;
                } elseif ($schema->type === 'integer' || $schema->type === 'number') {
                    $schema->maximum = $val;
                } elseif ($schema->type === 'array') {
                    $schema->extensions['maxItems'] = $val;
                } else {
                    $schema->maxLength = $val;
                }

                continue;
            }

            if ($ruleLower === 'size' && $ruleParam !== null && is_numeric($ruleParam)) {
                $val = (int) $ruleParam;
                if ($schema->type === 'string') {
                    $schema->minLength = $val;
                    $schema->maxLength = $val;
                } elseif ($schema->type === 'integer' || $schema->type === 'number') {
                    $schema->minimum = $val;
                    $schema->maximum = $val;
                } elseif ($schema->type === 'array') {
                    $schema->extensions['minItems'] = $val;
                    $schema->extensions['maxItems'] = $val;
                } else {
                    $schema->minLength = $val;
                    $schema->maxLength = $val;
                }

                continue;
            }

            if ($ruleLower === 'between' && $ruleParam !== null && str_contains($ruleParam, ',')) {
                [$minVal, $maxVal] = explode(',', $ruleParam, 2);
                if (is_numeric($minVal) && is_numeric($maxVal)) {
                    $min = (int) $minVal;
                    $max = (int) $maxVal;
                    if ($schema->type === 'string') {
                        $schema->minLength = $min;
                        $schema->maxLength = $max;
                    } elseif ($schema->type === 'integer' || $schema->type === 'number') {
                        $schema->minimum = $min;
                        $schema->maximum = $max;
                    } elseif ($schema->type === 'array') {
                        $schema->extensions['minItems'] = $min;
                        $schema->extensions['maxItems'] = $max;
                    } else {
                        $schema->minLength = $min;
                        $schema->maxLength = $max;
                    }
                }

                continue;
            }

            // in:a,b,c or in_array
            if ($ruleLower === 'in' && $ruleParam !== null) {
                $items = array_map('trim', explode(',', $ruleParam));
                $schema->enum = array_values(array_filter($items, fn ($v) => $v !== ''));
                if ($schema->type === null) {
                    $schema->type = 'string';
                }

                continue;
            }

            // Enum:SomeClass or Rule::enum(SomeClass::class)
            if ($ruleLower === 'enum' && $ruleParam !== null) {
                $enumClass = ltrim($ruleParam, '\\');
                $enumCases = $this->enumParser->parseCases($enumClass);
                if ($enumCases['cases'] !== []) {
                    $schema->enum = $enumCases['cases'];
                    $schema->type = $enumCases['type'] ?? 'string';
                }
                foreach ($enumCases['diagnostics'] as $d) {
                    $diagnostics[] = $d;
                }

                continue;
            }

            // exists:table,column
            if ($ruleLower === 'exists' && $ruleParam !== null) {
                $parts = explode(',', $ruleParam);
                $tableName = trim($parts[0] ?? '');
                $columnName = trim($parts[1] ?? 'id');

                $resolvedType = $this->resolveExistsColumnType($tableName, $columnName, $dbSchema);
                if ($resolvedType !== null) {
                    $schema->type = $resolvedType['type'];
                    if ($resolvedType['format'] !== null) {
                        $schema->format = $resolvedType['format'];
                    }
                } else {
                    if ($schema->type === null) {
                        $schema->type = 'string';
                    }
                    $diagnostics[] = new InferenceDiagnostic(
                        $file,
                        $line,
                        sprintf('Could not statically resolve column "%s" on table "%s" for exists rule — falling back to string', $columnName, $tableName)
                    );
                }

                continue;
            }

            // confirmed is handled by ConfirmedRuleTransformer, ignore here
            if ($ruleLower === 'confirmed') {
                continue;
            }

            // Unsupported rule for now
            $diagnostics[] = new InferenceDiagnostic(
                $file,
                $line,
                sprintf('Unsupported validation rule "%s" on field "%s" — skipped in Phase 4', $rule, $fieldName)
            );
        }

        if ($isNullable && $schema->type !== null) {
            $schema->type = is_array($schema->type)
                ? array_unique(array_merge($schema->type, ['null']))
                : [$schema->type, 'null'];
        } elseif ($isNullable && $schema->type === null) {
            $schema->type = ['string', 'null'];
        }

        return [
            'schema' => $schema,
            'required' => $required,
            'hasFile' => $hasFile,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array{type: string, format: ?string}|null
     */
    private function resolveExistsColumnType(string $tableName, string $columnName, ?DatabaseSchema $dbSchema): ?array
    {
        if ($dbSchema === null) {
            return null;
        }

        $table = $dbSchema->tables[$tableName] ?? null;
        if ($table === null) {
            foreach ($dbSchema->tables as $k => $t) {
                if ($t->name === $tableName || str_ends_with($k, '.'.$tableName)) {
                    $table = $t;
                    break;
                }
            }
        }

        if ($table === null || ! isset($table->columns[$columnName])) {
            return null;
        }

        $col = $table->columns[$columnName];

        if ($col->blueprintMethod === 'uuid' || $col->type === ColumnType::Uuid) {
            return ['type' => 'string', 'format' => 'uuid'];
        }

        if ($col->blueprintMethod === 'ulid' || $col->type === ColumnType::Ulid) {
            return ['type' => 'string', 'format' => 'ulid'];
        }

        return match ($col->type) {
            ColumnType::Integer => ['type' => 'integer', 'format' => null],
            ColumnType::Float, ColumnType::Decimal => ['type' => 'number', 'format' => null],
            ColumnType::Boolean => ['type' => 'boolean', 'format' => null],
            ColumnType::Date => ['type' => 'string', 'format' => 'date'],
            ColumnType::DateTime => ['type' => 'string', 'format' => 'date-time'],
            ColumnType::Time => ['type' => 'string', 'format' => 'time'],
            default => ['type' => 'string', 'format' => null],
        };
    }
}
