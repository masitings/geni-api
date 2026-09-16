<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Components;
use Geni\Inference\Document\Schema;
use Geni\SchemaReader\DatabaseSchema;
use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * AST-based detector and schema inferer for Spatie Laravel Data DTOs (spatie/laravel-data).
 *
 * Framework-free: uses php-parser and AST traversal only, zero runtime execution.
 */
final class LaravelDataDetector
{
    private Parser $parser;

    private NodeFinder $finder;

    /** @var array<string, array{class: ?Class_, imports: array<string, string>, namespace: ?string}> */
    private static array $fileCache = [];

    /** @var array<string, bool> */
    private static array $isDataClassCache = [];

    public function __construct(?Parser $parser = null, ?NodeFinder $finder = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
        $this->finder = $finder ?? new NodeFinder;
    }

    public static function clearCache(): void
    {
        self::$fileCache = [];
        self::$isDataClassCache = [];
    }

    /**
     * Determine whether an AST Class_ node represents a class extending Spatie\LaravelData\Data.
     *
     * @param  array<string, string>  $useImports
     */
    public function isDataClassNode(Class_ $classNode, array $useImports = []): bool
    {
        if ($classNode->extends === null) {
            return false;
        }

        $extendedName = $classNode->extends->toString();

        if ($extendedName === 'Spatie\LaravelData\Data' || $extendedName === '\Spatie\LaravelData\Data') {
            return true;
        }

        if ($extendedName === 'Data') {
            $resolved = $useImports['Data'] ?? null;
            if ($resolved === 'Spatie\LaravelData\Data' || $resolved === null) {
                return true;
            }
        }

        if (str_ends_with($extendedName, '\Data') || $extendedName === 'Data') {
            return true;
        }

        return false;
    }

    /**
     * Determine whether a class name (FQCN) is a Data class.
     */
    public function isDataClass(string $fqcn, ?string $file = null, ?ActionAst $context = null): bool
    {
        if (isset(self::$isDataClassCache[$fqcn])) {
            return self::$isDataClassCache[$fqcn];
        }

        if (class_exists($fqcn)) {
            try {
                if (is_subclass_of($fqcn, 'Spatie\LaravelData\Data')) {
                    self::$isDataClassCache[$fqcn] = true;

                    return true;
                }
            } catch (\Throwable) {
                // fallback to AST
            }
        }

        $resolvedFile = $file ?? $this->resolveClassFile($fqcn);
        if ($resolvedFile === null || ! is_file($resolvedFile)) {
            $isData = str_ends_with($fqcn, 'Data');
            self::$isDataClassCache[$fqcn] = $isData;

            return $isData;
        }

        $parsed = $this->parseFileAndExtractClass($resolvedFile, $fqcn);
        if ($parsed['class'] === null) {
            self::$isDataClassCache[$fqcn] = false;

            return false;
        }

        $isData = $this->isDataClassNode($parsed['class'], $parsed['imports']);
        self::$isDataClassCache[$fqcn] = $isData;

        return $isData;
    }

    /**
     * Infer the OpenAPI Schema for a Spatie Data class.
     *
     * @param  list<string>  $seenClasses
     * @return array{schema: Schema, diagnostics: list<InferenceDiagnostic>}
     */
    public function inferSchemaFromDataClass(
        string $fqcn,
        ?DatabaseSchema $dbSchema = null,
        ?Components $components = null,
        array $seenClasses = []
    ): array {
        $shortName = substr(strrchr('\\'.$fqcn, '\\') ?: $fqcn, 1);

        if (in_array($fqcn, $seenClasses, true)) {
            $refSchema = new Schema;
            $refSchema->ref = '#/components/schemas/'.$shortName;

            return ['schema' => $refSchema, 'diagnostics' => []];
        }

        $seenClasses[] = $fqcn;

        $file = $this->resolveClassFile($fqcn);
        if ($file === null || ! is_file($file)) {
            $schema = new Schema;
            $schema->type = 'object';

            return [
                'schema' => $schema,
                'diagnostics' => [new InferenceDiagnostic(
                    'unknown',
                    0,
                    "Could not locate file for Data class: {$fqcn}"
                )],
            ];
        }

        $parsed = $this->parseFileAndExtractClass($file, $fqcn);
        $classNode = $parsed['class'];
        $imports = $parsed['imports'];
        $currentNamespace = $parsed['namespace'];

        if ($classNode === null) {
            $schema = new Schema;
            $schema->type = 'object';

            return [
                'schema' => $schema,
                'diagnostics' => [new InferenceDiagnostic(
                    $file,
                    0,
                    "Could not find class AST for Data class: {$fqcn}"
                )],
            ];
        }

        $diagnostics = [];
        $properties = [];
        $required = [];

        // 1. Scan constructor parameters (promoted properties)
        $constructor = $classNode->getMethod('__construct');
        if ($constructor !== null) {
            foreach ($constructor->params as $param) {
                if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $propName = $param->var->name;
                    $propData = $this->analyzePropertyOrParam($param, $imports, $currentNamespace, $file, $components, $seenClasses);

                    $properties[$propName] = $propData['schema'];
                    if ($propData['required']) {
                        $required[] = $propName;
                    }
                    foreach ($propData['diagnostics'] as $d) {
                        $diagnostics[] = $d;
                    }
                }
            }
        }

        // 2. Scan public class properties
        foreach ($classNode->getProperties() as $property) {
            if ($property->isPublic() && ! $property->isStatic()) {
                foreach ($property->props as $propProperty) {
                    $propName = $propProperty->name->toString();
                    if (! isset($properties[$propName])) {
                        $propData = $this->analyzePropertyOrParam($property, $imports, $currentNamespace, $file, $components, $seenClasses, $propProperty);

                        $properties[$propName] = $propData['schema'];
                        if ($propData['required']) {
                            $required[] = $propName;
                        }
                        foreach ($propData['diagnostics'] as $d) {
                            $diagnostics[] = $d;
                        }
                    }
                }
            }
        }

        $schema = new Schema;
        $schema->type = 'object';
        $schema->properties = $properties;
        if ($required !== []) {
            $schema->required = array_values(array_unique($required));
        }

        if ($components !== null) {
            $components->addSchema($shortName, $schema);
            $refSchema = new Schema;
            $refSchema->ref = '#/components/schemas/'.$shortName;

            return ['schema' => $refSchema, 'diagnostics' => $diagnostics];
        }

        return ['schema' => $schema, 'diagnostics' => $diagnostics];
    }

    /**
     * @param  Param|Property  $node
     * @param  array<string, string>  $imports
     * @param  list<string>  $seenClasses
     * @return array{schema: Schema, required: bool, diagnostics: list<InferenceDiagnostic>}
     */
    private function analyzePropertyOrParam(
        Node $node,
        array $imports,
        ?string $currentNamespace,
        string $file,
        ?Components $components,
        array $seenClasses,
        ?Node\Stmt\PropertyProperty $propProperty = null
    ): array {
        $diagnostics = [];
        $rawType = $node->type;
        $attrGroups = $node->attrGroups;
        $defaultExpr = $node instanceof Param ? $node->default : $propProperty?->default;

        $defaultValue = null;
        $hasDefault = $defaultExpr !== null;
        if ($defaultExpr instanceof ConstFetch) {
            $val = strtolower($defaultExpr->name->toString());
            if ($val === 'true') {
                $defaultValue = true;
            } elseif ($val === 'false') {
                $defaultValue = false;
            }
        } elseif ($defaultExpr instanceof Int_ || $defaultExpr instanceof Float_ || $defaultExpr instanceof String_) {
            $defaultValue = $defaultExpr->value;
        }

        $isOptionalType = false;
        $isNullableType = false;
        $isCollection = false;
        $collectionItemType = null;
        $baseTypeString = 'string';
        $isNestedDataClass = false;
        $nestedDataFqcn = null;

        if ($rawType !== null) {
            $typeAnalysis = $this->analyzeTypeNode($rawType, $imports, $currentNamespace);
            $isOptionalType = $typeAnalysis['isOptional'];
            $isNullableType = $typeAnalysis['isNullable'];
            $baseTypeString = $typeAnalysis['baseType'];
            $isNestedDataClass = $typeAnalysis['isDataClass'];
            $nestedDataFqcn = $typeAnalysis['dataFqcn'];
            $isCollection = $typeAnalysis['isCollection'];
            $collectionItemType = $typeAnalysis['collectionItemType'];
        }

        $attrAnalysis = $this->analyzeValidationAttributes($attrGroups, $imports, $currentNamespace);
        if ($attrAnalysis['isDataCollection'] && $attrAnalysis['dataCollectionClass'] !== null) {
            $isCollection = true;
            $isNestedDataClass = true;
            $nestedDataFqcn = $attrAnalysis['dataCollectionClass'];
        }

        $propSchema = new Schema;

        if ($isNestedDataClass && $nestedDataFqcn !== null) {
            $nestedRes = $this->inferSchemaFromDataClass($nestedDataFqcn, null, $components, $seenClasses);
            foreach ($nestedRes['diagnostics'] as $d) {
                $diagnostics[] = $d;
            }

            if ($isCollection) {
                $propSchema->type = 'array';
                $propSchema->items = $nestedRes['schema'];
            } else {
                $propSchema = $nestedRes['schema'];
            }
        } elseif ($isCollection) {
            $propSchema->type = 'array';
            $itemSchema = new Schema;
            $itemSchema->type = $collectionItemType ?? 'string';
            $propSchema->items = $itemSchema;
        } else {
            $propSchema->type = $baseTypeString;
            if ($isNullableType) {
                $propSchema->type = [$baseTypeString, 'null'];
            }
        }

        if ($attrAnalysis['format'] !== null) {
            $propSchema->format = $attrAnalysis['format'];
        }
        if ($attrAnalysis['minimum'] !== null) {
            $propSchema->minimum = $attrAnalysis['minimum'];
        }
        if ($attrAnalysis['maximum'] !== null) {
            $propSchema->maximum = $attrAnalysis['maximum'];
        }
        if ($attrAnalysis['pattern'] !== null) {
            $propSchema->pattern = $attrAnalysis['pattern'];
        }
        if ($attrAnalysis['enum'] !== []) {
            $propSchema->enum = $attrAnalysis['enum'];
        }

        if ($hasDefault && $defaultValue !== null) {
            $propSchema->default = $defaultValue;
        }

        $isRequired = true;
        if ($attrAnalysis['required'] === true) {
            $isRequired = true;
        } elseif ($attrAnalysis['required'] === false || $hasDefault || $isOptionalType || $isNullableType) {
            $isRequired = false;
        }

        return [
            'schema' => $propSchema,
            'required' => $isRequired,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param  array<string, string>  $imports
     * @return array{
     *     baseType: string,
     *     isNullable: bool,
     *     isOptional: bool,
     *     isDataClass: bool,
     *     dataFqcn: ?string,
     *     isCollection: bool,
     *     collectionItemType: ?string
     * }
     */
    private function analyzeTypeNode(Node $typeNode, array $imports, ?string $currentNamespace): array
    {
        $isNullable = false;
        $isOptional = false;
        $isDataClass = false;
        $dataFqcn = null;
        $isCollection = false;
        $collectionItemType = null;
        $baseType = 'string';

        if ($typeNode instanceof NullableType) {
            $sub = $this->analyzeTypeNode($typeNode->type, $imports, $currentNamespace);
            $sub['isNullable'] = true;

            return $sub;
        }

        if ($typeNode instanceof UnionType) {
            $types = [];
            foreach ($typeNode->types as $t) {
                $sub = $this->analyzeTypeNode($t, $imports, $currentNamespace);
                if ($sub['isNullable']) {
                    $isNullable = true;
                }
                if ($sub['isOptional']) {
                    $isOptional = true;
                }
                if ($sub['isDataClass']) {
                    $isDataClass = true;
                    $dataFqcn = $sub['dataFqcn'];
                }
                if ($sub['isCollection']) {
                    $isCollection = true;
                }
                if ($sub['baseType'] !== 'null') {
                    $types[] = $sub['baseType'];
                    if ($dataFqcn === null && $sub['dataFqcn'] !== null) {
                        $dataFqcn = $sub['dataFqcn'];
                    }
                }
            }

            $types = array_values(array_unique($types));
            $baseType = count($types) === 1 ? $types[0] : ($types[0] ?? 'string');

            return [
                'baseType' => $baseType,
                'isNullable' => $isNullable,
                'isOptional' => $isOptional,
                'isDataClass' => $isDataClass,
                'dataFqcn' => $dataFqcn,
                'isCollection' => $isCollection,
                'collectionItemType' => $collectionItemType,
            ];
        }

        $typeName = $typeNode instanceof Name || $typeNode instanceof Identifier
            ? $typeNode->toString()
            : 'string';

        if ($typeName === 'null') {
            return [
                'baseType' => 'null',
                'isNullable' => true,
                'isOptional' => false,
                'isDataClass' => false,
                'dataFqcn' => null,
                'isCollection' => false,
                'collectionItemType' => null,
            ];
        }

        if ($typeName === 'Optional' || str_ends_with($typeName, '\Optional')) {
            return [
                'baseType' => 'string',
                'isNullable' => false,
                'isOptional' => true,
                'isDataClass' => false,
                'dataFqcn' => null,
                'isCollection' => false,
                'collectionItemType' => null,
            ];
        }

        if ($typeName === 'array' || str_contains($typeName, 'Collection') || str_contains($typeName, 'DataCollection')) {
            $isCollection = true;
        }

        $scalarMap = [
            'int' => 'integer',
            'integer' => 'integer',
            'float' => 'number',
            'double' => 'number',
            'bool' => 'boolean',
            'boolean' => 'boolean',
            'string' => 'string',
            'array' => 'array',
        ];

        if (isset($scalarMap[strtolower($typeName)])) {
            $baseType = $scalarMap[strtolower($typeName)];
        } else {
            $resolvedFqcn = $this->resolveFqcn($typeName, $imports, $currentNamespace);
            if ($this->isDataClass($resolvedFqcn)) {
                $isDataClass = true;
                $dataFqcn = $resolvedFqcn;
                $baseType = 'object';
            } else {
                $baseType = 'string';
            }
        }

        return [
            'baseType' => $baseType,
            'isNullable' => $isNullable,
            'isOptional' => $isOptional,
            'isDataClass' => $isDataClass,
            'dataFqcn' => $dataFqcn,
            'isCollection' => $isCollection,
            'collectionItemType' => $collectionItemType,
        ];
    }

    /**
     * @param  list<AttributeGroup>  $attrGroups
     * @param  array<string, string>  $imports
     * @return array{
     *     required: ?bool,
     *     format: ?string,
     *     minimum: ?int,
     *     maximum: ?int,
     *     pattern: ?string,
     *     enum: list<mixed>,
     *     isDataCollection: bool,
     *     dataCollectionClass: ?string
     * }
     */
    private function analyzeValidationAttributes(array $attrGroups, array $imports, ?string $currentNamespace): array
    {
        $res = [
            'required' => null,
            'format' => null,
            'minimum' => null,
            'maximum' => null,
            'pattern' => null,
            'enum' => [],
            'isDataCollection' => false,
            'dataCollectionClass' => null,
        ];

        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $name = $attr->name->toString();
                $shortName = substr(strrchr('\\'.$name, '\\') ?: $name, 1);

                switch ($shortName) {
                    case 'Required':
                        $res['required'] = true;
                        break;
                    case 'Nullable':
                        $res['required'] = false;
                        break;
                    case 'Email':
                        $res['format'] = 'email';
                        break;
                    case 'Url':
                    case 'ActiveUrl':
                        $res['format'] = 'uri';
                        break;
                    case 'Uuid':
                        $res['format'] = 'uuid';
                        break;
                    case 'Min':
                    case 'GreaterThanOrEqualTo':
                        if (isset($attr->args[0]) && $attr->args[0]->value instanceof Int_) {
                            $res['minimum'] = $attr->args[0]->value->value;
                        }
                        break;
                    case 'Max':
                    case 'LessThanOrEqualTo':
                        if (isset($attr->args[0]) && $attr->args[0]->value instanceof Int_) {
                            $res['maximum'] = $attr->args[0]->value->value;
                        }
                        break;
                    case 'Between':
                    case 'DigitsBetween':
                        if (isset($attr->args[0]) && $attr->args[0]->value instanceof Int_) {
                            $res['minimum'] = $attr->args[0]->value->value;
                        }
                        if (isset($attr->args[1]) && $attr->args[1]->value instanceof Int_) {
                            $res['maximum'] = $attr->args[1]->value->value;
                        }
                        break;
                    case 'Regex':
                        if (isset($attr->args[0]) && $attr->args[0]->value instanceof String_) {
                            $val = $attr->args[0]->value->value;
                            if (str_starts_with($val, '/') && str_ends_with($val, '/')) {
                                $val = substr($val, 1, -1);
                            }
                            $res['pattern'] = $val;
                        }
                        break;
                    case 'DataCollectionOf':
                        if (isset($attr->args[0]) && $attr->args[0]->value instanceof ClassConstFetch) {
                            $target = $attr->args[0]->value->class;
                            if ($target instanceof Name) {
                                $res['isDataCollection'] = true;
                                $res['dataCollectionClass'] = $this->resolveFqcn($target->toString(), $imports, $currentNamespace);
                            }
                        }
                        break;
                }
            }
        }

        return $res;
    }

    /**
     * Resolve a short class name to FQCN using imports map or current namespace.
     *
     * @param  array<string, string>  $imports
     */
    public function resolveFqcn(string $name, array $imports, ?string $currentNamespace = null): string
    {
        $clean = ltrim($name, '\\');
        if (str_contains($clean, '\\')) {
            $prefix = substr($clean, 0, strpos($clean, '\\') ?: 0);
            if (isset($imports[$prefix])) {
                return $imports[$prefix].substr($clean, strlen($prefix));
            }

            return $clean;
        }

        if (isset($imports[$clean])) {
            return $imports[$clean];
        }

        if ($currentNamespace !== null) {
            $candidate = $currentNamespace.'\\'.$clean;
            $file = $this->resolveClassFile($candidate);
            if ($file !== null && is_file($file)) {
                return $candidate;
            }
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return $clean;
    }

    /**
     * Resolve filesystem path for a class name.
     */
    public function resolveClassFile(string $className): ?string
    {
        if (class_exists($className)) {
            try {
                $file = (new \ReflectionClass($className))->getFileName();
                if ($file !== false && is_file($file)) {
                    return $file;
                }
            } catch (\Throwable) {
                // fallback
            }
        }

        $cleanClass = ltrim($className, '\\');
        $relativePath = str_replace('\\', '/', $cleanClass).'.php';

        $possiblePaths = [
            $relativePath,
            __DIR__.'/../../../../src/'.$relativePath,
            __DIR__.'/../../../../app/'.$relativePath,
            __DIR__.'/../../../../tests/Fixtures/App/Data/'.$relativePath,
            __DIR__.'/../../../../tests/Fixtures/'.$relativePath,
        ];

        foreach ($possiblePaths as $path) {
            if (is_file($path)) {
                return realpath($path) ?: $path;
            }
        }

        return null;
    }

    /**
     * Parse a PHP file and extract the matching Class_ node and use imports.
     *
     * @return array{class: ?Class_, imports: array<string, string>, namespace: ?string}
     */
    public function parseFileAndExtractClass(string $filePath, string $className): array
    {
        if (isset(self::$fileCache[$filePath])) {
            return self::$fileCache[$filePath];
        }

        try {
            $code = file_get_contents($filePath);
            if ($code === false) {
                return ['class' => null, 'imports' => [], 'namespace' => null];
            }

            $stmts = $this->parser->parse($code);
            if ($stmts === null) {
                return ['class' => null, 'imports' => [], 'namespace' => null];
            }

            $namespace = null;
            $imports = [];

            foreach ($stmts as $stmt) {
                if ($stmt instanceof Namespace_) {
                    $namespace = $stmt->name->toString();
                    foreach ($stmt->stmts as $nsStmt) {
                        if ($nsStmt instanceof Use_) {
                            foreach ($nsStmt->uses as $useUse) {
                                $alias = $useUse->alias ? $useUse->alias->toString() : $useUse->name->getLast();
                                $imports[$alias] = $useUse->name->toString();
                            }
                        }
                    }
                } elseif ($stmt instanceof Use_) {
                    foreach ($stmt->uses as $useUse) {
                        $alias = $useUse->alias ? $useUse->alias->toString() : $useUse->name->getLast();
                        $imports[$alias] = $useUse->name->toString();
                    }
                }
            }

            $classNodes = $this->finder->findInstanceOf($stmts, Class_::class);
            $targetClass = null;

            $cleanTarget = ltrim($className, '\\');
            $shortTarget = substr(strrchr('\\'.$cleanTarget, '\\') ?: $cleanTarget, 1);

            foreach ($classNodes as $node) {
                if ($node->name === null) {
                    continue;
                }

                $nodeName = $node->name->toString();
                $fqcn = $namespace !== null ? $namespace.'\\'.$nodeName : $nodeName;

                if ($fqcn === $cleanTarget || $nodeName === $shortTarget) {
                    $targetClass = $node;
                    break;
                }
            }

            $result = [
                'class' => $targetClass,
                'imports' => $imports,
                'namespace' => $namespace,
            ];

            self::$fileCache[$filePath] = $result;

            return $result;
        } catch (\Throwable) {
            return ['class' => null, 'imports' => [], 'namespace' => null];
        }
    }
}
