<?php

declare(strict_types=1);

namespace Geni\Inference;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * AST-based detector for classes implementing the Laravel Actions pattern
 * (lorisleiva/laravel-actions).
 *
 * Framework-free: uses php-parser and AST traversal only, zero application booting.
 */
final class LaravelActionDetector
{
    private Parser $parser;

    private NodeFinder $finder;

    /** @var array<string, array{class: ?Class_, imports: array<string, string>, namespace: ?string}> */
    private static array $fileCache = [];

    /** @var array<string, array{isAction: bool, method: ?string, hasAuthorize: bool, hasRules: bool}> */
    private static array $classMetadataCache = [];

    public function __construct(?Parser $parser = null, ?NodeFinder $finder = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
        $this->finder = $finder ?? new NodeFinder;
    }

    public static function clearCache(): void
    {
        self::$fileCache = [];
        self::$classMetadataCache = [];
    }

    /**
     * Determine whether an AST Class_ node represents a Laravel Action.
     *
     * @param  array<string, string>  $useImports
     */
    public function isActionClass(Class_ $classNode, array $useImports = []): bool
    {
        // 1. Check if the class uses Lorisleiva\Actions\Concerns\AsAction trait
        if ($this->hasAsActionTrait($classNode, $useImports)) {
            return true;
        }

        // 2. Check if the class defines asController() or handle()
        if ($classNode->stmts !== null) {
            foreach ($classNode->stmts as $stmt) {
                if ($stmt instanceof ClassMethod && $stmt->isPublic()) {
                    $name = $stmt->name->toString();
                    if ($name === 'asController' || $name === 'handle') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Resolve the action method to analyze for controller behavior.
     *
     * Prefers 'asController' over 'handle' when both exist, matching Laravel Actions
     * routing resolution. Falls back to '__invoke' or null.
     */
    public function resolveActionMethod(Class_ $classNode): ?string
    {
        if ($classNode->stmts === null) {
            return null;
        }

        $hasAsController = false;
        $hasHandle = false;
        $hasInvoke = false;

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->isPublic()) {
                $name = $stmt->name->toString();
                if ($name === 'asController') {
                    $hasAsController = true;
                } elseif ($name === 'handle') {
                    $hasHandle = true;
                } elseif ($name === '__invoke') {
                    $hasInvoke = true;
                }
            }
        }

        if ($hasAsController) {
            return 'asController';
        }

        if ($hasHandle) {
            return 'handle';
        }

        if ($hasInvoke) {
            return '__invoke';
        }

        return null;
    }

    /**
     * Check if a public or protected authorize() method is present with non-empty logic.
     *
     * Returns false if the method is missing or contains only an unconditional "return true;".
     */
    public function hasAuthorizeMethod(Class_ $classNode): bool
    {
        if ($classNode->stmts === null) {
            return false;
        }

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === 'authorize') {
                if ($stmt->isPrivate() || $stmt->stmts === null || $stmt->stmts === []) {
                    return false;
                }

                // Check if the only statement is "return true;"
                if (count($stmt->stmts) === 1 && $stmt->stmts[0] instanceof Return_) {
                    $expr = $stmt->stmts[0]->expr;
                    if ($expr instanceof ConstFetch && strtolower($expr->name->toString()) === 'true') {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Check if a public static or public instance rules() method is present on the class.
     */
    public function hasRulesMethod(Class_ $classNode): bool
    {
        if ($classNode->stmts === null) {
            return false;
        }

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->isPublic() && $stmt->name->toString() === 'rules') {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect Action metadata for a class by FQCN.
     *
     * @return array{isAction: bool, method: ?string, hasAuthorize: bool, hasRules: bool, classNode: ?Class_, file: ?string}|null
     */
    public function detectForClass(string $className, ?string $filePath = null): ?array
    {
        if (isset(self::$classMetadataCache[$className])) {
            $cached = self::$classMetadataCache[$className];
            $file = $filePath ?? $this->resolveClassFile($className);
            $classNode = $file !== null ? ($this->parseFileAndExtractClass($file, $className)['class'] ?? null) : null;

            return array_merge($cached, ['classNode' => $classNode, 'file' => $file]);
        }

        $file = $filePath ?? $this->resolveClassFile($className);
        if ($file === null || ! is_file($file)) {
            return null;
        }

        $parsed = $this->parseFileAndExtractClass($file, $className);
        $classNode = $parsed['class'];
        if ($classNode === null) {
            return null;
        }

        $useImports = $parsed['imports'];
        $isAction = $this->isActionClass($classNode, $useImports);
        $method = $this->resolveActionMethod($classNode);
        $hasAuthorize = $this->hasAuthorizeMethod($classNode);
        $hasRules = $this->hasRulesMethod($classNode);

        $metadata = [
            'isAction' => $isAction,
            'method' => $method,
            'hasAuthorize' => $hasAuthorize,
            'hasRules' => $hasRules,
        ];

        self::$classMetadataCache[$className] = $metadata;

        return array_merge($metadata, ['classNode' => $classNode, 'file' => $file]);
    }

    /**
     * Check if AsAction trait is used in the class.
     *
     * @param  array<string, string>  $useImports
     */
    private function hasAsActionTrait(Class_ $classNode, array $useImports): bool
    {
        if ($classNode->stmts === null) {
            return false;
        }

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $traitName = $trait->toString();
                    if ($traitName === 'Lorisleiva\Actions\Concerns\AsAction' || $traitName === '\Lorisleiva\Actions\Concerns\AsAction') {
                        return true;
                    }

                    if ($traitName === 'AsAction') {
                        $resolved = $useImports['AsAction'] ?? null;
                        if ($resolved === 'Lorisleiva\Actions\Concerns\AsAction' || $resolved === null) {
                            return true;
                        }
                    }

                    if (str_ends_with($traitName, 'AsAction')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Resolve the filesystem path for a class name.
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
                // Ignore and fall back to file discovery
            }
        }

        $cleanClass = ltrim($className, '\\');
        $relativePath = str_replace('\\', '/', $cleanClass).'.php';

        $possiblePaths = [
            $relativePath,
            __DIR__.'/../../../../src/'.$relativePath,
            __DIR__.'/../../../../app/'.$relativePath,
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
