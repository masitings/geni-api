<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Schema;

/**
 * Resolves response type by applying architecture.md section 3.1 priority rules:
 * 1. @response / #[Response] annotation wins if present.
 * 2. Inferred type wins when it is more specific than declared return type.
 * 3. Declared return type wins otherwise.
 *
 * Framework-free.
 */
final class ResponseTypeResolver
{
    /**
     * Resolve the response schema.
     */
    public function resolve(
        ?Schema $annotated,
        ?string $declaredReturnType,
        ?Schema $inferred
    ): Schema {
        // Priority 1: Explicit annotation (@response or #[Response])
        if ($annotated !== null && ($annotated->properties !== null || $annotated->ref !== null || $annotated->type !== null)) {
            return $annotated;
        }

        // Priority 2 & 3: Check specificity between inferred and declared
        $inferredHasShape = $inferred !== null && ($inferred->properties !== null || $inferred->ref !== null || $inferred->type !== null);

        if ($declaredReturnType !== null && $inferredHasShape) {
            if ($this->isInferredMoreSpecific($declaredReturnType, $inferred)) {
                return $inferred;
            }

            $declaredSchema = $this->scalarSchema($declaredReturnType);

            return $declaredSchema ?? $inferred;
        }

        if ($inferredHasShape) {
            return $inferred;
        }

        if ($declaredReturnType !== null) {
            // A class name is not an OpenAPI type; only PHP scalars map directly.
            return $this->scalarSchema($declaredReturnType) ?? new Schema;
        }

        return new Schema;
    }

    private function scalarSchema(string $declaredReturnType): ?Schema
    {
        $type = match (strtolower(ltrim($declaredReturnType, '\\'))) {
            'string' => 'string',
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $schema = new Schema;
        $schema->type = $type;

        return $schema;
    }

    /**
     * Determines whether an inferred schema is more specific than the declared return type.
     */
    public function isInferredMoreSpecific(string $declaredReturnType, Schema $inferred): bool
    {
        $declaredLower = strtolower(ltrim($declaredReturnType, '\\'));

        $genericTypes = [
            'response',
            'jsonresponse',
            'illuminate\http\response',
            'illuminate\http\jsonresponse',
            'mixed',
            'array',
            'object',
            'view',
        ];

        // If declared type is a broad/generic wrapper, concrete inferred schema is always more specific
        if (in_array($declaredLower, $genericTypes, true)) {
            return true;
        }

        // If declared type is a Resource class or Model, and inferred has extracted the full properties/ref,
        // the inferred concrete schema is more specific than just the class name string
        if ($inferred->properties !== null || $inferred->ref !== null || $inferred->anyOf !== null) {
            return true;
        }

        return false;
    }
}
