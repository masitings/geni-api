<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Parameter;
use Geni\Inference\Document\Schema;
use Geni\SchemaReader\ColumnType;
use Geni\SchemaReader\DatabaseSchema;

/**
 * Infers path parameter types from route URI parameters and schema reader.
 *
 * Framework-free.
 */
final class PathParameterInferer
{
    private ModelTableResolver $tableResolver;

    public function __construct(?ModelTableResolver $tableResolver = null)
    {
        $this->tableResolver = $tableResolver ?? new ModelTableResolver;
    }

    /**
     * Extract parameter names from route URI template, e.g. "/api/posts/{post}/{comment?}"
     *
     * @return list<string>
     */
    public function extractParamNames(string $uri): array
    {
        preg_match_all('/\{([a-zA-Z0-9_]+)\??\}/', $uri, $matches);

        return $matches[1] ?? [];
    }

    /**
     * Infer Parameter for a single path parameter name.
     *
     * @param  string  $paramName  e.g. 'post'
     * @param  string|null  $boundModelClass  e.g. 'App\Models\Post' or null
     * @param  string|null  $routeKeyName  e.g. 'id' or 'uuid' (default 'id')
     * @param  string  $file  Context for diagnostics
     * @param  int  $line  Context for diagnostics
     * @return array{parameter: Parameter, diagnostics: list<InferenceDiagnostic>}
     */
    public function inferParam(
        string $paramName,
        ?DatabaseSchema $schema = null,
        ?string $boundModelClass = null,
        ?string $routeKeyName = null,
        string $file = 'unknown',
        int $line = 0,
        array $schemaOverrides = [],
    ): array {
        $diagnostics = [];
        $keyName = $routeKeyName ?? 'id';

        // Default type if nothing resolves
        $paramType = 'string';
        $paramFormat = null;

        if ($schema !== null && $boundModelClass !== null) {
            $tableName = $this->tableResolver->resolve($boundModelClass);

            // Find table in schema (check connection.table and table)
            $table = $schema->tables[$tableName] ?? null;
            if ($table === null) {
                // Try searching all tables by suffix
                foreach ($schema->tables as $k => $t) {
                    if ($t->name === $tableName || str_ends_with($k, '.'.$tableName)) {
                        $table = $t;
                        break;
                    }
                }
            }

            $overrideKey = $tableName.'.'.$keyName;

            if ($table !== null && isset($table->columns[$keyName])) {
                $col = $table->columns[$keyName];
                [$paramType, $paramFormat] = $this->columnTypeToOpenApi($col->type, $col->blueprintMethod);
            } elseif (isset($schemaOverrides[$overrideKey])) {
                // FR-005 / TASK-007: Consult schema_overrides escape hatch before falling back to string
                $paramType = $schemaOverrides[$overrideKey];
                $paramFormat = null;
            } else {
                $diagnostics[] = new InferenceDiagnostic(
                    $file,
                    $line,
                    sprintf('Could not resolve route-model bound column "%s" on table "%s" for model "%s": falling back to string', $keyName, $tableName, $boundModelClass)
                );
            }
        } elseif ($schema !== null) {
            // Unbound param: if paramName looks like a model name (e.g. 'post' or 'user_id'), we can try guessing table
            $tableName = $this->tableResolver->pluralize($paramName);
            $overrideKey = $tableName.'.'.$keyName;

            $table = $schema->tables[$tableName] ?? null;
            if ($table !== null && isset($table->columns['id'])) {
                $col = $table->columns['id'];
                [$paramType, $paramFormat] = $this->columnTypeToOpenApi($col->type, $col->blueprintMethod);
            } elseif (isset($schemaOverrides[$overrideKey])) {
                $paramType = $schemaOverrides[$overrideKey];
            } else {
                $paramType = 'string';
            }
        }

        $paramSchema = new Schema;
        $paramSchema->type = $paramType;
        if ($paramFormat !== null) {
            $paramSchema->format = $paramFormat;
        }

        $parameter = new Parameter(
            name: $paramName,
            in: 'path',
            required: true,
            schema: $paramSchema
        );

        return [
            'parameter' => $parameter,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array{0: string, 1: ?string} [type, format]
     */
    private function columnTypeToOpenApi(ColumnType $type, string $blueprintMethod): array
    {
        if ($blueprintMethod === 'uuid' || $type === ColumnType::Uuid) {
            return ['string', 'uuid'];
        }

        if ($blueprintMethod === 'ulid' || $type === ColumnType::Ulid) {
            return ['string', 'ulid'];
        }

        return match ($type) {
            ColumnType::Integer => ['integer', null],
            ColumnType::Float, ColumnType::Decimal => ['number', null],
            ColumnType::Boolean => ['boolean', null],
            ColumnType::Date => ['string', 'date'],
            ColumnType::DateTime => ['string', 'date-time'],
            ColumnType::Time => ['string', 'time'],
            ColumnType::Json => ['object', null],
            default => ['string', null],
        };
    }
}
