<?php

declare(strict_types=1);

namespace Geni\SchemaReader;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class MigrationParser
{
    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
    }

    /**
     * Parse a migration file and apply its schema mutations to the DatabaseSchema.
     */
    public function parseFile(string $filePath, DatabaseSchema $databaseSchema): void
    {
        $code = file_get_contents($filePath);
        if ($code === false) {
            $databaseSchema->unresolved[] = new UnresolvedConstruct(
                file: $filePath,
                line: 1,
                reason: 'Could not read migration file.'
            );

            return;
        }

        try {
            $stmts = $this->parser->parse($code);
        } catch (\Throwable $e) {
            $databaseSchema->unresolved[] = new UnresolvedConstruct(
                file: $filePath,
                line: (int) $e->getLine(),
                reason: 'PHP parse error: '.$e->getMessage()
            );

            return;
        }

        if ($stmts === null) {
            return;
        }

        $upMethods = $this->findUpMethods($stmts);

        foreach ($upMethods as $upMethod) {
            $this->processUpStatements($upMethod->stmts ?? [], $filePath, $databaseSchema);
        }
    }

    /**
     * Find all class methods named 'up' in the AST statements.
     *
     * @param  list<Node>  $stmts
     * @return list<Stmt\ClassMethod>
     */
    private function findUpMethods(array $stmts): array
    {
        $upMethods = [];

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($upMethods) extends NodeVisitorAbstract
        {
            /**
             * @param  list<Stmt\ClassMethod>  $upMethods
             */
            public function __construct(public array &$upMethods) {}

            public function enterNode(Node $node)
            {
                if ($node instanceof Stmt\ClassMethod && strtolower($node->name->toString()) === 'up') {
                    $this->upMethods[] = $node;
                }

                return null;
            }
        });

        $traverser->traverse($stmts);

        return $upMethods;
    }

    /**
     * Process statements inside the up() method.
     *
     * @param  list<Stmt>  $stmts
     */
    private function processUpStatements(array $stmts, string $filePath, DatabaseSchema $databaseSchema): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Expression) {
                $this->processExpression($stmt->expr, $stmt->getStartLine(), $filePath, $databaseSchema);
            } elseif ($stmt instanceof Stmt\If_) {
                $databaseSchema->unresolved[] = new UnresolvedConstruct(
                    file: $filePath,
                    line: $stmt->getStartLine(),
                    reason: 'Control flow construct (If_) evaluated tentatively.'
                );
                $this->processUpStatements($stmt->stmts, $filePath, $databaseSchema);
            } elseif (
                $stmt instanceof Stmt\Foreach_
                || $stmt instanceof Stmt\For_
                || $stmt instanceof Stmt\While_
                || $stmt instanceof Stmt\Switch_
            ) {
                $databaseSchema->unresolved[] = new UnresolvedConstruct(
                    file: $filePath,
                    line: $stmt->getStartLine(),
                    reason: 'Control flow construct ('.(new \ReflectionClass($stmt))->getShortName().') skipped.'
                );
            }
        }
    }

    /**
     * Process an expression that may contain a Schema:: call.
     */
    private function processExpression(Expr $expr, int $line, string $filePath, DatabaseSchema $databaseSchema): void
    {
        $schemaCall = $this->extractSchemaCall($expr);
        if ($schemaCall === null) {
            return;
        }

        $connection = $schemaCall['connection'];
        $operation = $schemaCall['operation'];
        $args = $schemaCall['args'];

        switch ($operation) {
            case 'drop':
            case 'dropIfExists':
                $tableName = null;
                if (! isset($args[0]) || ! AstHelper::tryResolveLiteral($args[0]->value, $tableName) || ! is_string($tableName)) {
                    $databaseSchema->unresolved[] = new UnresolvedConstruct(
                        file: $filePath,
                        line: $line,
                        reason: "Cannot resolve table name for Schema::{$operation}."
                    );

                    return;
                }

                $key = $connection !== null ? "{$connection}.{$tableName}" : $tableName;
                unset($databaseSchema->tables[$key]);
                break;

            case 'rename':
                $oldName = null;
                $newName = null;
                if (
                    ! isset($args[0], $args[1])
                    || ! AstHelper::tryResolveLiteral($args[0]->value, $oldName)
                    || ! AstHelper::tryResolveLiteral($args[1]->value, $newName)
                    || ! is_string($oldName)
                    || ! is_string($newName)
                ) {
                    $databaseSchema->unresolved[] = new UnresolvedConstruct(
                        file: $filePath,
                        line: $line,
                        reason: 'Cannot resolve table names for Schema::rename.'
                    );

                    return;
                }

                $oldKey = $connection !== null ? "{$connection}.{$oldName}" : $oldName;
                $newKey = $connection !== null ? "{$connection}.{$newName}" : $newName;

                if (isset($databaseSchema->tables[$oldKey])) {
                    $table = $databaseSchema->tables[$oldKey];
                    $table->name = $newName;
                    unset($databaseSchema->tables[$oldKey]);
                    $databaseSchema->tables[$newKey] = $table;
                }
                break;

            case 'create':
            case 'table':
                $tableName = null;
                if (! isset($args[0]) || ! AstHelper::tryResolveLiteral($args[0]->value, $tableName) || ! is_string($tableName)) {
                    $databaseSchema->unresolved[] = new UnresolvedConstruct(
                        file: $filePath,
                        line: $line,
                        reason: "Cannot resolve table name for Schema::{$operation}."
                    );

                    return;
                }

                if (! isset($args[1]) || ! ($args[1]->value instanceof Expr\Closure || $args[1]->value instanceof Expr\ArrowFunction)) {
                    $databaseSchema->unresolved[] = new UnresolvedConstruct(
                        file: $filePath,
                        line: $line,
                        reason: "Schema::{$operation} requires a closure definition."
                    );

                    return;
                }

                $closure = $args[1]->value;
                $blueprintVarName = null;
                if (count($closure->params) > 0 && $closure->params[0]->var instanceof Expr\Variable && is_string($closure->params[0]->var->name)) {
                    $blueprintVarName = $closure->params[0]->var->name;
                }

                if ($blueprintVarName === null) {
                    $databaseSchema->unresolved[] = new UnresolvedConstruct(
                        file: $filePath,
                        line: $line,
                        reason: 'Blueprint parameter missing in closure.'
                    );

                    return;
                }

                $tableKey = $connection !== null ? "{$connection}.{$tableName}" : $tableName;
                if ($operation === 'create' || ! isset($databaseSchema->tables[$tableKey])) {
                    $databaseSchema->tables[$tableKey] = new TableSchema(
                        name: $tableName,
                        connection: $connection,
                        columns: []
                    );
                }

                $tableSchema = $databaseSchema->tables[$tableKey];

                $closureStmts = $closure instanceof Expr\Closure
                    ? $closure->stmts
                    : [new Stmt\Expression($closure->expr)];

                $this->processClosureStatements($closureStmts, $blueprintVarName, $tableSchema, $filePath, $databaseSchema);
                break;
        }
    }

    /**
     * Process statements inside a Blueprint closure.
     *
     * @param  list<Stmt>  $stmts
     */
    private function processClosureStatements(
        array $stmts,
        string $blueprintVarName,
        TableSchema $tableSchema,
        string $filePath,
        DatabaseSchema $databaseSchema
    ): void {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Expression) {
                $this->processBlueprintCallChain($stmt->expr, $blueprintVarName, $tableSchema, $filePath, $databaseSchema);
            } elseif ($stmt instanceof Stmt\If_) {
                $databaseSchema->unresolved[] = new UnresolvedConstruct(
                    file: $filePath,
                    line: $stmt->getStartLine(),
                    reason: 'Control flow construct (If_) in Blueprint closure evaluated tentatively.'
                );
                $this->processClosureStatements($stmt->stmts, $blueprintVarName, $tableSchema, $filePath, $databaseSchema);
            } elseif (
                $stmt instanceof Stmt\Foreach_
                || $stmt instanceof Stmt\For_
                || $stmt instanceof Stmt\While_
                || $stmt instanceof Stmt\Switch_
            ) {
                $databaseSchema->unresolved[] = new UnresolvedConstruct(
                    file: $filePath,
                    line: $stmt->getStartLine(),
                    reason: 'Control flow construct ('.(new \ReflectionClass($stmt))->getShortName().') in Blueprint closure skipped.'
                );
            }
        }
    }

    /**
     * Process a method call chain on the Blueprint variable.
     */
    private function processBlueprintCallChain(
        Expr $expr,
        string $blueprintVarName,
        TableSchema $tableSchema,
        string $filePath,
        DatabaseSchema $databaseSchema
    ): void {
        $chain = [];
        $current = $expr;

        while ($current instanceof Expr\MethodCall) {
            $chain[] = $current;
            $current = $current->var;
        }

        if (! ($current instanceof Expr\Variable && $current->name === $blueprintVarName)) {
            // Not a call chain on the Blueprint variable (e.g. DB::table or helper call)
            return;
        }

        if (count($chain) === 0) {
            return;
        }

        // Descend to innermost call
        $chain = array_reverse($chain);
        $innermost = $chain[0];
        $modifiers = array_slice($chain, 1);

        if (! ($innermost->name instanceof Node\Identifier)) {
            $databaseSchema->unresolved[] = new UnresolvedConstruct(
                file: $filePath,
                line: $innermost->getStartLine(),
                reason: 'Dynamic method call on Blueprint variable cannot be resolved.'
            );

            return;
        }

        $methodName = $innermost->name->toString();
        $rawArgs = $innermost->args;

        // Try to resolve all arguments
        $resolvedArgs = [];
        $allArgsResolved = true;
        foreach ($rawArgs as $arg) {
            $val = null;
            if (AstHelper::tryResolveLiteral($arg->value, $val)) {
                $resolvedArgs[] = $val;
            } else {
                $allArgsResolved = false;
                break;
            }
        }

        // Handle dropColumn
        if ($methodName === 'dropColumn') {
            if (! $allArgsResolved || ! isset($resolvedArgs[0])) {
                $databaseSchema->unresolved[] = new UnresolvedConstruct(
                    file: $filePath,
                    line: $innermost->getStartLine(),
                    reason: 'Cannot statically resolve arguments for dropColumn.'
                );

                return;
            }

            $target = $resolvedArgs[0];
            if (is_string($target)) {
                unset($tableSchema->columns[$target]);
            } elseif (is_array($target)) {
                foreach ($target as $col) {
                    if (is_string($col)) {
                        unset($tableSchema->columns[$col]);
                    }
                }
            }

            return;
        }

        // Handle renameColumn
        if ($methodName === 'renameColumn') {
            if (! $allArgsResolved || ! isset($resolvedArgs[0], $resolvedArgs[1]) || ! is_string($resolvedArgs[0]) || ! is_string($resolvedArgs[1])) {
                $databaseSchema->unresolved[] = new UnresolvedConstruct(
                    file: $filePath,
                    line: $innermost->getStartLine(),
                    reason: 'Cannot statically resolve arguments for renameColumn.'
                );

                return;
            }

            $oldCol = $resolvedArgs[0];
            $newCol = $resolvedArgs[1];

            if (isset($tableSchema->columns[$oldCol])) {
                $existing = $tableSchema->columns[$oldCol];
                $existing->name = $newCol;
                unset($tableSchema->columns[$oldCol]);
                $tableSchema->columns[$newCol] = $existing;
            }

            return;
        }

        /** @var list<ColumnSchema> $columns */
        $columns = [];

        // Handle macros
        if (MacroExpander::isMacro($methodName)) {
            if (! $allArgsResolved) {
                $databaseSchema->unresolved[] = new UnresolvedConstruct(
                    file: $filePath,
                    line: $innermost->getStartLine(),
                    reason: "Cannot statically resolve arguments for macro '{$methodName}'."
                );

                return;
            }

            $expanded = MacroExpander::expand($methodName, $resolvedArgs);
            if ($expanded === null) {
                $databaseSchema->unresolved[] = new UnresolvedConstruct(
                    file: $filePath,
                    line: $innermost->getStartLine(),
                    reason: "Failed to expand macro '{$methodName}'."
                );

                return;
            }

            $columns = $expanded;
        } elseif (ColumnDefinitionTable::isStandardColumnMethod($methodName)) {
            if (! $allArgsResolved || ! isset($resolvedArgs[0]) || ! is_string($resolvedArgs[0])) {
                $databaseSchema->unresolved[] = new UnresolvedConstruct(
                    file: $filePath,
                    line: $innermost->getStartLine(),
                    reason: "Cannot statically resolve column name for '{$methodName}'."
                );

                return;
            }

            $colName = $resolvedArgs[0];
            $def = ColumnDefinitionTable::getDefinition($methodName);

            $length = $def['length'];
            $precision = $def['precision'];
            $scale = $def['scale'];
            $unsigned = $def['unsigned'];
            $allowed = null;
            $autoIncrement = $def['autoIncrement'] ?? false;

            if (in_array($methodName, ['string', 'char'], true) && isset($resolvedArgs[1]) && is_int($resolvedArgs[1])) {
                $length = $resolvedArgs[1];
            }

            if (in_array($methodName, ['decimal', 'unsignedDecimal'], true)) {
                if (isset($resolvedArgs[1]) && is_int($resolvedArgs[1])) {
                    $precision = $resolvedArgs[1];
                }
                if (isset($resolvedArgs[2]) && is_int($resolvedArgs[2])) {
                    $scale = $resolvedArgs[2];
                }
            }

            if (in_array($methodName, ['enum', 'set'], true) && isset($resolvedArgs[1]) && is_array($resolvedArgs[1])) {
                $allowed = array_values(array_filter($resolvedArgs[1], 'is_string'));
            }

            if (in_array($methodName, ['integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger'], true)) {
                if (isset($resolvedArgs[1]) && is_bool($resolvedArgs[1])) {
                    $autoIncrement = $resolvedArgs[1];
                }
                if (isset($resolvedArgs[2]) && is_bool($resolvedArgs[2])) {
                    $unsigned = $resolvedArgs[2];
                }
            }

            $columns = [
                new ColumnSchema(
                    name: $colName,
                    type: $def['type'],
                    blueprintMethod: $methodName,
                    length: $length,
                    precision: $precision,
                    scale: $scale,
                    unsigned: $unsigned,
                    allowed: $allowed,
                    autoIncrement: $autoIncrement,
                ),
            ];
        } else {
            // Unrecognized method (index, foreign constraint, or custom macro)
            // Indexes and foreign constraints are deferred per FR-003 and FSD A2.5
            return;
        }

        // Apply modifiers from innermost to outermost
        $isChange = false;

        foreach ($modifiers as $modifier) {
            if (! ($modifier->name instanceof Node\Identifier)) {
                continue;
            }

            $modName = $modifier->name->toString();
            $modArgs = [];
            $allModArgsResolved = true;
            foreach ($modifier->args as $mArg) {
                $mVal = null;
                if (AstHelper::tryResolveLiteral($mArg->value, $mVal)) {
                    $modArgs[] = $mVal;
                } else {
                    $allModArgsResolved = false;
                    break;
                }
            }

            switch ($modName) {
                case 'nullable':
                    $nullable = true;
                    if (isset($modArgs[0]) && is_bool($modArgs[0])) {
                        $nullable = $modArgs[0];
                    }
                    foreach ($columns as $col) {
                        $col->nullable = $nullable;
                    }
                    break;

                case 'default':
                    if ($allModArgsResolved && array_key_exists(0, $modArgs)) {
                        foreach ($columns as $col) {
                            $col->default = $modArgs[0];
                        }
                    } else {
                        $databaseSchema->unresolved[] = new UnresolvedConstruct(
                            file: $filePath,
                            line: $modifier->getStartLine(),
                            reason: 'Non-literal default value in modifier chain.'
                        );
                    }
                    break;

                case 'unsigned':
                    foreach ($columns as $col) {
                        $col->unsigned = true;
                    }
                    break;

                case 'comment':
                    if ($allModArgsResolved && isset($modArgs[0]) && is_string($modArgs[0])) {
                        foreach ($columns as $col) {
                            $col->comment = $modArgs[0];
                        }
                    }
                    break;

                case 'autoIncrement':
                    foreach ($columns as $col) {
                        $col->autoIncrement = true;
                    }
                    break;

                case 'change':
                    $isChange = true;
                    break;

                case 'length':
                    if ($allModArgsResolved && isset($modArgs[0]) && is_int($modArgs[0])) {
                        foreach ($columns as $col) {
                            $col->length = $modArgs[0];
                        }
                    }
                    break;

                default:
                    // Ignored modifiers (unique, index, after, invisible, charset, etc.)
                    break;
            }
        }

        // Apply column additions or mutations
        foreach ($columns as $column) {
            if ($isChange && isset($tableSchema->columns[$column->name])) {
                // Change redefines the column
                $tableSchema->columns[$column->name] = $column;
            } else {
                $tableSchema->columns[$column->name] = $column;
            }
        }
    }

    /**
     * Extract operation, connection, and arguments from a Schema call.
     *
     * @return array{connection: ?string, operation: string, args: list<Node\Arg>}|null
     */
    private function extractSchemaCall(Expr $expr): ?array
    {
        // Direct static call: Schema::create(...), Schema::table(...)
        if ($expr instanceof Expr\StaticCall) {
            if ($expr->class instanceof Node\Name && $this->isSchemaClass($expr->class->toString())) {
                if ($expr->name instanceof Node\Identifier) {
                    return [
                        'connection' => null,
                        'operation' => $expr->name->toString(),
                        'args' => $expr->getArgs(),
                    ];
                }
            }
        }

        // Chained method call: Schema::connection('x')->create(...)
        if ($expr instanceof Expr\MethodCall) {
            if ($expr->var instanceof Expr\StaticCall) {
                $subCall = $expr->var;
                if ($subCall->class instanceof Node\Name && $this->isSchemaClass($subCall->class->toString())) {
                    if ($subCall->name instanceof Node\Identifier && in_array($subCall->name->toString(), ['connection', 'setConnection'], true)) {
                        $connectionName = null;
                        $subArgs = $subCall->getArgs();
                        if (isset($subArgs[0])) {
                            AstHelper::tryResolveLiteral($subArgs[0]->value, $connectionName);
                        }

                        if ($expr->name instanceof Node\Identifier) {
                            return [
                                'connection' => is_string($connectionName) ? $connectionName : null,
                                'operation' => $expr->name->toString(),
                                'args' => $expr->getArgs(),
                            ];
                        }
                    }
                }
            }
        }

        return null;
    }

    private function isSchemaClass(string $className): bool
    {
        $base = AstHelper::classBasename($className);

        return $base === 'Schema';
    }
}
