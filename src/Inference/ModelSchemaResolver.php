<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Components;
use Geni\Inference\Document\Schema;
use Geni\SchemaReader\ColumnSchema;
use Geni\SchemaReader\ColumnType;
use Geni\SchemaReader\DatabaseSchema;
use Geni\SchemaReader\TableSchema;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Reads an Eloquent model without booting it: attribute types come from the
 * migration columns, refined by `$casts` / `casts()`, and the model's
 * `$hidden` / `$visible` lists and relationship methods are collected so
 * resources and raw model responses can be documented accurately.
 *
 * @phpstan-type Relation array{model: ?string, many: bool, kind: string, foreignKey: ?string}
 * @phpstan-type ModelInfo array{
 *     class: string,
 *     table: string,
 *     attributes: array<string, Schema>,
 *     hidden: list<string>,
 *     visible: list<string>,
 *     relations: array<string, Relation>,
 *     methods: array<string, string>
 * }
 */
final class ModelSchemaResolver
{
    private const MANY_RELATIONS = ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany', 'morphedByMany', 'hasManyThrough'];

    private const ONE_RELATIONS = ['hasOne', 'belongsTo', 'morphOne', 'hasOneThrough', 'morphTo'];

    private Parser $parser;

    private NodeFinder $finder;

    private ModelTableResolver $tableResolver;

    private BackedEnumParser $enumParser;

    /** @var array<string, ModelInfo|null> */
    private array $cache = [];

    /** @var array<string, string> */
    private array $schemaOverrides = [];

    public function __construct(
        ?Parser $parser = null,
        ?NodeFinder $finder = null,
        ?ModelTableResolver $tableResolver = null,
        ?BackedEnumParser $enumParser = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
        $this->finder = $finder ?? new NodeFinder;
        $this->tableResolver = $tableResolver ?? new ModelTableResolver;
        $this->enumParser = $enumParser ?? new BackedEnumParser;
    }

    /**
     * @param  array<string, string>  $overrides  'table.column' => OpenAPI type
     */
    public function setSchemaOverrides(array $overrides): void
    {
        $this->schemaOverrides = $overrides;
        $this->cache = [];
    }

    /**
     * Whether the class is (statically) an Eloquent model.
     */
    public function isModel(string $class): bool
    {
        if (is_subclass_of($class, 'Illuminate\Database\Eloquent\Model')) {
            return true;
        }

        $parsed = $this->parseClass($class);

        if ($parsed === null || $parsed['class']->extends === null) {
            return false;
        }

        $parent = $parsed['class']->extends->toString();

        return $parent === 'Illuminate\Database\Eloquent\Model'
            || $parent === 'Illuminate\Foundation\Auth\User'
            || str_ends_with($parent, '\Model')
            || $this->isModel($parent);
    }

    /**
     * @return ModelInfo|null
     */
    public function resolve(string $class, ?DatabaseSchema $schema, ?Components $components): ?array
    {
        $cacheKey = $class.'#'.spl_object_id($schema ?? $this).'#'.($components !== null ? spl_object_id($components) : 0);

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $parsed = $this->parseClass($class);
        $source = $parsed !== null ? (string) file_get_contents($parsed['file']) : null;
        $tableName = $this->tableResolver->resolve($class, $source);
        $table = $schema !== null ? $this->findTable($schema, $tableName) : null;

        if ($parsed === null && $table === null) {
            return $this->cache[$cacheKey] = null;
        }

        $classNode = $parsed['class'] ?? null;
        $casts = $classNode !== null ? $this->readCasts($classNode) : [];

        $attributes = [];
        foreach ($table->columns ?? [] as $name => $column) {
            $attributes[$name] = $this->attributeSchema($column, $casts[$name] ?? null, $components);
        }

        foreach ($casts as $name => $cast) {
            if (! isset($attributes[$name])) {
                $castSchema = $this->castSchema($cast, $components);
                if ($castSchema !== null) {
                    $attributes[$name] = $castSchema;
                }
            }
        }

        foreach ($this->schemaOverrides as $key => $type) {
            [$overrideTable, $column] = array_pad(explode('.', $key, 2), 2, null);
            if ($overrideTable === $tableName && $column !== null) {
                $attributes[$column] = Schema::ofType($type);
            }
        }

        return $this->cache[$cacheKey] = [
            'class' => $class,
            'table' => $tableName,
            'attributes' => $attributes,
            'hidden' => $classNode !== null ? $this->readStringList($classNode, 'hidden', 'Hidden') : [],
            'visible' => $classNode !== null ? $this->readStringList($classNode, 'visible', 'Visible') : [],
            'relations' => $classNode !== null ? $this->readRelations($classNode) : [],
            'methods' => $classNode !== null ? $this->readScalarMethods($classNode) : [],
        ];
    }

    /**
     * Register the serialized model (visible attributes only) as a component
     * named after the class and return a reference to it.
     */
    public function reference(string $class, ?DatabaseSchema $schema, Components $components): Schema
    {
        $name = $this->shortName($class);

        if (! isset($components->schemas[$name])) {
            $components->schemas[$name] = ['type' => 'object'];
            $components->addSchema($name, $this->serializedSchema($class, $schema, $components));
        }

        return Schema::ref($name);
    }

    /**
     * The model as `toArray()` serializes it: every column, minus `$hidden`
     * (or only `$visible` when set), with casts applied.
     */
    public function serializedSchema(string $class, ?DatabaseSchema $schema, ?Components $components): Schema
    {
        $info = $this->resolve($class, $schema, $components);
        $object = Schema::ofType('object');
        $object->properties = [];

        foreach ($info['attributes'] ?? [] as $name => $attribute) {
            if (in_array($name, $info['hidden'], true)) {
                continue;
            }
            if ($info['visible'] !== [] && ! in_array($name, $info['visible'], true)) {
                continue;
            }
            $object->properties[$name] = $attribute;
        }

        $object->required = array_keys($object->properties);

        return $object;
    }

    /**
     * Whether a belongsTo relation can be null because its foreign key column is nullable.
     *
     * @param  ModelInfo  $info
     */
    public function relationIsNullable(array $info, string $relation, ?DatabaseSchema $schema): bool
    {
        $definition = $info['relations'][$relation] ?? null;

        if ($definition === null || $definition['many']) {
            return false;
        }

        if ($definition['kind'] !== 'belongsTo') {
            return $definition['kind'] === 'morphTo';
        }

        $foreignKey = $definition['foreignKey'] ?? $this->tableResolver->snakeCase($relation).'_id';
        $attribute = $info['attributes'][$foreignKey] ?? null;

        return $attribute !== null && $attribute->isNullable();
    }

    public function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    private function attributeSchema(ColumnSchema $column, Node|string|null $cast, ?Components $components): Schema
    {
        $schema = $cast !== null ? $this->castSchema($cast, $components) : null;

        if ($schema === null) {
            $schema = $this->columnSchema($column);
        }

        return $column->nullable ? $schema->nullable() : $schema;
    }

    private function columnSchema(ColumnSchema $column): Schema
    {
        $schema = match ($column->type) {
            ColumnType::Integer => Schema::ofType('integer'),
            ColumnType::Float, ColumnType::Decimal => Schema::ofType('number'),
            ColumnType::Boolean => Schema::ofType('boolean'),
            ColumnType::Uuid => Schema::ofType('string', 'uuid'),
            ColumnType::Ulid => Schema::ofType('string', 'ulid'),
            ColumnType::Date => Schema::ofType('string', 'date'),
            ColumnType::DateTime => Schema::ofType('string', 'date-time'),
            ColumnType::Time => Schema::ofType('string', 'time'),
            ColumnType::Json => Schema::ofType('object'),
            default => Schema::ofType('string'),
        };

        if ($column->allowed !== null && $column->allowed !== []) {
            $schema->enum = array_values($column->allowed);
        }

        return $schema;
    }

    /**
     * Map a cast definition (string like 'boolean' / 'decimal:2', or an
     * `Enum::class` node) to a schema. Null for casts that do not change the
     * column type (custom cast classes we cannot see into).
     */
    private function castSchema(Node|string $cast, ?Components $components): ?Schema
    {
        if ($cast instanceof ClassConstFetch && $cast->class instanceof Name) {
            return $this->enumSchema($cast->class->toString(), $components);
        }

        if (! is_string($cast)) {
            return null;
        }

        if (class_exists($cast) || enum_exists($cast)) {
            $enum = $this->enumSchema($cast, $components);
            if ($enum !== null) {
                return $enum;
            }

            return match (true) {
                str_ends_with($cast, 'AsArrayObject'), str_ends_with($cast, 'AsCollection'), str_ends_with($cast, 'AsEncryptedArrayObject'), str_ends_with($cast, 'AsEncryptedCollection') => Schema::ofType('array'),
                str_ends_with($cast, 'AsStringable') => Schema::ofType('string'),
                default => null,
            };
        }

        $lower = strtolower($cast);
        $type = str_starts_with($lower, 'encrypted:') ? substr($lower, 10) : explode(':', $lower, 2)[0];

        return match ($type) {
            'bool', 'boolean' => Schema::ofType('boolean'),
            'int', 'integer', 'timestamp' => Schema::ofType('integer'),
            'float', 'double', 'real' => Schema::ofType('number'),
            'decimal', 'string', 'hashed', 'encrypted' => Schema::ofType('string'),
            'array', 'json', 'collection' => Schema::ofType('array'),
            'object' => Schema::ofType('object'),
            'date', 'datetime', 'immutable_date', 'immutable_datetime', 'custom_datetime', 'immutable_custom_datetime' => Schema::ofType('string', 'date-time'),
            default => null,
        };
    }

    private function enumSchema(string $enumClass, ?Components $components): ?Schema
    {
        $enumClass = ltrim($enumClass, '\\');
        $cases = $this->enumParser->parseCases($enumClass);

        if ($cases['cases'] === []) {
            return null;
        }

        $schema = Schema::ofType($cases['type'] ?? 'string');
        $schema->enum = $cases['cases'];

        if ($components === null) {
            return $schema;
        }

        $name = $this->shortName($enumClass);
        $components->addSchema($name, $schema);

        return Schema::ref($name);
    }

    /**
     * @return array<string, Node|string>
     */
    private function readCasts(Class_ $class): array
    {
        $array = null;

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop->name->toString() === 'casts' && $prop->default instanceof Array_) {
                        $array = $prop->default;
                    }
                }
            }

            if ($stmt instanceof ClassMethod && $stmt->name->toString() === 'casts') {
                /** @var Return_|null $return */
                $return = $this->finder->findFirstInstanceOf($stmt->stmts ?? [], Return_::class);
                if ($return?->expr instanceof Array_) {
                    $array = $return->expr;
                }
            }
        }

        $casts = [];
        foreach ($array?->items ?? [] as $item) {
            if ($item === null || ! $item->key instanceof String_) {
                continue;
            }

            $casts[$item->key->value] = $item->value instanceof String_ ? $item->value->value : $item->value;
        }

        return $casts;
    }

    /**
     * Read a `protected $hidden = [...]` style list, or the matching
     * `#[Hidden([...])]` class attribute.
     *
     * @return list<string>
     */
    private function readStringList(Class_ $class, string $property, string $attribute): array
    {
        $values = [];

        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() === $attribute && isset($attr->args[0]) && $attr->args[0]->value instanceof Array_) {
                    $values = array_merge($values, $this->stringsFrom($attr->args[0]->value));
                }
            }
        }

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop->name->toString() === $property && $prop->default instanceof Array_) {
                        $values = array_merge($values, $this->stringsFrom($prop->default));
                    }
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @return list<string>
     */
    private function stringsFrom(Array_ $array): array
    {
        $values = [];
        foreach ($array->items as $item) {
            if ($item !== null && $item->value instanceof String_) {
                $values[] = $item->value->value;
            }
        }

        return $values;
    }

    /**
     * @return array<string, Relation>
     */
    private function readRelations(Class_ $class): array
    {
        $relations = [];

        foreach ($class->getMethods() as $method) {
            if (! $method->isPublic() || $method->isStatic() || $method->params !== []) {
                continue;
            }

            /** @var Return_|null $return */
            $return = $this->finder->findFirstInstanceOf($method->stmts ?? [], Return_::class);
            $call = $return?->expr;

            while ($call instanceof MethodCall && ! ($call->var instanceof Variable && $call->var->name === 'this')) {
                $call = $call->var;
            }

            if (! $call instanceof MethodCall || ! $call->name instanceof Identifier) {
                continue;
            }

            $kind = $call->name->toString();
            $isMany = in_array($kind, self::MANY_RELATIONS, true);

            if (! $isMany && ! in_array($kind, self::ONE_RELATIONS, true)) {
                continue;
            }

            $related = null;
            $firstArg = $call->args[0] ?? null;
            if ($firstArg instanceof Arg && $firstArg->value instanceof ClassConstFetch && $firstArg->value->class instanceof Name) {
                $related = $firstArg->value->class->toString();
            }

            $foreignKey = null;
            $secondArg = $call->args[1] ?? null;
            if ($kind === 'belongsTo' && $secondArg instanceof Arg && $secondArg->value instanceof String_) {
                $foreignKey = $secondArg->value->value;
            }

            $relations[$method->name->toString()] = [
                'model' => $related,
                'many' => $isMany,
                'kind' => $kind,
                'foreignKey' => $foreignKey,
            ];
        }

        return $relations;
    }

    /**
     * Public methods with a scalar return type, e.g. `fullName(): string`.
     *
     * @return array<string, string>
     */
    private function readScalarMethods(Class_ $class): array
    {
        $methods = [];

        foreach ($class->getMethods() as $method) {
            if (! $method->returnType instanceof Identifier) {
                continue;
            }

            $type = match ($method->returnType->toLowerString()) {
                'string' => 'string',
                'int' => 'integer',
                'float' => 'number',
                'bool' => 'boolean',
                'array' => 'array',
                default => null,
            };

            if ($type !== null) {
                $methods[$method->name->toString()] = $type;
            }
        }

        return $methods;
    }

    private function findTable(DatabaseSchema $schema, string $tableName): ?TableSchema
    {
        if (isset($schema->tables[$tableName])) {
            return $schema->tables[$tableName];
        }

        foreach ($schema->tables as $key => $table) {
            if ($table->name === $tableName || str_ends_with($key, '.'.$tableName)) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @return array{class: Class_, file: string}|null
     */
    private function parseClass(string $class): ?array
    {
        $file = ClassFileLocator::locate($class);

        if ($file === null) {
            return null;
        }

        try {
            $stmts = $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (\Throwable) {
            return null;
        }

        $traverser = new NodeTraverser(new NameResolver);
        $stmts = $traverser->traverse($stmts);

        $shortName = $this->shortName($class);
        /** @var Class_|null $classNode */
        $classNode = $this->finder->findFirst($stmts, fn (Node $node) => $node instanceof Class_ && $node->name?->toString() === $shortName);

        return $classNode === null ? null : ['class' => $classNode, 'file' => $file];
    }
}
