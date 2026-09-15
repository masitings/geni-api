<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Schema;

/**
 * Merges inferred schema with explicit annotation metadata.
 *
 * Implements architecture.md section 3.1:
 * - When `infer: false`, the inferred schema is discarded in favor of annotation values.
 * - When `infer: true` or absent, annotation values override/supplement inferred schema facets.
 *
 * Framework-free.
 */
final class AnnotationMerger
{
    /**
     * Merge a parameter or field schema with annotation values.
     *
     * @param  array{
     *     name?: string,
     *     description?: ?string,
     *     required?: ?bool,
     *     deprecated?: ?bool,
     *     type?: mixed,
     *     format?: ?string,
     *     infer?: bool,
     *     default?: mixed,
     *     example?: mixed,
     *     examples?: ?array,
     *     enum?: ?array
     * }  $annotation
     * @return array{schema: Schema, required?: bool}
     */
    public function mergeParameter(Schema $inferred, array $annotation): array
    {
        $infer = $annotation['infer'] ?? true;

        if (! $infer) {
            // Discard inferred schema entirely
            $schema = new Schema;
            if (isset($annotation['type']) && $annotation['type'] !== null) {
                $schema->type = $this->normalizeType($annotation['type']);
            }
            if (isset($annotation['format']) && $annotation['format'] !== null) {
                $schema->format = $annotation['format'];
            }
            if (isset($annotation['default'])) {
                $schema->default = $annotation['default'];
            }
            if (isset($annotation['description']) && $annotation['description'] !== null) {
                $schema->description = $annotation['description'];
            }
            if (isset($annotation['enum']) && is_array($annotation['enum'])) {
                $schema->enum = $annotation['enum'];
            }

            return [
                'schema' => $schema,
                'required' => $annotation['required'] ?? false,
            ];
        }

        // Layer annotation onto inferred schema
        $schema = clone $inferred;

        if (isset($annotation['type']) && $annotation['type'] !== null) {
            $schema->type = $this->normalizeType($annotation['type']);
        }
        if (isset($annotation['format']) && $annotation['format'] !== null) {
            $schema->format = $annotation['format'];
        }
        if (isset($annotation['default'])) {
            $schema->default = $annotation['default'];
        }
        if (isset($annotation['description']) && $annotation['description'] !== null) {
            $schema->description = $annotation['description'];
        }
        if (isset($annotation['example'])) {
            $schema->extensions['example'] = $annotation['example'];
        }
        if (isset($annotation['examples']) && is_array($annotation['examples'])) {
            $schema->extensions['examples'] = $annotation['examples'];
        }
        if (isset($annotation['enum']) && is_array($annotation['enum'])) {
            $schema->enum = $annotation['enum'];
        }

        $required = $annotation['required'] ?? null;

        return [
            'schema' => $schema,
            'required' => $required,
        ];
    }

    /**
     * Normalize type value (e.g. PHP class name, string, array).
     */
    private function normalizeType(mixed $type): mixed
    {
        if (is_string($type)) {
            $lower = strtolower($type);

            return match ($lower) {
                'int', 'integer' => 'integer',
                'float', 'double', 'numeric' => 'number',
                'bool', 'boolean' => 'boolean',
                'array' => 'array',
                'object' => 'object',
                default => $type,
            };
        }

        return $type;
    }
}
