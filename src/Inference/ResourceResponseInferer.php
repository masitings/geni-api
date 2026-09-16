<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Components;
use Geni\Inference\Document\Schema;
use Geni\SchemaReader\ColumnType;
use Geni\SchemaReader\DatabaseSchema;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Infers response schemas for actions returning JsonResources, ResourceCollections,
 * paginators, plain Eloquent models, backed enums, plain PHP DTOs, and response()->json() calls.
 *
 * Framework-free: php-parser + AST traversal only.
 */
final class ResourceResponseInferer
{
    private Parser $parser;

    private NodeFinder $finder;

    private ModelTableResolver $tableResolver;

    private BackedEnumParser $enumParser;

    private PhpDocAnnotationParser $phpDocParser;

    /** @var array<string, string> */
    private array $schemaOverrides = [];

    public function __construct(
        ?Parser $parser = null,
        ?NodeFinder $finder = null,
        ?ModelTableResolver $tableResolver = null,
        ?BackedEnumParser $enumParser = null,
        ?PhpDocAnnotationParser $phpDocParser = null,
        array $schemaOverrides = [],
    ) {
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
        $this->finder = $finder ?? new NodeFinder;
        $this->tableResolver = $tableResolver ?? new ModelTableResolver;
        $this->enumParser = $enumParser ?? new BackedEnumParser;
        $this->phpDocParser = $phpDocParser ?? new PhpDocAnnotationParser;
        $this->schemaOverrides = $schemaOverrides;
    }

    public function setSchemaOverrides(array $overrides): void
    {
        $this->schemaOverrides = $overrides;
    }

    /**
     * @param  array<string, string>  $modelTableOverrides
     * @return array{schema: Schema, diagnostics: list<InferenceDiagnostic>}
     */
    public function inferFromActionAst(
        ActionAst $actionAst,
        ?DatabaseSchema $schema = null,
        array $modelTableOverrides = [],
        ?Components $components = null
    ): array {
        /** @var list<Return_> */
        $returns = $this->finder->findInstanceOf($actionAst->node, Return_::class);

        foreach ($returns as $returnStmt) {
            $result = $this->inferFromReturnStatement($returnStmt, $actionAst, $schema, $modelTableOverrides, $components);
            if ($result['schema']->properties !== null || $result['schema']->ref !== null || $result['schema']->type !== null) {
                return $result;
            }
        }

        // Check declared return type on ClassMethod (e.g. `PostResource`, `LengthAwarePaginator`, `Post`)
        if ($actionAst->node instanceof ClassMethod && $actionAst->node->returnType instanceof Name) {
            $typeFqcn = $this->resolveNameToString($actionAst->node->returnType, $actionAst);
            if ($typeFqcn !== null) {
                return $this->inferFromTypeFqcn($typeFqcn, $actionAst, $schema, $modelTableOverrides, $components);
            }
        }

        return ['schema' => new Schema, 'diagnostics' => []];
    }

    /**
     * @param  array<string, string>  $modelTableOverrides
     * @return array{schema: Schema, diagnostics: list<InferenceDiagnostic>}
     */
    private function inferFromReturnStatement(
        Return_ $returnStmt,
        ActionAst $actionAst,
        ?DatabaseSchema $schema = null,
        array $modelTableOverrides = [],
        ?Components $components = null
    ): array {
        $value = $returnStmt->expr;
        if ($value === null) {
            return ['schema' => new Schema, 'diagnostics' => []];
        }

        // 1. response()->json([...]) or Response::json([...]) (FR-016)
        if ($this->isResponseJsonCall($value)) {
            return $this->inferFromResponseJsonCall($value, $actionAst);
        }

        // 2. Resource::collection(...) or SomeCollection::make(...) or DataClass::collect(...)
        if ($value instanceof StaticCall && $value->name instanceof Identifier) {
            $methodName = $value->name->toString();
            if ($methodName === 'collection') {
                return $this->inferFromResourceCollectionCall($value, $actionAst, $schema, $modelTableOverrides, $components);
            }
            if ($methodName === 'collect' && $value->class instanceof Name) {
                $dataFqcn = $this->resolveNameToString($value->class, $actionAst);
                if ($dataFqcn !== null) {
                    $detector = new LaravelDataDetector($this->parser, $this->finder);
                    if ($detector->isDataClass($dataFqcn)) {
                        $dataRes = $detector->inferSchemaFromDataClass($dataFqcn, $schema, $components);
                        $arraySchema = new Schema;
                        $arraySchema->type = 'array';
                        $arraySchema->items = $dataRes['schema'];

                        return ['schema' => $arraySchema, 'diagnostics' => $dataRes['diagnostics']];
                    }
                }
            }
            if ($methodName === 'from' && $value->class instanceof Name) {
                $dataFqcn = $this->resolveNameToString($value->class, $actionAst);
                if ($dataFqcn !== null) {
                    $detector = new LaravelDataDetector($this->parser, $this->finder);
                    if ($detector->isDataClass($dataFqcn)) {
                        return $detector->inferSchemaFromDataClass($dataFqcn, $schema, $components);
                    }
                }
            }
        }

        // 3. new SomeResource($model) OR SomeResource::make($model) (Phase 3 + FR-011)
        $resourceFqcn = null;
        if ($value instanceof New_ && $value->class instanceof Name) {
            $resourceFqcn = $this->resolveNameToString($value->class, $actionAst);
        } elseif ($value instanceof StaticCall && $value->name instanceof Identifier && $value->name->toString() === 'make' && $value->class instanceof Name) {
            $resourceFqcn = $this->resolveNameToString($value->class, $actionAst);
        }

        if ($resourceFqcn !== null) {
            // Check if it's a Spatie Data class
            $detector = new LaravelDataDetector($this->parser, $this->finder);
            if ($detector->isDataClass($resourceFqcn)) {
                return $detector->inferSchemaFromDataClass($resourceFqcn, $schema, $components);
            }

            // Check if it's a JsonResource
            if ($this->resourceExtendsJsonResource($resourceFqcn)) {
                return $this->inferSingleResourceResponse($resourceFqcn, $actionAst, $schema, $modelTableOverrides, $components);
            }

            // Check if it's an Eloquent model
            if ($this->isModelClass($resourceFqcn)) {
                return $this->inferModelResponse($resourceFqcn, $schema, $components);
            }

            // Check if it's a plain PHP DTO
            return $this->inferPlainPhpObjectResponse($resourceFqcn, $components);
        }

        // 4. Backed enum return, e.g. UserStatus::Active (FR-014)
        if ($value instanceof ClassConstFetch && $value->class instanceof Name && $value->name instanceof Identifier) {
            $enumClass = $this->resolveNameToString($value->class, $actionAst);
            if ($enumClass !== null) {
                $enumCases = $this->enumParser->parseCases($enumClass);
                if ($enumCases['cases'] !== []) {
                    $enumSchema = new Schema;
                    $enumSchema->type = $enumCases['type'] ?? 'string';
                    $enumSchema->enum = $enumCases['cases'];

                    if ($components !== null) {
                        $shortName = substr(strrchr('\\'.$enumClass, '\\') ?: $enumClass, 1);
                        $components->addSchema($shortName, $enumSchema);
                        $refSchema = new Schema;
                        $refSchema->ref = '#/components/schemas/'.$shortName;

                        return ['schema' => $refSchema, 'diagnostics' => []];
                    }

                    return ['schema' => $enumSchema, 'diagnostics' => []];
                }
            }
        }

        // 5. Paginator return from AST method chain: e.g. Post::paginate() (FR-012)
        if ($value instanceof MethodCall && $value->name instanceof Identifier) {
            $methodName = $value->name->toString();
            if (in_array($methodName, ['paginate', 'simplePaginate', 'cursorPaginate'], true)) {
                return $this->inferPaginatorFromMethodCall($value, $actionAst, $schema, $components);
            }
        }

        return ['schema' => new Schema, 'diagnostics' => []];
    }

    private function isResponseJsonCall(Node $expr): bool
    {
        if ($expr instanceof MethodCall && $expr->name instanceof Identifier && $expr->name->toString() === 'json') {
            if ($expr->var instanceof FuncCall && $expr->var->name instanceof Name && $expr->var->name->toString() === 'response') {
                return true;
            }
            if ($expr->var instanceof StaticCall && $expr->var->class instanceof Name && str_ends_with($expr->var->class->toString(), 'Response')) {
                return true;
            }
        }

        return false;
    }

    private function inferFromResponseJsonCall(Node $expr, ActionAst $actionAst): array
    {
        /** @var MethodCall $expr */
        if (! isset($expr->args[0]) || ! ($expr->args[0]->value instanceof Array_)) {
            return [
                'schema' => new Schema,
                'diagnostics' => [
                    new InferenceDiagnostic($actionAst->file, $expr->getLine(), 'Non-literal argument passed to response()->json()'),
                ],
            ];
        }

        $properties = [];
        $diagnostics = [];
        foreach ($expr->args[0]->value->items as $item) {
            if ($item === null || ! ($item->key instanceof String_)) {
                continue;
            }
            $k = $item->key->value;
            $v = $item->value;

            $propSchema = new Schema;
            if ($v instanceof String_) {
                $propSchema->type = 'string';
            } elseif ($v instanceof Int_) {
                $propSchema->type = 'integer';
            } elseif ($v instanceof Float_) {
                $propSchema->type = 'number';
            } elseif ($v instanceof Node\Expr\ConstFetch) {
                $c = strtolower($v->name->toString());
                if ($c === 'true' || $c === 'false') {
                    $propSchema->type = 'boolean';
                }
            } elseif ($v instanceof Array_) {
                $propSchema->type = 'array';
            } else {
                $propSchema->type = 'string';
                $diagnostics[] = new InferenceDiagnostic($actionAst->file, $v->getLine(), sprintf('Could not resolve type for response()->json key "%s"', $k));
            }

            $properties[$k] = $propSchema;
        }

        $schema = new Schema;
        $schema->type = 'object';
        $schema->properties = $properties;

        return ['schema' => $schema, 'diagnostics' => $diagnostics];
    }

    /**
     * Handle Resource::collection($models) (FR-011, FR-012)
     */
    private function inferFromResourceCollectionCall(
        StaticCall $call,
        ActionAst $actionAst,
        ?DatabaseSchema $schema,
        array $modelTableOverrides,
        ?Components $components
    ): array {
        $resourceFqcn = $this->resolveNameToString($call->class, $actionAst);
        if ($resourceFqcn === null) {
            return ['schema' => new Schema, 'diagnostics' => []];
        }

        // Infer underlying item resource schema
        $itemResult = $this->inferSingleResourceResponse($resourceFqcn, $actionAst, $schema, $modelTableOverrides, $components);

        // Check if the argument is a paginator
        $isPaginator = false;
        $paginatorType = 'LengthAwarePaginator';
        if (isset($call->args[0])) {
            $argVal = $call->args[0]->value;
            if (($argVal instanceof MethodCall || $argVal instanceof StaticCall) && $argVal->name instanceof Identifier) {
                $mName = $argVal->name->toString();
                if ($mName === 'paginate') {
                    $isPaginator = true;
                    $paginatorType = 'LengthAwarePaginator';
                } elseif ($mName === 'simplePaginate') {
                    $isPaginator = true;
                    $paginatorType = 'Paginator';
                } elseif ($mName === 'cursorPaginate') {
                    $isPaginator = true;
                    $paginatorType = 'CursorPaginator';
                }
            }
        }

        if ($isPaginator) {
            $paginatorSchema = $this->buildPaginatorEnvelope($itemResult['schema'], $paginatorType);

            return ['schema' => $paginatorSchema, 'diagnostics' => $itemResult['diagnostics']];
        }

        // Otherwise: plain array of items
        $arraySchema = new Schema;
        $arraySchema->type = 'array';
        $arraySchema->items = $itemResult['schema'];

        return ['schema' => $arraySchema, 'diagnostics' => $itemResult['diagnostics']];
    }

    private function inferSingleResourceResponse(
        string $resourceFqcn,
        ActionAst $actionAst,
        ?DatabaseSchema $schema,
        array $modelTableOverrides,
        ?Components $components
    ): array {
        $shortName = substr(strrchr('\\'.$resourceFqcn, '\\') ?: $resourceFqcn, 1);

        $parseResult = $this->parseResourceToArrayReturn($resourceFqcn);
        if ($parseResult['arrayNode'] === null) {
            return [
                'schema' => new Schema,
                'diagnostics' => [
                    new InferenceDiagnostic($actionAst->file, 1, sprintf('Could not find toArray() in resource %s', $resourceFqcn)),
                ],
            ];
        }

        $modelFqcn = $this->resolveModelFromResourceClass($resourceFqcn, $actionAst);
        $modelSource = $this->resolveClassSource($modelFqcn ?? '', $modelTableOverrides[$modelFqcn ?? ''] ?? null);
        $tableName = $this->tableResolver->resolve($modelFqcn ?? '', $modelSource);

        $resourceSchemaResult = $this->buildSchemaFromResourceArray(
            $parseResult['arrayNode'],
            $parseResult['file'],
            $modelFqcn,
            $tableName,
            $schema,
            []
        );

        $resourceSchema = $resourceSchemaResult['schema'];

        if ($components !== null) {
            $components->addSchema($shortName, $resourceSchema);
            $refSchema = new Schema;
            $refSchema->ref = '#/components/schemas/'.$shortName;

            return ['schema' => $refSchema, 'diagnostics' => $resourceSchemaResult['diagnostics']];
        }

        return $resourceSchemaResult;
    }

    private function inferModelResponse(string $modelFqcn, ?DatabaseSchema $schema, ?Components $components): array
    {
        $shortName = substr(strrchr('\\'.$modelFqcn, '\\') ?: $modelFqcn, 1);
        $modelSource = $this->resolveClassSource($modelFqcn);
        $tableName = $this->tableResolver->resolve($modelFqcn, $modelSource);

        $properties = [];
        if ($schema !== null) {
            $table = $schema->tables[$tableName] ?? null;
            if ($table === null) {
                foreach ($schema->tables as $k => $t) {
                    if ($t->name === $tableName || str_ends_with($k, '.'.$tableName)) {
                        $table = $t;
                        break;
                    }
                }
            }
            if ($table !== null) {
                foreach ($table->columns as $colName => $col) {
                    $prop = new Schema;
                    [$type, $format] = $this->columnTypeToOpenApi($col->type);
                    $prop->type = $type;
                    if ($format !== null) {
                        $prop->format = $format;
                    }
                    if ($col->nullable) {
                        $prop->type = is_array($prop->type) ? array_merge($prop->type, ['null']) : [$prop->type, 'null'];
                    }
                    $properties[$colName] = $prop;
                }
            }
        }

        $modelSchema = new Schema;
        $modelSchema->type = 'object';
        $modelSchema->properties = $properties;

        if ($components !== null) {
            $components->addSchema($shortName, $modelSchema);
            $refSchema = new Schema;
            $refSchema->ref = '#/components/schemas/'.$shortName;

            return ['schema' => $refSchema, 'diagnostics' => []];
        }

        return ['schema' => $modelSchema, 'diagnostics' => []];
    }

    private function inferPlainPhpObjectResponse(string $classFqcn, ?Components $components): array
    {
        $file = $this->resolveClassFile($classFqcn);
        if ($file === null || ! is_file($file)) {
            return ['schema' => new Schema, 'diagnostics' => []];
        }

        $code = file_get_contents($file);
        if ($code === false) {
            return ['schema' => new Schema, 'diagnostics' => []];
        }

        try {
            $stmts = $this->parser->parse($code);
            if ($stmts === null) {
                return ['schema' => new Schema, 'diagnostics' => []];
            }

            /** @var Class_|null $class */
            $class = $this->finder->findFirstInstanceOf($stmts, Class_::class);
            if ($class === null) {
                return ['schema' => new Schema, 'diagnostics' => []];
            }

            $properties = [];
            foreach ($class->stmts as $stmt) {
                if ($stmt instanceof Property && $stmt->isPublic()) {
                    foreach ($stmt->props as $p) {
                        $propSchema = new Schema;
                        $typeName = $stmt->type ? $stmt->type->toString() : 'string';
                        $propSchema->type = match ($typeName) {
                            'int', 'integer' => 'integer',
                            'float' => 'number',
                            'bool', 'boolean' => 'boolean',
                            'array' => 'array',
                            default => 'string',
                        };
                        $properties[$p->name->toString()] = $propSchema;
                    }
                }
            }

            $dtoSchema = new Schema;
            $dtoSchema->type = 'object';
            $dtoSchema->properties = $properties;

            if ($components !== null) {
                $shortName = substr(strrchr('\\'.$classFqcn, '\\') ?: $classFqcn, 1);
                $components->addSchema($shortName, $dtoSchema);
                $refSchema = new Schema;
                $refSchema->ref = '#/components/schemas/'.$shortName;

                return ['schema' => $refSchema, 'diagnostics' => []];
            }

            return ['schema' => $dtoSchema, 'diagnostics' => []];
        } catch (\Throwable) {
            return ['schema' => new Schema, 'diagnostics' => []];
        }
    }

    private function inferPaginatorFromMethodCall(MethodCall $call, ActionAst $actionAst, ?DatabaseSchema $schema, ?Components $components): array
    {
        // Try inferring item model from caller e.g. Post::paginate()
        $itemSchema = new Schema;
        $itemSchema->type = 'object';

        if ($call->var instanceof StaticCall && $call->var->class instanceof Name) {
            $modelFqcn = $this->resolveNameToString($call->var->class, $actionAst);
            if ($modelFqcn !== null && $this->isModelClass($modelFqcn)) {
                $modelRes = $this->inferModelResponse($modelFqcn, $schema, $components);
                $itemSchema = $modelRes['schema'];
            }
        }

        $type = match ($call->name->toString()) {
            'cursorPaginate' => 'CursorPaginator',
            'simplePaginate' => 'Paginator',
            default => 'LengthAwarePaginator',
        };

        return ['schema' => $this->buildPaginatorEnvelope($itemSchema, $type), 'diagnostics' => []];
    }

    private function schemaWithType($type): Schema
    {
        $s = new Schema;
        $s->type = $type;

        return $s;
    }

    private function buildPaginatorEnvelope(Schema $itemSchema, string $paginatorType): Schema
    {
        $envelope = new Schema;
        $envelope->type = 'object';

        $dataSchema = new Schema;
        $dataSchema->type = 'array';
        $dataSchema->items = $itemSchema;

        if ($paginatorType === 'CursorPaginator') {
            $envelope->properties = [
                'data' => $dataSchema,
                'path' => $this->schemaWithType('string'),
                'per_page' => $this->schemaWithType('integer'),
                'next_cursor' => $this->schemaWithType(['string', 'null']),
                'next_page_url' => $this->schemaWithType(['string', 'null']),
                'prev_cursor' => $this->schemaWithType(['string', 'null']),
                'prev_page_url' => $this->schemaWithType(['string', 'null']),
            ];

            return $envelope;
        }

        if ($paginatorType === 'Paginator') {
            $envelope->properties = [
                'data' => $dataSchema,
                'current_page' => $this->schemaWithType('integer'),
                'current_page_url' => $this->schemaWithType('string'),
                'first_page_url' => $this->schemaWithType('string'),
                'from' => $this->schemaWithType(['integer', 'null']),
                'next_page_url' => $this->schemaWithType(['string', 'null']),
                'path' => $this->schemaWithType('string'),
                'per_page' => $this->schemaWithType('integer'),
                'prev_page_url' => $this->schemaWithType(['string', 'null']),
                'to' => $this->schemaWithType(['integer', 'null']),
            ];

            return $envelope;
        }

        // LengthAwarePaginator
        $linksItemSchema = new Schema;
        $linksItemSchema->type = 'object';
        $linksItemSchema->properties = [
            'url' => $this->schemaWithType(['string', 'null']),
            'label' => $this->schemaWithType('string'),
            'active' => $this->schemaWithType('boolean'),
        ];

        $linksArray = new Schema;
        $linksArray->type = 'array';
        $linksArray->items = $linksItemSchema;

        $envelope->properties = [
            'current_page' => $this->schemaWithType('integer'),
            'data' => $dataSchema,
            'first_page_url' => $this->schemaWithType('string'),
            'from' => $this->schemaWithType(['integer', 'null']),
            'last_page' => $this->schemaWithType('integer'),
            'last_page_url' => $this->schemaWithType('string'),
            'links' => $linksArray,
            'next_page_url' => $this->schemaWithType(['string', 'null']),
            'path' => $this->schemaWithType('string'),
            'per_page' => $this->schemaWithType('integer'),
            'prev_page_url' => $this->schemaWithType(['string', 'null']),
            'to' => $this->schemaWithType(['integer', 'null']),
            'total' => $this->schemaWithType('integer'),
        ];

        return $envelope;
    }

    private function inferFromTypeFqcn(
        string $typeFqcn,
        ActionAst $actionAst,
        ?DatabaseSchema $schema,
        array $modelTableOverrides,
        ?Components $components
    ): array {
        $detector = new LaravelDataDetector($this->parser, $this->finder);
        if ($detector->isDataClass($typeFqcn)) {
            return $detector->inferSchemaFromDataClass($typeFqcn, $schema, $components);
        }

        if ($this->resourceExtendsJsonResource($typeFqcn)) {
            return $this->inferSingleResourceResponse($typeFqcn, $actionAst, $schema, $modelTableOverrides, $components);
        }

        if ($this->isModelClass($typeFqcn)) {
            return $this->inferModelResponse($typeFqcn, $schema, $components);
        }

        return ['schema' => new Schema, 'diagnostics' => []];
    }

    private function isModelClass(string $className): bool
    {
        if (str_contains($className, '\\Models\\') || is_subclass_of($className, 'Illuminate\Database\Eloquent\Model')) {
            return true;
        }

        $file = $this->resolveClassFile($className);
        if ($file === null || ! is_file($file)) {
            return false;
        }

        $code = file_get_contents($file);
        if ($code === false) {
            return false;
        }

        try {
            $stmts = $this->parser->parse($code);
            if ($stmts === null) {
                return false;
            }
            /** @var Class_|null $c */
            $c = $this->finder->findFirstInstanceOf($stmts, Class_::class);

            return $c !== null && $c->extends !== null && (str_ends_with($c->extends->toString(), 'Model') || $c->extends->toString() === 'Model');
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnTypeToOpenApi(ColumnType $type): array
    {
        return match ($type) {
            ColumnType::Integer => ['integer', null],
            ColumnType::Float, ColumnType::Decimal => ['number', null],
            ColumnType::Boolean => ['boolean', null],
            ColumnType::Uuid => ['string', 'uuid'],
            ColumnType::Ulid => ['string', 'ulid'],
            ColumnType::Date => ['string', 'date'],
            ColumnType::DateTime => ['string', 'date-time'],
            ColumnType::Time => ['string', 'time'],
            ColumnType::Json => ['object', null],
            default => ['string', null],
        };
    }

    private function resolveNameToString(Name $expr, ActionAst $actionAst): ?string
    {
        if ($expr->isFullyQualified()) {
            return ltrim($expr->toString(), '\\');
        }

        $parts = $expr->getParts();

        if (isset($actionAst->useImports[$parts[0]])) {
            $parts[0] = $actionAst->useImports[$parts[0]];

            return implode('\\', $parts);
        }

        if (! empty($actionAst->namespace)) {
            return $actionAst->namespace.'\\'.implode('\\', $parts);
        }

        return implode('\\', $parts);
    }

    private function resourceExtendsJsonResource(string $resourceFqcn): bool
    {
        $file = $this->resolveClassFile($resourceFqcn);
        if ($file === null) {
            return false;
        }

        $stmts = $this->parseFile($file);
        if ($stmts === null) {
            return false;
        }

        $class = $this->finder->findFirstInstanceOf($stmts, Class_::class);
        if ($class === null || $class->extends === null) {
            return false;
        }

        $parent = $class->extends->toString();

        return $parent === 'Illuminate\Http\Resources\Json\JsonResource'
            || $parent === 'JsonResource'
            || str_ends_with($parent, 'JsonResource')
            || str_ends_with($parent, 'ResourceCollection');
    }

    private function parseResourceToArrayReturn(string $resourceFqcn): array
    {
        $file = $this->resolveClassFile($resourceFqcn);
        if ($file === null) {
            return ['arrayNode' => null, 'file' => ''];
        }

        $stmts = $this->parseFile($file);
        if ($stmts === null) {
            return ['arrayNode' => null, 'file' => $file];
        }

        $class = $this->finder->findFirstInstanceOf($stmts, Class_::class);
        if ($class === null) {
            return ['arrayNode' => null, 'file' => $file];
        }

        $toArrayMethod = $this->finder->findFirst($class->stmts ?? [], function (Node $node) {
            return $node instanceof ClassMethod && $node->name->toString() === 'toArray';
        });

        if ($toArrayMethod === null) {
            return ['arrayNode' => null, 'file' => $file];
        }

        /** @var ClassMethod $toArrayMethod */
        $returns = $this->finder->findInstanceOf($toArrayMethod->stmts ?? [], Return_::class);
        foreach ($returns as $return) {
            if ($return->expr instanceof Array_) {
                return ['arrayNode' => $return->expr, 'file' => $file];
            }
        }

        return ['arrayNode' => null, 'file' => $file];
    }

    private function resolveModelFromResourceClass(string $resourceFqcn, ActionAst $actionAst): ?string
    {
        // 1. Check Resource class docblock for @mixin or @property (FR-009 / US-006)
        $resourceFile = $this->resolveClassFile($resourceFqcn);
        if ($resourceFile !== null && is_file($resourceFile)) {
            $code = file_get_contents($resourceFile);
            if ($code !== false) {
                $doc = $this->phpDocParser->parseClassDocblock($code);
                if (isset($doc['backingModel'])) {
                    $m = $doc['backingModel'];
                    if (class_exists($m)) {
                        return $m;
                    }
                    if (class_exists('App\\Models\\'.$m)) {
                        return 'App\\Models\\'.$m;
                    }
                }
            }
        }

        $parts = explode('\\', $resourceFqcn);
        $shortName = end($parts);

        if (str_ends_with($shortName, 'Resource')) {
            $modelShort = substr($shortName, 0, -strlen('Resource'));
        } elseif (str_ends_with($shortName, 'Collection')) {
            $modelShort = substr($shortName, 0, -strlen('Collection'));
        } else {
            $modelShort = $shortName;
        }

        if ($modelShort === '') {
            return null;
        }

        $namespaceParts = array_slice($parts, 0, -1);
        $ns = implode('\\', $namespaceParts);
        $modelNs = preg_replace('/(Http\\\\)?Resources$/', 'Models', $ns);
        $candidate = $modelNs.'\\'.$modelShort;
        if (class_exists($candidate)) {
            return $candidate;
        }

        if (isset($actionAst->useImports[$modelShort])) {
            return $actionAst->useImports[$modelShort];
        }

        return 'App\\Models\\'.$modelShort;
    }

    private function resolveClassSource(string $className, ?string $filePath = null): ?string
    {
        $file = $filePath ?? $this->resolveClassFile($className);
        if ($file === null || ! is_file($file)) {
            return null;
        }

        try {
            $code = file_get_contents($file);

            return $code === false ? null : $code;
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildSchemaFromResourceArray(
        Array_ $arrayNode,
        string $resourceFile,
        ?string $modelFqcn,
        string $tableName,
        ?DatabaseSchema $schema,
        array $diagnostics
    ): array {
        $properties = [];

        foreach ($arrayNode->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->key === null) {
                $diagnostics[] = new InferenceDiagnostic(
                    $resourceFile ?: 'unknown',
                    $item->getLine(),
                    'Array item without string key in JsonResource::toArray(): skipped'
                );

                continue;
            }

            if (! ($item->key instanceof String_)) {
                $diagnostics[] = new InferenceDiagnostic(
                    $resourceFile ?: 'unknown',
                    $item->key->getLine(),
                    'Non-string field key in JsonResource::toArray(): skipped'
                );

                continue;
            }

            $fieldName = $item->key->value;
            $value = $item->value;

            [$type, $format] = $this->inferFieldTypeFromValue($value, $modelFqcn, $tableName, $schema);

            if ($type === null && $format === null) {
                $diagnostics[] = new InferenceDiagnostic(
                    $resourceFile ?: 'unknown',
                    $item->getLine(),
                    sprintf('Could not resolve type for JsonResource::toArray() key "%s": marking unresolved', $fieldName)
                );
                $type = 'string';
            }

            $fieldSchema = new Schema;
            $fieldSchema->type = $type;
            if ($format !== null) {
                $fieldSchema->format = $format;
            }

            $properties[$fieldName] = $fieldSchema;
        }

        $objectSchema = new Schema;
        $objectSchema->type = 'object';
        $objectSchema->properties = $properties;

        return ['schema' => $objectSchema, 'diagnostics' => $diagnostics];
    }

    private function inferFieldTypeFromValue(
        Node $value,
        ?string $modelFqcn,
        string $tableName,
        ?DatabaseSchema $schema
    ): array {
        $propertyName = null;

        if ($value instanceof PropertyFetch) {
            $var = $value->var;
            $prop = $value->name;

            if ($var instanceof Variable && $var->name === 'this') {
                $propertyName = is_string($prop) ? $prop : ($prop instanceof Identifier ? $prop->toString() : null);
            } elseif ($var instanceof PropertyFetch
                && $var->var instanceof Variable && $var->var->name === 'this'
                && $var->name instanceof Identifier && $var->name->toString() === 'resource'
            ) {
                $propertyName = is_string($prop) ? $prop : ($prop instanceof Identifier ? $prop->toString() : null);
            }
        }

        if ($propertyName === null) {
            return [null, null];
        }

        if ($schema !== null) {
            $table = $schema->tables[$tableName] ?? null;
            if ($table === null) {
                foreach ($schema->tables as $k => $t) {
                    if ($t->name === $tableName || str_ends_with($k, '.'.$tableName)) {
                        $table = $t;
                        break;
                    }
                }
            }

            if ($table !== null && isset($table->columns[$propertyName])) {
                $col = $table->columns[$propertyName];

                return $this->columnTypeToOpenApi($col->type);
            }
        }

        // FR-005 / TASK-007: Consult schema_overrides escape hatch before falling back to string/unresolved
        $overrideKey = $tableName.'.'.$propertyName;
        if (isset($this->schemaOverrides[$overrideKey])) {
            return [$this->schemaOverrides[$overrideKey], null];
        }

        return [null, null];
    }

    private function resolveClassFile(string $className): ?string
    {
        if (class_exists($className)) {
            $file = (new \ReflectionClass($className))->getFileName();
            if ($file !== false && is_file($file)) {
                return $file;
            }
        }

        $relativePath = str_replace('\\', '/', $className).'.php';

        $possiblePaths = [
            __DIR__.'/../../../../src/'.$relativePath,
            __DIR__.'/../../../../app/'.$relativePath,
            $relativePath,
        ];

        foreach ($possiblePaths as $path) {
            if (is_file($path)) {
                return realpath($path);
            }
        }

        return null;
    }

    private function parseFile(string $filePath): ?array
    {
        try {
            $code = file_get_contents($filePath);
            if ($code === false) {
                return null;
            }

            return $this->parser->parse($code);
        } catch (\Throwable) {
            return null;
        }
    }
}
