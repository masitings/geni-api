<?php

declare(strict_types=1);

namespace Geni\Inference\QueryBuilder;

use Geni\Inference\Document\Parameter;
use Geni\Inference\Document\Schema;
use Geni\Inference\InferenceDiagnostic;
use Geni\SchemaReader\ColumnType;
use Geni\SchemaReader\DatabaseSchema;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

/**
 * Parses individual filter arguments passed to QueryBuilder::allowedFilters().
 *
 * Framework-free: AST-only traversal.
 */
final class AllowedFilterParser
{
    /**
     * Parse an argument node or array item representing an allowed filter.
     *
     * @param  array<string, string>  $useImports
     * @return array{parameter: ?Parameter, diagnostic: ?InferenceDiagnostic}
     */
    public function parseFilter(
        Node $node,
        ?string $tableName = null,
        ?DatabaseSchema $dbSchema = null,
        string $filePath = '',
        array $useImports = []
    ): array {
        // Case 1: Plain string literal 'name'
        if ($node instanceof String_) {
            $name = $node->value;
            $param = $this->createFilterParameter($name, $tableName, $dbSchema);

            return ['parameter' => $param, 'diagnostic' => null];
        }

        // Case 2: Static call to AllowedFilter::*
        if ($node instanceof StaticCall && $node->class instanceof Name) {
            $classStr = $this->resolveClassName($node->class, $useImports);
            if (str_ends_with($classStr, 'AllowedFilter')) {
                return $this->parseAllowedFilterStaticCall($node, $tableName, $dbSchema, $filePath);
            }
        }

        // Case 3: Non-literal / unsupported expression
        return [
            'parameter' => null,
            'diagnostic' => new InferenceDiagnostic(
                $filePath,
                $node->getLine(),
                'Dynamic expression in allowedFilters(): filter not statically extracted'
            ),
        ];
    }

    /**
     * Parse AllowedFilter::exact, ::partial, ::scope, ::trashed, etc.
     *
     * @return array{parameter: ?Parameter, diagnostic: ?InferenceDiagnostic}
     */
    private function parseAllowedFilterStaticCall(
        StaticCall $call,
        ?string $tableName = null,
        ?DatabaseSchema $dbSchema = null,
        string $filePath = ''
    ): array {
        if (! ($call->name instanceof Identifier)) {
            return ['parameter' => null, 'diagnostic' => null];
        }

        $method = $call->name->toString();

        // Special case: AllowedFilter::trashed('name') (default is 'trashed')
        if ($method === 'trashed') {
            $filterName = 'trashed';
            if (isset($call->args[0]) && $call->args[0] instanceof Arg && $call->args[0]->value instanceof String_) {
                $filterName = $call->args[0]->value->value;
            }

            $schema = new Schema;
            $schema->type = 'string';
            $schema->enum = ['with', 'only'];

            $param = new Parameter("filter[{$filterName}]", 'query', false, $schema);
            $param->description = 'Filter by soft-deleted state (with or only).';

            return ['parameter' => $param, 'diagnostic' => null];
        }

        // Methods where first argument is the filter name
        if (isset($call->args[0]) && $call->args[0] instanceof Arg && $call->args[0]->value instanceof String_) {
            $filterName = $call->args[0]->value->value;

            // Check if internal column name is passed as second argument
            $internalColumn = $filterName;
            if (isset($call->args[1]) && $call->args[1] instanceof Arg && $call->args[1]->value instanceof String_) {
                $internalColumn = $call->args[1]->value->value;
            }

            $columnToResolve = ($method === 'exact' || $method === 'partial') ? $internalColumn : null;
            $param = $this->createFilterParameter($filterName, $tableName, $dbSchema, $columnToResolve);

            if ($method === 'scope') {
                $param->description = "Filter records using the {$filterName} scope.";
            } elseif ($method === 'exact') {
                $param->description = "Filter by exact match on {$filterName}.";
            } elseif ($method === 'partial') {
                $param->description = "Filter by partial match on {$filterName}.";
            } elseif ($method === 'beginsWithStrict' || $method === 'endsWithStrict') {
                $param->description = "Filter matching boundary on {$filterName}.";
            }

            return ['parameter' => $param, 'diagnostic' => null];
        }

        return [
            'parameter' => null,
            'diagnostic' => new InferenceDiagnostic(
                $filePath,
                $call->getLine(),
                "Non-literal argument in AllowedFilter::{$method}(): filter not statically extracted"
            ),
        ];
    }

    private function createFilterParameter(
        string $filterName,
        ?string $tableName = null,
        ?DatabaseSchema $dbSchema = null,
        ?string $columnName = null
    ): Parameter {
        $colToLookUp = $columnName ?? $filterName;
        $schemaType = 'string';

        if ($tableName !== null && $dbSchema !== null) {
            $table = $dbSchema->tables[$tableName] ?? null;
            if ($table === null) {
                foreach ($dbSchema->tables as $k => $t) {
                    if ($t->name === $tableName || str_ends_with($k, '.'.$tableName)) {
                        $table = $t;
                        break;
                    }
                }
            }

            if ($table !== null && isset($table->columns[$colToLookUp])) {
                $col = $table->columns[$colToLookUp];
                $schemaType = $this->mapColumnTypeToSchemaType($col->type);
            }
        }

        $schema = new Schema;
        $schema->type = $schemaType;

        return new Parameter("filter[{$filterName}]", 'query', false, $schema);
    }

    private function mapColumnTypeToSchemaType(ColumnType $type): string
    {
        return match ($type) {
            ColumnType::Integer => 'integer',
            ColumnType::Boolean => 'boolean',
            ColumnType::Decimal, ColumnType::Float => 'number',
            default => 'string',
        };
    }

    private function resolveClassName(Name $name, array $useImports): string
    {
        $first = $name->getFirst();
        if (isset($useImports[$first])) {
            if (count($name->getParts()) === 1) {
                return $useImports[$first];
            }

            return $useImports[$first].'\\'.implode('\\', array_slice($name->getParts(), 1));
        }

        return $name->toString();
    }
}
