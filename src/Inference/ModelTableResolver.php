<?php

declare(strict_types=1);

namespace Geni\Inference;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Resolves a model class name to its database table name.
 *
 * Framework-free: Does not use Illuminate\Support\Str or instantiate any model.
 * Looks for `protected $table = '...'` in AST if source is available, otherwise
 * falls back to snake_case pluralization convention.
 */
final class ModelTableResolver
{
    private Parser $parser;

    private NodeFinder $nodeFinder;

    public function __construct(?Parser $parser = null, ?NodeFinder $nodeFinder = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
        $this->nodeFinder = $nodeFinder ?? new NodeFinder;
    }

    /**
     * Resolve the table name for a model class.
     *
     * @param  string  $modelClass  e.g. 'App\Models\Post'
     * @param  string|null  $sourceCode  Optional raw source code of the model class
     */
    public function resolve(string $modelClass, ?string $sourceCode = null): string
    {
        if ($sourceCode !== null) {
            $tableFromAst = $this->resolveFromSource($sourceCode);
            if ($tableFromAst !== null) {
                return $tableFromAst;
            }
        }

        // Fallback: Eloquent naming convention (snake_case plural)
        $shortName = $this->getShortName($modelClass);

        return $this->pluralize($this->snakeCase($shortName));
    }

    /**
     * Parse source code looking for a literal `$table` property on the class.
     */
    public function resolveFromSource(string $sourceCode): ?string
    {
        try {
            $stmts = $this->parser->parse($sourceCode);
            if ($stmts === null) {
                return null;
            }

            /** @var Class_|null $class */
            $class = $this->nodeFinder->findFirstInstanceOf($stmts, Class_::class);
            if ($class === null) {
                return null;
            }

            foreach ($class->stmts as $stmt) {
                if ($stmt instanceof Property) {
                    foreach ($stmt->props as $prop) {
                        if ($prop->name->toString() === 'table' && $prop->default instanceof Node\Scalar\String_) {
                            return $prop->default->value;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Get class base name from FQCN.
     */
    public function getShortName(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts);
    }

    /**
     * Convert string to snake_case.
     */
    public function snakeCase(string $value): string
    {
        $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? $value);

        return $key;
    }

    /**
     * Minimal English pluralizer for common Eloquent conventions.
     */
    public function pluralize(string $value): string
    {
        $rules = [
            '/(quiz)$/i' => '$1zes',
            '/^(ox)$/i' => '$1en',
            '/([m|l])ouse$/i' => '$1ice',
            '/(matr|vert|ind)ix|ex$/i' => '$1ices',
            '/(x|ch|ss|sh)$/i' => '$1es',
            '/([^aeiouy]|qu)y$/i' => '$1ies',
            '/(hive)$/i' => '$1s',
            '/(?:([^f])fe|([lr])f)$/i' => '$1$2ves',
            '/(shea|lea|loa|thie)f$/i' => '$1ves',
            '/sis$/i' => 'ses',
            '/([ti])um$/i' => '$1a',
            '/(buffal|tomat)o$/i' => '$1oes',
            '/(bu)s$/i' => '$1ses',
            '/(alias|status)$/i' => '$1es',
            '/(octop|vir)us$/i' => '$1i',
            '/(ax|test)is$/i' => '$1es',
            '/s$/i' => 's',
            '/$/' => 's',
        ];

        foreach ($rules as $pattern => $replacement) {
            if (preg_match($pattern, $value)) {
                return preg_replace($pattern, $replacement, $value) ?? $value;
            }
        }

        return $value.'s';
    }
}
