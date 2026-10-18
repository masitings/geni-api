<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Components;
use Geni\Inference\Document\Schema;
use Geni\SchemaReader\DatabaseSchema;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
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

    private ModelSchemaResolver $models;

    private ResourceSchemaBuilder $resources;

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
        $this->models = new ModelSchemaResolver($this->parser, $this->finder, $this->tableResolver, $this->enumParser);
        $this->resources = new ResourceSchemaBuilder($this->models, $this->parser, $this->finder, $this->phpDocParser);
        $this->setSchemaOverrides($schemaOverrides);
    }

    public function setSchemaOverrides(array $overrides): void
    {
        $this->schemaOverrides = $overrides;
        $this->models->setSchemaOverrides($overrides);
    }

    /**
     * Response schema for a class named in `@response` / `#[Response]` or a
     * declared return type: resources are wrapped like Laravel sends them,
     * models are serialized, Data objects inferred.
     *
     * @return array{schema: Schema, diagnostics: list<InferenceDiagnostic>, description?: string}|null
     */
    public function inferForClass(string $class, ActionAst $actionAst, ?DatabaseSchema $schema, ?Components $components): ?array
    {
        $result = $this->inferFromTypeFqcn(ltrim($class, '\\'), $actionAst, $schema, [], $components);

        return $this->hasShape($result['schema']) ? $result : null;
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
            if ($this->hasShape($result['schema']) || isset($result['status'])) {
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
        ?Components $components = null,
        ?Array_ $additionalFromChain = null,
        bool $statusResolved = false,
    ): array {
        $value = $returnStmt->expr;
        if ($value === null) {
            return ['schema' => new Schema, 'diagnostics' => []];
        }

        // response()->noContent() → 204 without a body
        if ($value instanceof MethodCall && $value->name instanceof Identifier && $value->name->toString() === 'noContent'
            && $value->var instanceof FuncCall && $value->var->name instanceof Name && $value->var->name->toString() === 'response') {
            return ['schema' => new Schema, 'diagnostics' => [], 'status' => 204, 'description' => 'No content'];
        }

        // (new FooResource($x))->additional([...]), ->response(), ->setStatusCode(...)
        $additional = $additionalFromChain;
        $explicitStatus = null;
        while ($value instanceof MethodCall && $value->name instanceof Identifier
            && in_array($value->name->toString(), ['additional', 'response', 'setStatusCode', 'withResponse', 'header', 'withHeaders'], true)) {
            $firstArg = $value->args[0] ?? null;
            $firstValue = $firstArg instanceof Node\Arg ? $firstArg->value : null;
            if ($value->name->toString() === 'additional' && $firstValue instanceof Array_) {
                $additional = $firstValue;
            }
            if ($value->name->toString() === 'setStatusCode' && $firstValue instanceof Int_) {
                $explicitStatus = $firstValue->value;
            }
            $value = $value->var;
        }

        if (! $statusResolved) {
            $status = $explicitStatus ?? $this->inferStatus($value, $actionAst);
            $result = $this->inferFromReturnStatement(new Return_($value), $actionAst, $schema, $modelTableOverrides, $components, $additional, true);

            if ($status !== null && $status !== 200 && ! isset($result['status'])) {
                $result['status'] = $status;
            }

            return $result;
        }

        // return $user; where $user is a typed action parameter
        if ($value instanceof Variable && is_string($value->name) && $actionAst->node instanceof ClassMethod) {
            foreach ($actionAst->node->params as $param) {
                if ($param->var instanceof Variable && $param->var->name === $value->name && $param->type instanceof Name) {
                    $typeFqcn = $this->resolveNameToString($param->type, $actionAst);
                    if ($typeFqcn !== null) {
                        return $this->inferFromTypeFqcn($typeFqcn, $actionAst, $schema, $modelTableOverrides, $components, $additional);
                    }
                }
            }
        }

        // User::findOrFail($id), User::query()->where(...)->first(), User::all(), ...->get()
        $modelQuery = $this->inferModelQuery($value, $actionAst, $schema, $components);
        if ($modelQuery !== null) {
            return $modelQuery;
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

            // Check if it's a ResourceCollection subclass (new UserCollection($users))
            if ($this->resources->isResourceCollection($resourceFqcn)) {
                $itemResource = $this->resources->collectedResource($resourceFqcn);
                if ($itemResource !== null) {
                    $argument = $value instanceof New_ || $value instanceof StaticCall ? ($value->args[0] ?? null) : null;

                    return $this->resourceCollectionEnvelope($itemResource, $argument instanceof Node\Arg ? $argument->value : null, $schema, $components);
                }
            }

            // Check if it's a JsonResource
            if ($this->resources->isResource($resourceFqcn)) {
                return $this->inferSingleResourceResponse($resourceFqcn, $actionAst, $schema, $modelTableOverrides, $components, $additional);
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
        $resourceFqcn = $call->class instanceof Name ? $this->resolveNameToString($call->class, $actionAst) : null;
        if ($resourceFqcn === null) {
            return ['schema' => new Schema, 'diagnostics' => []];
        }

        $argument = $call->args[0] ?? null;

        return $this->resourceCollectionEnvelope($resourceFqcn, $argument instanceof Node\Arg ? $argument->value : null, $schema, $components);
    }

    /**
     * `{data: [...]}` for a plain collection, `{data, links, meta}` when the
     * collected value is a paginator, exactly as Laravel serializes it.
     *
     * @return array{schema: Schema, diagnostics: list<InferenceDiagnostic>, description: string}
     */
    private function resourceCollectionEnvelope(string $resourceFqcn, ?Node $argument, ?DatabaseSchema $schema, ?Components $components): array
    {
        $items = Schema::ofType('array');
        $items->items = $this->resources->reference($resourceFqcn, $schema, $components);
        $diagnostics = $this->resources->takeDiagnostics();
        $name = $this->models->shortName($resourceFqcn);

        $paginator = null;
        if (($argument instanceof MethodCall || $argument instanceof StaticCall) && $argument->name instanceof Identifier) {
            $paginator = match ($argument->name->toString()) {
                'paginate' => 'LengthAwarePaginator',
                'simplePaginate' => 'Paginator',
                'cursorPaginate' => 'CursorPaginator',
                default => null,
            };
        }

        $envelope = Schema::ofType('object');

        if ($paginator === null) {
            $envelope->properties = ['data' => $items];
            $envelope->required = ['data'];

            return ['schema' => $envelope, 'diagnostics' => $diagnostics, 'description' => sprintf('Array of `%s`', $name)];
        }

        $links = Schema::ofType('object');
        $links->properties = [
            'first' => Schema::ofType(['string', 'null']),
            'last' => Schema::ofType(['string', 'null']),
            'prev' => Schema::ofType(['string', 'null']),
            'next' => Schema::ofType(['string', 'null']),
        ];
        $links->required = ['first', 'last', 'prev', 'next'];

        $envelope->properties = ['data' => $items, 'links' => $links, 'meta' => $this->paginationMeta($paginator)];
        $envelope->required = ['data', 'links', 'meta'];

        return ['schema' => $envelope, 'diagnostics' => $diagnostics, 'description' => sprintf('Paginated set of `%s`', $name)];
    }

    /**
     * The `meta` block of a paginated resource response: the paginator's
     * `toArray()` minus `data` and the `*_url` keys (those go to `links`).
     */
    private function paginationMeta(string $paginator): Schema
    {
        $meta = Schema::ofType('object');

        $pageLink = Schema::ofType('object');
        $pageLink->properties = [
            'url' => Schema::ofType(['string', 'null']),
            'label' => Schema::ofType('string'),
            'active' => Schema::ofType('boolean'),
        ];
        $pageLink->required = ['url', 'label', 'active'];
        $pageLinks = Schema::ofType('array');
        $pageLinks->items = $pageLink;

        $field = function ($type, ?string $description = null, ?int $minimum = null): Schema {
            $schema = Schema::ofType($type);
            $schema->description = $description;
            $schema->minimum = $minimum;

            return $schema;
        };
        $pageLinks->description = 'Generated paginator links.';
        $path = $field(['string', 'null'], 'Base path for paginator generated URLs.');
        $perPage = $field('integer', 'Number of items shown per page.', 0);

        $meta->properties = match ($paginator) {
            'CursorPaginator' => [
                'path' => $path,
                'per_page' => $perPage,
                'next_cursor' => $field(['string', 'null'], 'The "cursor" that points to the next set of items.'),
                'prev_cursor' => $field(['string', 'null'], 'The "cursor" that points to the previous set of items.'),
            ],
            'Paginator' => [
                'current_page' => $field('integer', null, 1),
                'current_page_url' => $field('string'),
                'from' => $field(['integer', 'null'], null, 1),
                'path' => $path,
                'per_page' => $perPage,
                'to' => $field(['integer', 'null'], 'Number of the last item in the slice.', 1),
            ],
            default => [
                'current_page' => $field('integer', null, 1),
                'from' => $field(['integer', 'null'], null, 1),
                'last_page' => $field('integer', null, 1),
                'links' => $pageLinks,
                'path' => $path,
                'per_page' => $perPage,
                'to' => $field(['integer', 'null'], 'Number of the last item in the slice.', 1),
                'total' => $field('integer', 'Total number of items being paginated.', 0),
            ],
        };
        $meta->required = array_keys($meta->properties);

        return $meta;
    }

    /**
     * A single resource, wrapped the way Laravel sends it: `{data: {...}}`
     * (or the resource's `$wrap` key) plus `with()` / `additional()` keys.
     *
     * @return array{schema: Schema, diagnostics: list<InferenceDiagnostic>, description: string}
     */
    private function inferSingleResourceResponse(
        string $resourceFqcn,
        ActionAst $actionAst,
        ?DatabaseSchema $schema,
        array $modelTableOverrides,
        ?Components $components,
        ?Array_ $additional = null
    ): array {
        $reference = $this->resources->reference($resourceFqcn, $schema, $components);
        $description = sprintf('`%s`', $this->models->shortName($resourceFqcn));
        $wrapKey = $this->resources->wrapKey($resourceFqcn);

        if ($wrapKey === null) {
            return ['schema' => $reference, 'diagnostics' => $this->resources->takeDiagnostics(), 'description' => $description];
        }

        $envelope = Schema::ofType('object');
        $envelope->properties = [$wrapKey => $reference];
        $envelope->required = [$wrapKey];

        $with = $this->resources->withProperties($resourceFqcn, $schema, $components);
        $envelope->properties = array_merge($envelope->properties, $with['properties']);
        $envelope->required = array_merge($envelope->required, $with['required']);

        if ($additional !== null) {
            $extra = $this->resources->literalObject($additional, $actionAst->file, $schema, $components);
            $envelope->properties = array_merge($envelope->properties, $extra->properties ?? []);
            $envelope->required = array_merge($envelope->required, $extra->required ?? []);
        }

        $envelope->required = array_values(array_unique($envelope->required));

        return ['schema' => $envelope, 'diagnostics' => $this->resources->takeDiagnostics(), 'description' => $description];
    }

    /**
     * @return array{schema: Schema, diagnostics: list<InferenceDiagnostic>, description: string}
     */
    private function inferModelResponse(string $modelFqcn, ?DatabaseSchema $schema, ?Components $components): array
    {
        $modelSchema = $components !== null
            ? $this->models->reference($modelFqcn, $schema, $components)
            : $this->models->serializedSchema($modelFqcn, $schema, null);

        return ['schema' => $modelSchema, 'diagnostics' => [], 'description' => sprintf('`%s`', $this->models->shortName($modelFqcn))];
    }

    /**
     * `User::findOrFail($id)`, `User::query()->where(...)->first()` → the model;
     * `User::all()`, `User::where(...)->get()` → an array of models.
     *
     * @return array{schema: Schema, diagnostics: list<InferenceDiagnostic>, description: string}|null
     */
    private function inferModelQuery(Node $value, ActionAst $actionAst, ?DatabaseSchema $schema, ?Components $components): ?array
    {
        if (! ($value instanceof MethodCall || $value instanceof StaticCall) || ! $value->name instanceof Identifier) {
            return null;
        }

        $terminal = $value->name->toString();
        $single = ['find', 'findOrFail', 'first', 'firstOrFail', 'sole', 'create', 'firstOrCreate', 'updateOrCreate', 'firstOrNew', 'forceCreate'];
        $many = ['get', 'all'];

        if (! in_array($terminal, $single, true) && ! in_array($terminal, $many, true)) {
            return null;
        }

        $root = $value;
        while ($root instanceof MethodCall) {
            $root = $root->var;
        }

        if (! $root instanceof StaticCall || ! $root->class instanceof Name) {
            return null;
        }

        $modelFqcn = $this->resolveNameToString($root->class, $actionAst);
        if ($modelFqcn === null || ! $this->isModelClass($modelFqcn)) {
            return null;
        }

        $result = $this->inferModelResponse($modelFqcn, $schema, $components);

        if (in_array($terminal, $many, true)) {
            $array = Schema::ofType('array');
            $array->items = $result['schema'];
            $result['schema'] = $array;
            $result['description'] = sprintf('Array of `%s`', $this->models->shortName($modelFqcn));
        }

        return $result;
    }

    /**
     * Status code of the returned value when it is not 200:
     * `response()->json($data, 201)`, or a resource wrapping a model that was
     * just created (`Model::create()`, `->create()` on a relation, `new Model`
     * + `->save()`), which Laravel sends as 201.
     */
    private function inferStatus(Node $value, ActionAst $actionAst): ?int
    {
        if ($this->isResponseJsonCall($value) && $value instanceof MethodCall) {
            $statusArg = $value->args[1] ?? null;

            return $statusArg instanceof Node\Arg && $statusArg->value instanceof Int_ ? $statusArg->value->value : null;
        }

        $resourceArgument = null;
        if ($value instanceof New_ || ($value instanceof StaticCall && $value->name instanceof Identifier && $value->name->toString() === 'make')) {
            $argument = $value->args[0] ?? null;
            $resourceArgument = $argument instanceof Node\Arg ? $argument->value : null;
        }

        if ($resourceArgument === null) {
            return null;
        }

        if ($this->isCreateCall($resourceArgument)) {
            return 201;
        }

        if (! $resourceArgument instanceof Variable || ! is_string($resourceArgument->name)) {
            return null;
        }

        $variable = $resourceArgument->name;
        $assignedNew = false;

        foreach ($this->finder->findInstanceOf($actionAst->node, Node\Expr\Assign::class) as $assign) {
            if (! $assign->var instanceof Variable || $assign->var->name !== $variable) {
                continue;
            }
            if ($this->isCreateCall($assign->expr)) {
                return 201;
            }
            if ($assign->expr instanceof New_) {
                $assignedNew = true;
            }
        }

        if ($assignedNew) {
            $saves = $this->finder->find($actionAst->node, fn (Node $node) => $node instanceof MethodCall
                && $node->var instanceof Variable && $node->var->name === $variable
                && $node->name instanceof Identifier && $node->name->toString() === 'save');

            return $saves !== [] ? 201 : null;
        }

        return null;
    }

    private function isCreateCall(Node $expr): bool
    {
        return ($expr instanceof StaticCall || $expr instanceof MethodCall)
            && $expr->name instanceof Identifier
            && in_array($expr->name->toString(), ['create', 'forceCreate', 'createQuietly', 'forceCreateQuietly'], true);
    }

    private function hasShape(Schema $schema): bool
    {
        return $schema->properties !== null || $schema->ref !== null || $schema->type !== null || $schema->anyOf !== null;
    }

    private function inferPlainPhpObjectResponse(string $classFqcn, ?Components $components): array
    {
        $file = ClassFileLocator::locate($classFqcn);
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
        ?Components $components,
        ?Array_ $additional = null
    ): array {
        $detector = new LaravelDataDetector($this->parser, $this->finder);
        if ($detector->isDataClass($typeFqcn)) {
            return $detector->inferSchemaFromDataClass($typeFqcn, $schema, $components);
        }

        if ($this->resources->isResourceCollection($typeFqcn)) {
            $itemResource = $this->resources->collectedResource($typeFqcn);

            return $itemResource !== null
                ? $this->resourceCollectionEnvelope($itemResource, null, $schema, $components)
                : ['schema' => new Schema, 'diagnostics' => []];
        }

        if ($this->resources->isResource($typeFqcn)) {
            return $this->inferSingleResourceResponse($typeFqcn, $actionAst, $schema, $modelTableOverrides, $components, $additional);
        }

        if ($this->isModelClass($typeFqcn)) {
            return $this->inferModelResponse($typeFqcn, $schema, $components);
        }

        return ['schema' => new Schema, 'diagnostics' => []];
    }

    private function isModelClass(string $className): bool
    {
        return $this->models->isModel($className);
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
}
