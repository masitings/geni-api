<?php

declare(strict_types=1);

namespace Geni\Inference\QueryBuilder;

use Geni\Inference\ActionAst;
use Geni\Inference\Document\Parameter;
use Geni\Inference\Document\Schema;
use Geni\Inference\InferenceDiagnostic;
use Geni\Inference\ModelTableResolver;
use Geni\SchemaReader\DatabaseSchema;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

/**
 * Statically analyzes controller action AST for Spatie QueryBuilder::for(...) method chains
 * and extracts corresponding OpenAPI query parameters (filters, sorts, includes, fields, appends).
 *
 * Framework-free: uses php-parser and AST traversal only, zero application booting.
 */
final class QueryBuilderExtractor
{
    private NodeFinder $finder;

    private AllowedFilterParser $filterParser;

    private AllowedSortParser $sortParser;

    private ModelTableResolver $tableResolver;

    public function __construct(
        ?NodeFinder $finder = null,
        ?AllowedFilterParser $filterParser = null,
        ?AllowedSortParser $sortParser = null,
        ?ModelTableResolver $tableResolver = null
    ) {
        $this->finder = $finder ?? new NodeFinder;
        $this->filterParser = $filterParser ?? new AllowedFilterParser;
        $this->sortParser = $sortParser ?? new AllowedSortParser;
        $this->tableResolver = $tableResolver ?? new ModelTableResolver;
    }

    /**
     * Extract QueryBuilder parameters from an action AST.
     *
     * @return array{parameters: list<Parameter>, diagnostics: list<InferenceDiagnostic>}
     */
    public function extractFromActionAst(ActionAst $ast, ?DatabaseSchema $dbSchema = null): array
    {
        $parameters = [];
        $diagnostics = [];

        // Look for StaticCall to QueryBuilder::for(...)
        $builderCalls = $this->findQueryBuilderCalls($ast->node);
        if (empty($builderCalls)) {
            return ['parameters' => [], 'diagnostics' => []];
        }

        foreach ($builderCalls as $callContext) {
            $baseCall = $callContext['staticCall'];
            $chainMethods = $callContext['chainMethods'];

            // Resolve target model class and table
            $modelClass = $this->resolveModelClass($baseCall, $ast->useImports);
            $tableName = $modelClass !== null ? $this->tableResolver->resolve($modelClass) : null;

            // 1. Process allowedFilters
            foreach ($chainMethods['allowedFilters'] ?? [] as $methodCall) {
                $filterResults = $this->extractFilters($methodCall, $tableName, $dbSchema, $ast->file, $ast->useImports);
                foreach ($filterResults['parameters'] as $p) {
                    $parameters[] = $p;
                }
                foreach ($filterResults['diagnostics'] as $d) {
                    $diagnostics[] = $d;
                }
            }

            // 2. Process allowedSorts and defaultSort
            $sortFields = [];
            $defaultSort = null;

            foreach ($chainMethods['allowedSorts'] ?? [] as $sortCall) {
                $sortRes = $this->sortParser->parseAllowedSorts($sortCall->args, $ast->file, $ast->useImports);
                $sortFields = array_merge($sortFields, $sortRes['fields']);
                foreach ($sortRes['diagnostics'] as $d) {
                    $diagnostics[] = $d;
                }
            }

            foreach ($chainMethods['defaultSort'] ?? [] as $defaultCall) {
                $def = $this->sortParser->parseDefaultSort($defaultCall->args);
                if ($def !== null) {
                    $defaultSort = $def;
                }
            }

            if (! empty($sortFields)) {
                $sortParam = $this->sortParser->createSortParameter($sortFields, $defaultSort);
                if ($sortParam !== null) {
                    $parameters[] = $sortParam;
                }
            }

            // 3. Process allowedIncludes
            foreach ($chainMethods['allowedIncludes'] ?? [] as $incCall) {
                $incRes = $this->extractIncludes($incCall, $ast->file);
                if ($incRes['parameter'] !== null) {
                    $parameters[] = $incRes['parameter'];
                }
                foreach ($incRes['diagnostics'] as $d) {
                    $diagnostics[] = $d;
                }
            }

            // 4. Process allowedFields
            foreach ($chainMethods['allowedFields'] ?? [] as $fieldCall) {
                $fieldRes = $this->extractFields($fieldCall, $tableName, $ast->file);
                foreach ($fieldRes['parameters'] as $p) {
                    $parameters[] = $p;
                }
                foreach ($fieldRes['diagnostics'] as $d) {
                    $diagnostics[] = $d;
                }
            }

            // 5. Process allowedAppends
            foreach ($chainMethods['allowedAppends'] ?? [] as $appendCall) {
                $appRes = $this->extractAppends($appendCall, $ast->file);
                if ($appRes['parameter'] !== null) {
                    $parameters[] = $appRes['parameter'];
                }
                foreach ($appRes['diagnostics'] as $d) {
                    $diagnostics[] = $d;
                }
            }
        }

        // Deduplicate parameters by name + in
        $deduped = [];
        foreach ($parameters as $param) {
            $key = "{$param->in}:{$param->name}";
            $deduped[$key] = $param;
        }

        return [
            'parameters' => array_values($deduped),
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Find QueryBuilder::for(...) static calls and map out chained method calls.
     *
     * @return list<array{staticCall: StaticCall, chainMethods: array<string, list<MethodCall>>}>
     */
    private function findQueryBuilderCalls(Node $astNode): array
    {
        $calls = [];

        $staticCalls = $this->finder->find($astNode, function (Node $node) {
            return $node instanceof StaticCall
                && $node->class instanceof Name
                && str_ends_with($node->class->toString(), 'QueryBuilder')
                && $node->name instanceof Identifier
                && $node->name->toString() === 'for';
        });

        foreach ($staticCalls as $sc) {
            if (! ($sc instanceof StaticCall)) {
                continue;
            }

            $chain = $this->collectChainedCalls($sc, $astNode);
            $calls[] = [
                'staticCall' => $sc,
                'chainMethods' => $chain,
            ];
        }

        return $calls;
    }

    /**
     * Walk the AST upwards and across statements to collect fluent chained methods.
     *
     * @return array<string, list<MethodCall>>
     */
    private function collectChainedCalls(StaticCall $rootCall, Node $scopeNode): array
    {
        $methods = [];
        $targetVarName = null;

        // 1. Traverse upward from the static call to find direct chaining
        // e.g. QueryBuilder::for(...)->allowedFilters(...)->get()
        $current = $rootCall;
        $parent = $this->findParentOf($current, $scopeNode);

        while ($parent instanceof MethodCall && $parent->var === $current) {
            if ($parent->name instanceof Identifier) {
                $name = $parent->name->toString();
                $normalized = match ($name) {
                    'defaultSorts' => 'defaultSort',
                    default => $name,
                };
                $methods[$normalized][] = $parent;
            }

            $current = $parent;
            $parent = $this->findParentOf($current, $scopeNode);
        }

        // Check if assigned to a variable: $query = QueryBuilder::for(...)
        if ($parent instanceof Node\Expr\Assign && $parent->expr === $current && $parent->var instanceof Variable && is_string($parent->var->name)) {
            $targetVarName = $parent->var->name;
        }

        // 2. If assigned to a variable, find subsequent method calls on that variable
        if ($targetVarName !== null) {
            $subsequentCalls = $this->finder->find($scopeNode, function (Node $node) use ($targetVarName) {
                return $node instanceof MethodCall
                    && $node->var instanceof Variable
                    && $node->var->name === $targetVarName;
            });

            foreach ($subsequentCalls as $mCall) {
                if ($mCall instanceof MethodCall && $mCall->name instanceof Identifier) {
                    $name = $mCall->name->toString();
                    $normalized = match ($name) {
                        'defaultSorts' => 'defaultSort',
                        default => $name,
                    };
                    $methods[$normalized][] = $mCall;
                }
            }
        }

        return $methods;
    }

    private function findParentOf(Node $target, Node $scope): ?Node
    {
        return $this->finder->findFirst($scope, function (Node $node) use ($target) {
            foreach ($node->getSubNodeNames() as $sub) {
                $val = $node->$sub;
                if ($val === $target) {
                    return true;
                }
                if (is_array($val)) {
                    foreach ($val as $item) {
                        if ($item === $target) {
                            return true;
                        }
                    }
                }
            }

            return false;
        });
    }

    /**
     * @return array{parameters: list<Parameter>, diagnostics: list<InferenceDiagnostic>}
     */
    private function extractFilters(
        MethodCall $call,
        ?string $tableName,
        ?DatabaseSchema $dbSchema,
        string $filePath,
        array $useImports
    ): array {
        $parameters = [];
        $diagnostics = [];

        foreach ($call->args as $arg) {
            if (! ($arg instanceof Arg)) {
                continue;
            }

            $val = $arg->value;

            if ($val instanceof Array_) {
                foreach ($val->items as $item) {
                    if ($item === null) {
                        continue;
                    }
                    $res = $this->filterParser->parseFilter($item->value, $tableName, $dbSchema, $filePath, $useImports);
                    if ($res['parameter'] !== null) {
                        $parameters[] = $res['parameter'];
                    }
                    if ($res['diagnostic'] !== null) {
                        $diagnostics[] = $res['diagnostic'];
                    }
                }
            } else {
                $res = $this->filterParser->parseFilter($val, $tableName, $dbSchema, $filePath, $useImports);
                if ($res['parameter'] !== null) {
                    $parameters[] = $res['parameter'];
                }
                if ($res['diagnostic'] !== null) {
                    $diagnostics[] = $res['diagnostic'];
                }
            }
        }

        return [
            'parameters' => $parameters,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array{parameter: ?Parameter, diagnostics: list<InferenceDiagnostic>}
     */
    private function extractIncludes(MethodCall $call, string $filePath): array
    {
        $relations = [];
        $diagnostics = [];

        foreach ($call->args as $arg) {
            if (! ($arg instanceof Arg)) {
                continue;
            }

            $val = $arg->value;
            if ($val instanceof Array_) {
                foreach ($val->items as $item) {
                    if ($item !== null && $item->value instanceof String_) {
                        $relations[] = $item->value->value;
                    }
                }
            } elseif ($val instanceof String_) {
                $relations[] = $val->value;
            }
        }

        if (empty($relations)) {
            return ['parameter' => null, 'diagnostics' => $diagnostics];
        }

        $schema = new Schema;
        $schema->type = 'string';

        $param = new Parameter('include', 'query', false, $schema);
        $param->description = 'Comma-separated list of relationships to include: '.implode(', ', array_unique($relations)).'.';

        return ['parameter' => $param, 'diagnostics' => $diagnostics];
    }

    /**
     * @return array{parameters: list<Parameter>, diagnostics: list<InferenceDiagnostic>}
     */
    private function extractFields(MethodCall $call, ?string $defaultTable, string $filePath): array
    {
        $parameters = [];
        $diagnostics = [];
        $grouped = [];

        foreach ($call->args as $arg) {
            if (! ($arg instanceof Arg)) {
                continue;
            }

            $val = $arg->value;
            $fields = [];

            if ($val instanceof Array_) {
                foreach ($val->items as $item) {
                    if ($item !== null && $item->value instanceof String_) {
                        $fields[] = $item->value->value;
                    }
                }
            } elseif ($val instanceof String_) {
                $fields[] = $val->value;
            }

            foreach ($fields as $fieldStr) {
                if (str_contains($fieldStr, '.')) {
                    [$resource, $col] = explode('.', $fieldStr, 2);
                    $grouped[$resource][] = $col;
                } else {
                    $resName = $defaultTable ?? 'default';
                    $grouped[$resName][] = $fieldStr;
                }
            }
        }

        foreach ($grouped as $resource => $cols) {
            $schema = new Schema;
            $schema->type = 'string';

            $param = new Parameter("fields[{$resource}]", 'query', false, $schema);
            $param->description = "Comma-separated list of fields to select for {$resource}: ".implode(', ', array_unique($cols)).'.';
            $parameters[] = $param;
        }

        return [
            'parameters' => $parameters,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array{parameter: ?Parameter, diagnostics: list<InferenceDiagnostic>}
     */
    private function extractAppends(MethodCall $call, string $filePath): array
    {
        $appends = [];
        $diagnostics = [];

        foreach ($call->args as $arg) {
            if (! ($arg instanceof Arg)) {
                continue;
            }

            $val = $arg->value;
            if ($val instanceof Array_) {
                foreach ($val->items as $item) {
                    if ($item !== null && $item->value instanceof String_) {
                        $appends[] = $item->value->value;
                    }
                }
            } elseif ($val instanceof String_) {
                $appends[] = $val->value;
            }
        }

        if (empty($appends)) {
            return ['parameter' => null, 'diagnostics' => $diagnostics];
        }

        $schema = new Schema;
        $schema->type = 'string';
        $schema->enum = array_values(array_unique($appends));

        $param = new Parameter('append', 'query', false, $schema);
        $param->description = 'Comma-separated list of accessors to append.';

        return ['parameter' => $param, 'diagnostics' => $diagnostics];
    }

    private function resolveModelClass(StaticCall $call, array $useImports): ?string
    {
        if (! isset($call->args[0]) || ! ($call->args[0] instanceof Arg)) {
            return null;
        }

        $argVal = $call->args[0]->value;

        // Model::class
        if ($argVal instanceof ClassConstFetch && $argVal->class instanceof Name && $argVal->name instanceof Identifier && $argVal->name->toString() === 'class') {
            return $this->resolveClassName($argVal->class, $useImports);
        }

        // Model::where(...) static call
        if ($argVal instanceof StaticCall && $argVal->class instanceof Name) {
            return $this->resolveClassName($argVal->class, $useImports);
        }

        // String table/model name 'users'
        if ($argVal instanceof String_) {
            return $argVal->value;
        }

        return null;
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
