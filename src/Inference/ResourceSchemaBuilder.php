<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Components;
use Geni\Inference\Document\Schema;
use Geni\SchemaReader\DatabaseSchema;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedString;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Builds the component schema of a JsonResource from its `toArray()` the
 * way Scramble does: literal values, casts, nested arrays, model attributes
 * (columns + casts), relations, nested resources and collections, and the
 * conditional helpers (`when*`, `merge*`) that make a key optional.
 *
 * @phpstan-import-type ModelInfo from ModelSchemaResolver
 *
 * @phpstan-type Context array{file: string, model: ModelInfo|null, schema: ?DatabaseSchema, components: ?Components}
 */
final class ResourceSchemaBuilder
{
    private const JSON_RESOURCE = 'Illuminate\Http\Resources\Json\JsonResource';

    private const RESOURCE_COLLECTION = 'Illuminate\Http\Resources\Json\ResourceCollection';

    /** Carbon / string helpers that always return a string. */
    private const STRING_METHODS = [
        'toIso8601String', 'toIso8601ZuluString', 'toDateTimeString', 'toDateString', 'toTimeString', 'toDateTimeLocalString',
        'toAtomString', 'toCookieString', 'toRfc822String', 'toRfc850String', 'toRfc1036String', 'toRfc1123String',
        'toRfc2822String', 'toRfc3339String', 'toRfc7231String', 'toRssString', 'toW3cString', 'toJSON', 'toFormattedDateString',
        'toDayDateTimeString', 'format', 'isoFormat', 'translatedFormat', 'diffForHumans', 'toString', 'value',
    ];

    private const STRING_FUNCTIONS = [
        'route', 'url', 'asset', 'secure_url', 'secure_asset', 'action', '__', 'trans', 'trans_choice', 'e', 'csrf_token',
        'strval', 'strtoupper', 'strtolower', 'ucfirst', 'lcfirst', 'ucwords', 'trim', 'ltrim', 'rtrim', 'implode', 'sprintf',
        'number_format', 'json_encode', 'date', 'substr', 'str_replace', 'str_pad', 'nl2br', 'htmlspecialchars', 'md5', 'sha1',
    ];

    private const INTEGER_FUNCTIONS = ['count', 'intval', 'strlen', 'mb_strlen', 'round', 'floor', 'ceil', 'time'];

    private const BOOLEAN_FUNCTIONS = ['boolval', 'is_null', 'is_array', 'is_string', 'is_int', 'is_numeric', 'in_array', 'array_key_exists', 'filled', 'blank'];

    private const ARRAY_FUNCTIONS = ['array_values', 'array_keys', 'array_map', 'array_filter', 'array_merge', 'array_unique', 'explode', 'array_column', 'array_slice', 'compact'];

    private Parser $parser;

    private NodeFinder $finder;

    private PhpDocAnnotationParser $phpDocParser;

    /** @var array<string, array{class: Class_, file: string, uses: array<string, string>, namespace: string}|null> */
    private array $parsedClasses = [];

    /** @var list<InferenceDiagnostic> */
    private array $diagnostics = [];

    public function __construct(
        private ModelSchemaResolver $models,
        ?Parser $parser = null,
        ?NodeFinder $finder = null,
        ?PhpDocAnnotationParser $phpDocParser = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
        $this->finder = $finder ?? new NodeFinder;
        $this->phpDocParser = $phpDocParser ?? new PhpDocAnnotationParser;
    }

    public function isResource(string $class): bool
    {
        return $this->extendsClass($class, self::JSON_RESOURCE);
    }

    public function isResourceCollection(string $class): bool
    {
        return $this->extendsClass($class, self::RESOURCE_COLLECTION);
    }

    /**
     * Diagnostics recorded since the last call, then cleared.
     *
     * @return list<InferenceDiagnostic>
     */
    public function takeDiagnostics(): array
    {
        $diagnostics = $this->diagnostics;
        $this->diagnostics = [];

        return $diagnostics;
    }

    /**
     * Register the resource as a component (once) and return a reference to it.
     * Without a component registry the full inline schema is returned.
     */
    public function reference(string $resourceClass, ?DatabaseSchema $schema, ?Components $components): Schema
    {
        $name = $this->models->shortName($resourceClass);

        if ($components === null) {
            return $this->build($resourceClass, $schema, null);
        }

        if (! isset($components->schemas[$name])) {
            // Placeholder first so self-referencing resources terminate.
            $components->schemas[$name] = ['type' => 'object'];
            $components->addSchema($name, $this->build($resourceClass, $schema, $components));
        }

        return Schema::ref($name);
    }

    /**
     * The key a single resource is wrapped in: `data` unless the resource
     * sets `public static $wrap` (null disables wrapping).
     */
    public function wrapKey(string $resourceClass): ?string
    {
        $parsed = $this->parseClass($resourceClass);

        foreach ($parsed === null ? [] : $parsed['class']->stmts as $stmt) {
            if ($stmt instanceof Property && $stmt->isStatic()) {
                foreach ($stmt->props as $prop) {
                    if ($prop->name->toString() !== 'wrap') {
                        continue;
                    }
                    if ($prop->default instanceof String_) {
                        return $prop->default->value;
                    }
                    if ($prop->default instanceof ConstFetch && $prop->default->name->toLowerString() === 'null') {
                        return null;
                    }
                }
            }
        }

        return 'data';
    }

    /**
     * Top-level keys added by the resource's `with()` method.
     *
     * @return array{properties: array<string, Schema>, required: list<string>}
     */
    public function withProperties(string $resourceClass, ?DatabaseSchema $schema, ?Components $components): array
    {
        $parsed = $this->parseClass($resourceClass);
        $method = $parsed !== null ? $this->findMethod($parsed['class'], 'with') : null;
        $return = $method !== null ? $this->firstReturnExpression($method) : null;

        if (! $return instanceof Array_) {
            return ['properties' => [], 'required' => []];
        }

        $object = $this->buildObject($return, ['file' => $parsed['file'], 'model' => null, 'schema' => $schema, 'components' => $components]);

        return ['properties' => $object->properties ?? [], 'required' => $object->required ?? []];
    }

    /**
     * Infer a literal array passed to `additional([...])` or similar.
     */
    public function literalObject(Array_ $array, string $file, ?DatabaseSchema $schema, ?Components $components): Schema
    {
        return $this->buildObject($array, ['file' => $file, 'model' => null, 'schema' => $schema, 'components' => $components]);
    }

    /**
     * The item resource a ResourceCollection subclass collects: `$collects`,
     * or the `UserCollection` -> `UserResource` naming convention.
     */
    public function collectedResource(string $collectionClass): ?string
    {
        $parsed = $this->parseClass($collectionClass);

        foreach ($parsed === null ? [] : $parsed['class']->stmts as $stmt) {
            if ($stmt instanceof Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop->name->toString() === 'collects' && $prop->default instanceof ClassConstFetch && $prop->default->class instanceof Name) {
                        return $prop->default->class->toString();
                    }
                }
            }
        }

        if (str_ends_with($collectionClass, 'Collection')) {
            $candidate = substr($collectionClass, 0, -10).'Resource';
            if (ClassFileLocator::locate($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function build(string $resourceClass, ?DatabaseSchema $schema, ?Components $components): Schema
    {
        $parsed = $this->parseClass($resourceClass);
        $context = [
            'file' => $parsed['file'] ?? '',
            'model' => null,
            'schema' => $schema,
            'components' => $components,
        ];

        $modelClass = $parsed !== null ? $this->modelFor($resourceClass, $parsed) : null;
        if ($modelClass !== null) {
            $context['model'] = $this->models->resolve($modelClass, $schema, $components);
        }

        $toArray = $parsed !== null ? $this->findMethod($parsed['class'], 'toArray') : null;

        if ($toArray === null) {
            // JsonResource::toArray() serializes the underlying model.
            return $modelClass !== null
                ? $this->models->serializedSchema($modelClass, $schema, $components)
                : Schema::ofType('object');
        }

        $return = $this->firstReturnExpression($toArray);

        if ($return instanceof Array_) {
            return $this->buildObject($return, $context);
        }

        if ($this->isParentToArray($return) && $modelClass !== null) {
            return $this->models->serializedSchema($modelClass, $schema, $components);
        }

        if ($return instanceof FuncCall && $return->name instanceof Name && $return->name->toLowerString() === 'array_merge') {
            $merged = Schema::ofType('object');
            $merged->properties = [];
            $merged->required = [];
            foreach ($return->args as $arg) {
                $part = null;
                if ($arg instanceof Arg && $arg->value instanceof Array_) {
                    $part = $this->buildObject($arg->value, $context);
                } elseif ($arg instanceof Arg && $this->isParentToArray($arg->value) && $modelClass !== null) {
                    $part = $this->models->serializedSchema($modelClass, $schema, $components);
                }
                if ($part !== null) {
                    $merged->properties = array_merge($merged->properties, $part->properties ?? []);
                    $merged->required = array_values(array_unique(array_merge($merged->required, $part->required ?? [])));
                }
            }

            return $merged;
        }

        $this->diagnose($context['file'], $toArray->getLine(), sprintf('Could not find a literal array returned by %s::toArray()', $resourceClass));

        return Schema::ofType('object');
    }

    /**
     * @param  Context  $context
     */
    private function buildObject(Array_ $array, array $context): Schema
    {
        $object = Schema::ofType('object');
        $properties = [];
        $required = [];

        foreach ($array->items as $item) {
            if ($item === null || $item->unpack) {
                continue;
            }

            if ($item->key === null) {
                $merged = $this->mergedProperties($item->value, $context);
                if ($merged === null) {
                    $this->diagnose($context['file'], $item->getLine(), 'Array item without string key in JsonResource::toArray(): skipped');

                    continue;
                }
                foreach ($merged['schema']->properties ?? [] as $name => $property) {
                    $properties[$name] = $property;
                    if (! $merged['optional'] && in_array($name, $merged['schema']->required ?? [], true)) {
                        $required[] = $name;
                    }
                }

                continue;
            }

            if (! $item->key instanceof String_) {
                $this->diagnose($context['file'], $item->key->getLine(), 'Non-string field key in JsonResource::toArray(): skipped');

                continue;
            }

            $name = $item->key->value;
            $inferred = $this->infer($item->value, $context);

            if ($inferred['schema'] === null) {
                $this->diagnose($context['file'], $item->getLine(), sprintf('Could not resolve type for JsonResource::toArray() key "%s": marking unresolved', $name));
                $inferred['schema'] = Schema::ofType('string');
            }

            $properties[$name] = $inferred['schema'];
            if (! $inferred['optional']) {
                $required[] = $name;
            }
        }

        $object->properties = $properties;
        $object->required = array_values(array_unique($required));

        return $object;
    }

    /**
     * `$this->merge([...])`, `$this->mergeWhen($cond, [...])`, `$this->mergeUnless($cond, [...])`.
     *
     * @param  Context  $context
     * @return array{schema: Schema, optional: bool}|null
     */
    private function mergedProperties(Node $value, array $context): ?array
    {
        if (! $value instanceof MethodCall || ! $this->isThis($value->var) || ! $value->name instanceof Identifier) {
            return null;
        }

        $method = $value->name->toString();
        $argIndex = match ($method) {
            'merge' => 0,
            'mergeWhen', 'mergeUnless' => 1,
            default => null,
        };

        $arg = $argIndex !== null ? $this->argValue($value, $argIndex) : null;
        $arg = $arg !== null ? $this->unwrapClosure($arg) : null;

        if (! $arg instanceof Array_) {
            return null;
        }

        return ['schema' => $this->buildObject($arg, $context), 'optional' => $method !== 'merge'];
    }

    /**
     * @param  Context  $context
     * @return array{schema: Schema|null, optional: bool}
     */
    private function infer(Node $expr, array $context): array
    {
        $resolved = fn (?Schema $schema, bool $optional = false): array => ['schema' => $schema, 'optional' => $optional];

        // Literals
        if ($expr instanceof String_) {
            return $resolved($this->constant('string', $expr->value));
        }
        if ($expr instanceof Int_) {
            return $resolved($this->constant('integer', $expr->value));
        }
        if ($expr instanceof Float_) {
            return $resolved($this->constant('number', $expr->value));
        }
        if ($expr instanceof InterpolatedString) {
            return $resolved(Schema::ofType('string'));
        }
        if ($expr instanceof ConstFetch) {
            return $resolved(match ($expr->name->toLowerString()) {
                'true', 'false' => Schema::ofType('boolean'),
                'null' => Schema::ofType('null'),
                default => null,
            });
        }

        if ($expr instanceof Cast) {
            return $resolved(match (true) {
                $expr instanceof Cast\Bool_ => Schema::ofType('boolean'),
                $expr instanceof Cast\Int_ => Schema::ofType('integer'),
                $expr instanceof Cast\Double => Schema::ofType('number'),
                $expr instanceof Cast\String_ => Schema::ofType('string'),
                $expr instanceof Cast\Array_ => Schema::ofType('array'),
                $expr instanceof Cast\Object_ => Schema::ofType('object'),
                default => null,
            });
        }

        if ($expr instanceof Array_) {
            return $resolved($this->inferArray($expr, $context));
        }

        if ($expr instanceof BinaryOp\Concat) {
            return $resolved(Schema::ofType('string'));
        }
        if ($expr instanceof BinaryOp\Coalesce) {
            $left = $this->infer($expr->left, $context)['schema'];
            $right = $this->infer($expr->right, $context)['schema'];

            return $resolved($left !== null && $right !== null && ! $right->isNullable() ? $this->withoutNull($left) : $left);
        }
        if ($expr instanceof BinaryOp\Plus || $expr instanceof BinaryOp\Minus || $expr instanceof BinaryOp\Mul || $expr instanceof BinaryOp\Mod) {
            $left = $this->infer($expr->left, $context)['schema'];
            $right = $this->infer($expr->right, $context)['schema'];
            $bothIntegers = $left?->type === 'integer' && $right?->type === 'integer';

            return $resolved(Schema::ofType($bothIntegers ? 'integer' : 'number'));
        }
        if ($expr instanceof BinaryOp\Div || $expr instanceof BinaryOp\Pow) {
            return $resolved(Schema::ofType('number'));
        }
        if ($expr instanceof BinaryOp || $expr instanceof BooleanNot || $expr instanceof Isset_ || $expr instanceof Empty_ || $expr instanceof Instanceof_) {
            return $resolved(Schema::ofType('boolean'));
        }

        if ($expr instanceof Ternary) {
            $if = $this->infer($expr->if ?? $expr->cond, $context)['schema'];
            $else = $this->infer($expr->else, $context)['schema'];

            return $resolved($this->union($if, $else));
        }

        if ($expr instanceof Match_) {
            $schema = null;
            foreach ($expr->arms as $arm) {
                $schema = $this->union($schema, $this->infer($arm->body, $context)['schema']);
            }

            return $resolved($schema);
        }

        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            return $resolved($this->inferFunction($expr->name->toLowerString()));
        }

        // Model attributes and relations: $this->name, $this->resource->name, $this?->name
        $attribute = $this->attributeName($expr);
        if ($attribute !== null) {
            return $resolved($this->attributeOrRelation($attribute, $context));
        }

        // Nested resources and collections
        if ($expr instanceof New_ || $expr instanceof StaticCall) {
            $nested = $this->inferNestedResource($expr, $context);
            if ($nested !== null) {
                return $nested;
            }
        }

        if ($expr instanceof ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier && $expr->name->toString() !== 'class') {
            return $resolved($this->enumCase($expr->class->toString(), $context));
        }

        if (($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) && $expr->name instanceof Identifier) {
            return $this->inferMethodCall($expr, $context);
        }

        return $resolved(null);
    }

    /**
     * @param  Context  $context
     * @return array{schema: Schema|null, optional: bool}
     */
    private function inferMethodCall(MethodCall|NullsafeMethodCall $call, array $context): array
    {
        /** @var Identifier $nameNode */
        $nameNode = $call->name;
        $method = $nameNode->toString();

        if ($this->isThis($call->var)) {
            $conditional = $this->inferConditional($method, $call, $context);
            if ($conditional !== null) {
                return $conditional;
            }

            // Calls on the resource are forwarded to the model.
            $returnType = $context['model']['methods'][$method] ?? null;

            return ['schema' => $returnType !== null ? Schema::ofType($returnType) : null, 'optional' => false];
        }

        $receiver = $this->infer($call->var, $context)['schema'];
        $nullable = $call instanceof NullsafeMethodCall && $receiver?->isNullable();

        $schema = match (true) {
            in_array($method, self::STRING_METHODS, true) => Schema::ofType('string'),
            in_array($method, ['count', 'sum', 'timestamp', 'getTimestamp'], true) => Schema::ofType('integer'),
            in_array($method, ['exists', 'isEmpty', 'isNotEmpty', 'contains', 'isPast', 'isFuture', 'isToday'], true) => Schema::ofType('boolean'),
            in_array($method, ['toArray', 'pluck', 'all', 'keys', 'values'], true) => Schema::ofType('array'),
            default => null,
        };

        return ['schema' => $schema !== null && $nullable ? $schema->nullable() : $schema, 'optional' => false];
    }

    /**
     * Laravel's conditional resource helpers; every one of them can drop the key.
     *
     * @param  Context  $context
     * @return array{schema: Schema|null, optional: bool}|null
     */
    private function inferConditional(string $method, MethodCall|NullsafeMethodCall $call, array $context): ?array
    {
        $valueOf = function (int $index) use ($call, $context): ?Schema {
            $value = $this->argValue($call, $index);

            return $value !== null ? $this->infer($this->unwrapClosure($value), $context)['schema'] : null;
        };

        $relationName = function () use ($call): ?string {
            $first = $this->argValue($call, 0);

            return $first instanceof String_ ? $first->value : null;
        };

        $schema = match ($method) {
            'when', 'unless' => $valueOf(1),
            'whenNotNull' => ($inner = $valueOf(0)) !== null ? $this->withoutNull($inner) : null,
            'whenNull' => Schema::ofType('null'),
            'whenLoaded' => $this->argValue($call, 1) !== null
                ? $valueOf(1)
                : (($relation = $relationName()) !== null ? $this->relationSchema($relation, $context) : null),
            'whenHas', 'whenAppended' => $this->argValue($call, 1) !== null
                ? $valueOf(1)
                : (($attribute = $relationName()) !== null ? $this->attributeOrRelation($attribute, $context) : null),
            'whenCounted' => $this->argValue($call, 1) !== null ? $valueOf(1) : Schema::ofType('integer'),
            'whenAggregated' => $this->argValue($call, 3) !== null ? $valueOf(3) : Schema::ofType('number'),
            'whenExistsLoaded' => $this->argValue($call, 1) !== null ? $valueOf(1) : Schema::ofType('boolean'),
            'whenPivotLoaded' => $valueOf(1),
            'whenPivotLoadedAs' => $valueOf(2),
            default => false,
        };

        if ($schema === false) {
            return null;
        }

        return ['schema' => $schema, 'optional' => true];
    }

    /**
     * `new FooResource(...)`, `FooResource::make(...)`, `FooResource::collection(...)`.
     *
     * @param  Context  $context
     * @return array{schema: Schema|null, optional: bool}|null
     */
    private function inferNestedResource(New_|StaticCall $expr, array $context): ?array
    {
        if (! $expr->class instanceof Name) {
            return null;
        }

        $class = $expr->class->toString();
        $isCollection = false;

        if ($expr instanceof StaticCall) {
            if (! $expr->name instanceof Identifier || ! in_array($expr->name->toString(), ['make', 'collection'], true)) {
                return null;
            }
            $isCollection = $expr->name->toString() === 'collection';
        }

        if (! $this->isResource($class)) {
            return null;
        }

        $argument = $expr->args[0] ?? null;
        $argument = $argument instanceof Arg ? $argument->value : null;

        $optional = false;
        $nullable = false;
        if ($argument instanceof MethodCall && $this->isThis($argument->var) && $argument->name instanceof Identifier && str_starts_with($argument->name->toString(), 'when')) {
            $optional = true;
            $relation = $this->argValue($argument, 0);
            if ($relation instanceof String_ && $context['model'] !== null) {
                $nullable = $this->models->relationIsNullable($context['model'], $relation->value, $context['schema']);
            }
        } elseif (($relation = $this->attributeName($argument)) !== null && $context['model'] !== null) {
            $nullable = $this->models->relationIsNullable($context['model'], $relation, $context['schema']);
        }

        if ($this->isResourceCollection($class)) {
            $item = $this->collectedResource($class);
            $schema = Schema::ofType('array');
            $schema->items = $item !== null ? $this->reference($item, $context['schema'], $context['components']) : null;

            return ['schema' => $schema, 'optional' => $optional];
        }

        $reference = $this->reference($class, $context['schema'], $context['components']);

        if ($isCollection) {
            $schema = Schema::ofType('array');
            $schema->items = $reference;

            return ['schema' => $schema, 'optional' => $optional];
        }

        return ['schema' => $nullable ? $reference->nullable() : $reference, 'optional' => $optional];
    }

    /**
     * @param  Context  $context
     */
    private function attributeOrRelation(string $name, array $context): ?Schema
    {
        $model = $context['model'];

        if ($model === null) {
            return null;
        }

        if (isset($model['attributes'][$name])) {
            return clone $model['attributes'][$name];
        }

        if (isset($model['relations'][$name])) {
            return $this->relationSchema($name, $context);
        }

        $accessor = 'get'.str_replace(' ', '', ucwords(str_replace('_', ' ', $name))).'Attribute';
        if (isset($model['methods'][$accessor])) {
            return Schema::ofType($model['methods'][$accessor]);
        }

        return null;
    }

    /**
     * Raw relation value as the model serializes it.
     *
     * @param  Context  $context
     */
    private function relationSchema(string $relation, array $context): ?Schema
    {
        $model = $context['model'];
        $definition = $model['relations'][$relation] ?? null;

        if ($definition === null || $definition['model'] === null) {
            return null;
        }

        $related = $context['components'] !== null
            ? $this->models->reference($definition['model'], $context['schema'], $context['components'])
            : $this->models->serializedSchema($definition['model'], $context['schema'], null);

        if ($definition['many']) {
            $array = Schema::ofType('array');
            $array->items = $related;

            return $array;
        }

        return $this->models->relationIsNullable($model, $relation, $context['schema']) ? $related->nullable() : $related;
    }

    /**
     * @param  Context  $context
     */
    private function inferArray(Array_ $array, array $context): Schema
    {
        $hasKeys = false;
        foreach ($array->items as $item) {
            if ($item !== null && ($item->key !== null || $this->mergedProperties($item->value, $context) !== null)) {
                $hasKeys = true;
                break;
            }
        }

        if ($hasKeys) {
            return $this->buildObject($array, $context);
        }

        $list = Schema::ofType('array');
        $itemSchema = null;
        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }
            $inferred = $this->infer($item->value, $context)['schema'];
            if ($inferred !== null) {
                $inferred = clone $inferred;
                unset($inferred->extensions['const']);
            }
            $itemSchema = $itemSchema === null ? $inferred : $this->union($itemSchema, $inferred);
        }
        $list->items = $itemSchema;

        return $list;
    }

    private function inferFunction(string $function): ?Schema
    {
        return match (true) {
            in_array($function, self::STRING_FUNCTIONS, true) => Schema::ofType('string'),
            in_array($function, self::INTEGER_FUNCTIONS, true) => Schema::ofType('integer'),
            in_array($function, ['floatval', 'abs'], true) => Schema::ofType('number'),
            in_array($function, self::BOOLEAN_FUNCTIONS, true) => Schema::ofType('boolean'),
            in_array($function, self::ARRAY_FUNCTIONS, true) => Schema::ofType('array'),
            in_array($function, ['now', 'today'], true) => Schema::ofType('string', 'date-time'),
            default => null,
        };
    }

    /**
     * @param  Context  $context
     */
    private function enumCase(string $enumClass, array $context): ?Schema
    {
        $cases = (new BackedEnumParser)->parseCases($enumClass);

        if ($cases['cases'] === []) {
            return null;
        }

        $schema = Schema::ofType($cases['type'] ?? 'string');
        $schema->enum = $cases['cases'];

        if ($context['components'] === null) {
            return $schema;
        }

        $name = $this->models->shortName($enumClass);
        $context['components']->addSchema($name, $schema);

        return Schema::ref($name);
    }

    /**
     * Combine two possible schemas for one key.
     */
    private function union(?Schema $first, ?Schema $second): ?Schema
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        if ($second->type === 'null') {
            return $first->nullable();
        }
        if ($first->type === 'null') {
            return $second->nullable();
        }

        $firstJson = $this->withoutConst($first)->jsonSerialize();
        $secondJson = $this->withoutConst($second)->jsonSerialize();

        if ($firstJson == $secondJson) {
            return $this->withoutConst($first);
        }

        if ($first->ref === null && $second->ref === null && $first->anyOf === null && $second->anyOf === null
            && is_string($first->type) && is_string($second->type) && $first->properties === null && $second->properties === null) {
            return Schema::ofType(array_values(array_unique([$first->type, $second->type])));
        }

        $union = new Schema;
        $union->anyOf = array_merge($first->anyOf ?? [$first], $second->anyOf ?? [$second]);

        return $union;
    }

    private function constant(string $type, string|int|float $value): Schema
    {
        $schema = Schema::ofType($type);
        $schema->extensions['const'] = $value;

        return $schema;
    }

    private function withoutConst(Schema $schema): Schema
    {
        $copy = clone $schema;
        unset($copy->extensions['const']);

        return $copy;
    }

    private function withoutNull(Schema $schema): Schema
    {
        $copy = clone $schema;

        if (is_array($copy->type)) {
            $types = array_values(array_filter($copy->type, fn ($type) => $type !== 'null'));
            $copy->type = count($types) === 1 ? $types[0] : $types;
        }

        if ($copy->anyOf !== null) {
            $options = array_values(array_filter($copy->anyOf, fn (Schema $option) => ! ($option->ref === null && $option->type === 'null')));

            if (count($options) === 1) {
                return $options[0];
            }
            $copy->anyOf = $options;
        }

        return $copy;
    }

    /**
     * `$this->foo`, `$this?->foo`, `$this->resource->foo` → "foo".
     */
    private function attributeName(?Node $expr): ?string
    {
        if (! $expr instanceof PropertyFetch && ! $expr instanceof NullsafePropertyFetch) {
            return null;
        }

        if (! $expr->name instanceof Identifier) {
            return null;
        }

        $name = $expr->name->toString();

        if ($this->isThis($expr->var)) {
            return $name === 'resource' ? null : $name;
        }

        if (($expr->var instanceof PropertyFetch || $expr->var instanceof NullsafePropertyFetch)
            && $this->isThis($expr->var->var)
            && $expr->var->name instanceof Identifier
            && $expr->var->name->toString() === 'resource') {
            return $name;
        }

        return null;
    }

    private function isThis(Node $node): bool
    {
        return $node instanceof Variable && $node->name === 'this';
    }

    private function isParentToArray(?Node $node): bool
    {
        return $node instanceof StaticCall
            && $node->class instanceof Name
            && $node->class->toLowerString() === 'parent'
            && $node->name instanceof Identifier
            && $node->name->toString() === 'toArray';
    }

    private function argValue(MethodCall|NullsafeMethodCall|StaticCall|New_ $call, int $index): ?Expr
    {
        $arg = $call->args[$index] ?? null;

        return $arg instanceof Arg ? $arg->value : null;
    }

    /**
     * `fn () => $x` or `function () { return $x; }` → `$x`.
     */
    private function unwrapClosure(Expr $expr): Expr
    {
        if ($expr instanceof ArrowFunction) {
            return $expr->expr;
        }

        if ($expr instanceof Closure) {
            /** @var Return_|null $return */
            $return = $this->finder->findFirstInstanceOf($expr->stmts, Return_::class);

            return $return?->expr ?? $expr;
        }

        return $expr;
    }

    private function firstReturnExpression(ClassMethod $method): ?Expr
    {
        foreach ($method->stmts ?? [] as $stmt) {
            if ($stmt instanceof Return_) {
                return $stmt->expr;
            }
        }

        /** @var Return_|null $return */
        $return = $this->finder->findFirstInstanceOf($method->stmts ?? [], Return_::class);

        return $return?->expr;
    }

    private function findMethod(Class_ $class, string $name): ?ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if ($method->name->toString() === $name) {
                return $method;
            }
        }

        return null;
    }

    /**
     * @param  array{class: Class_, file: string, uses: array<string, string>, namespace: string}  $parsed
     */
    private function modelFor(string $resourceClass, array $parsed): ?string
    {
        $comment = $parsed['class']->getDocComment()?->getText();
        $doc = $comment !== null ? $this->phpDocParser->parseClassDocblock($comment) : [];

        if (isset($doc['backingModel'])) {
            $candidate = $this->resolveDocName($doc['backingModel'], $parsed);
            if ($this->models->isModel($candidate)) {
                return $candidate;
            }
        }

        $short = $this->models->shortName($resourceClass);
        $base = preg_replace('/(Resource|Collection)$/', '', $short);

        if ($base === '' || $base === null) {
            return null;
        }

        $namespace = substr($resourceClass, 0, max(0, strrpos($resourceClass, '\\') ?: 0));
        $candidates = [
            preg_replace('/(Http\\\\)?Resources(\\\\.*)?$/', 'Models', $namespace).'\\'.$base,
            'App\\Models\\'.$base,
        ];

        foreach ($candidates as $candidate) {
            if (ClassFileLocator::locate($candidate) !== null && $this->models->isModel($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array{class: Class_, file: string, uses: array<string, string>, namespace: string}  $parsed
     */
    private function resolveDocName(string $name, array $parsed): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $first = explode('\\', $name)[0];
        if (isset($parsed['uses'][$first])) {
            return $parsed['uses'][$first].substr($name, strlen($first));
        }

        return $parsed['namespace'] !== '' ? $parsed['namespace'].'\\'.$name : $name;
    }

    private function extendsClass(string $class, string $parent, int $depth = 0): bool
    {
        $class = ltrim($class, '\\');

        if ($class === $parent || is_subclass_of($class, $parent)) {
            return true;
        }

        if ($depth > 10) {
            return false;
        }

        $parsed = $this->parseClass($class);
        $extends = $parsed === null ? null : $parsed['class']->extends;

        return $extends !== null && $this->extendsClass($extends->toString(), $parent, $depth + 1);
    }

    /**
     * @return array{class: Class_, file: string, uses: array<string, string>, namespace: string}|null
     */
    private function parseClass(string $class): ?array
    {
        $class = ltrim($class, '\\');

        if (array_key_exists($class, $this->parsedClasses)) {
            return $this->parsedClasses[$class];
        }

        $file = ClassFileLocator::locate($class);
        if ($file === null) {
            return $this->parsedClasses[$class] = null;
        }

        try {
            $stmts = $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (\Throwable) {
            return $this->parsedClasses[$class] = null;
        }

        $uses = [];
        $namespace = '';
        foreach ($this->finder->findInstanceOf($stmts, Use_::class) as $use) {
            foreach ($use->uses as $useItem) {
                $uses[$useItem->getAlias()->toString()] = $useItem->name->toString();
            }
        }
        $namespaceNode = $this->finder->findFirstInstanceOf($stmts, Namespace_::class);
        if ($namespaceNode instanceof Namespace_ && $namespaceNode->name !== null) {
            $namespace = $namespaceNode->name->toString();
        }

        $stmts = (new NodeTraverser(new NameResolver))->traverse($stmts);

        $short = $this->models->shortName($class);
        /** @var Class_|null $classNode */
        $classNode = $this->finder->findFirst($stmts, fn (Node $node) => $node instanceof Class_ && $node->name?->toString() === $short);

        return $this->parsedClasses[$class] = $classNode === null ? null : [
            'class' => $classNode,
            'file' => $file,
            'uses' => $uses,
            'namespace' => $namespace,
        ];
    }

    private function diagnose(string $file, int $line, string $message): void
    {
        $this->diagnostics[] = new InferenceDiagnostic($file !== '' ? $file : 'unknown', $line, $message);
    }
}
