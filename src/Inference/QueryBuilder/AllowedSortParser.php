<?php

declare(strict_types=1);

namespace Geni\Inference\QueryBuilder;

use Geni\Inference\Document\Parameter;
use Geni\Inference\Document\Schema;
use Geni\Inference\InferenceDiagnostic;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

/**
 * Parses allowedSorts and defaultSort arguments on QueryBuilder chains.
 *
 * Framework-free: AST-only traversal.
 */
final class AllowedSortParser
{
    /**
     * Parse arguments to allowedSorts(...) and return the expanded sort field names.
     *
     * @param  array<string, string>  $useImports
     * @return array{fields: list<string>, diagnostics: list<InferenceDiagnostic>}
     */
    public function parseAllowedSorts(
        array $args,
        string $filePath = '',
        array $useImports = []
    ): array {
        $fields = [];
        $diagnostics = [];

        foreach ($args as $arg) {
            if (! ($arg instanceof Arg)) {
                continue;
            }

            $val = $arg->value;

            // Could be an array of sort strings or individual variadic arguments
            if ($val instanceof Array_) {
                foreach ($val->items as $item) {
                    if ($item === null) {
                        continue;
                    }
                    $res = $this->extractSortField($item->value, $filePath, $useImports);
                    if ($res['field'] !== null) {
                        $fields[] = $res['field'];
                    }
                    if ($res['diagnostic'] !== null) {
                        $diagnostics[] = $res['diagnostic'];
                    }
                }
            } else {
                $res = $this->extractSortField($val, $filePath, $useImports);
                if ($res['field'] !== null) {
                    $fields[] = $res['field'];
                }
                if ($res['diagnostic'] !== null) {
                    $diagnostics[] = $res['diagnostic'];
                }
            }
        }

        return [
            'fields' => array_values(array_unique($fields)),
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Extract default sort value from defaultSort(...) or defaultSorts(...) arguments.
     *
     * @param  array<Arg>  $args
     */
    public function parseDefaultSort(array $args): ?string
    {
        if (empty($args)) {
            return null;
        }

        $first = $args[0]->value;
        if ($first instanceof String_) {
            return $first->value;
        }

        if ($first instanceof Array_ && ! empty($first->items) && $first->items[0] !== null) {
            if ($first->items[0]->value instanceof String_) {
                return $first->items[0]->value->value;
            }
        }

        return null;
    }

    /**
     * Create the unified sort Parameter from allowed fields and default sort.
     *
     * @param  list<string>  $fields
     */
    public function createSortParameter(array $fields, ?string $defaultSort = null): ?Parameter
    {
        if (empty($fields)) {
            return null;
        }

        $enum = [];
        foreach ($fields as $field) {
            $enum[] = $field;
            $enum[] = "-{$field}";
        }

        $schema = new Schema;
        $schema->type = 'string';
        $schema->enum = array_values(array_unique($enum));

        if ($defaultSort !== null) {
            $schema->default = $defaultSort;
        }

        $param = new Parameter('sort', 'query', false, $schema);
        $param->description = 'Comma-separated list of fields to sort by (prefix with - for descending).';

        return $param;
    }

    /**
     * @param  array<string, string>  $useImports
     * @return array{field: ?string, diagnostic: ?InferenceDiagnostic}
     */
    private function extractSortField(Node $node, string $filePath, array $useImports): array
    {
        if ($node instanceof String_) {
            return ['field' => $node->value, 'diagnostic' => null];
        }

        if ($node instanceof StaticCall && $node->class instanceof Name) {
            $classStr = $this->resolveClassName($node->class, $useImports);
            if (str_ends_with($classStr, 'AllowedSort')) {
                if (isset($node->args[0]) && $node->args[0] instanceof Arg && $node->args[0]->value instanceof String_) {
                    return ['field' => $node->args[0]->value->value, 'diagnostic' => null];
                }
            }
        }

        return [
            'field' => null,
            'diagnostic' => new InferenceDiagnostic(
                $filePath,
                $node->getLine(),
                'Non-literal argument in allowedSorts(): sort field not statically extracted'
            ),
        ];
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
