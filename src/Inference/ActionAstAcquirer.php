<?php

declare(strict_types=1);

namespace Geni\Inference;

use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Given an action descriptor (Controller@method or closure file:line), locates
 * the defining file and returns the AST node (ClassMethod or Closure) plus
 * import table for resolving short class names.
 *
 * Framework-free: php-parser + filesystem only.
 */
final class ActionAstAcquirer
{
    private Parser $parser;

    private NodeFinder $finder;

    public function __construct(?Parser $parser = null, ?NodeFinder $finder = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
        $this->finder = $finder ?? new NodeFinder;
    }

    /**
     * @param  string  $className  e.g. 'Tests\\Fixtures\\App\\Http\\Controllers\\PostController'
     * @param  string  $methodName  e.g. 'show'
     * @param  string|null  $filePath  Optional absolute path to class file
     */
    public function fromControllerMethod(
        string $className,
        string $methodName,
        ?string $filePath = null
    ): ?ActionAst {
        $file = $filePath ?? $this->resolveClassFile($className);

        if ($file === null || ! is_file($file)) {
            return null;
        }

        $useStmtImports = $this->extractUseImports($file);

        return $this->parseClassMethod($file, $className, $methodName, $useStmtImports['namespace'] ?? null, $useStmtImports['imports'] ?? []);
    }

    /**
     * @param  string  $filePath  absolute path to a PHP file containing a closure
     * @param  int|null  $lineNumber  Optional hint where closure starts
     */
    public function fromClosure(
        string $filePath,
        ?int $lineNumber = null
    ): ?ActionAst {
        if (! is_file($filePath)) {
            return null;
        }

        $useStmtImports = $this->extractUseImports($filePath);

        return $this->parseClosure($filePath, $lineNumber, $useStmtImports['namespace'] ?? null, $useStmtImports['imports'] ?? []);
    }

    /**
     * Extract namespace + use-statement imports from a file.
     *
     * @return array{namespace: string|null, imports: array<string, string>}
     */
    private function extractUseImports(string $filePath): array
    {
        $stmts = $this->parseFile($filePath);
        if ($stmts === null) {
            return ['namespace' => null, 'imports' => []];
        }

        $imports = [];
        $namespace = null;
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
            }
        }

        return [
            'namespace' => $namespace,
            'imports' => $imports,
        ];
    }

    /**
     * @param  array<string, string>  $imports
     */
    private function parseClassMethod(string $filePath, string $className, string $methodName, ?string $namespace, array $imports): ?ActionAst
    {
        $stmts = $this->parseFile($filePath);
        if ($stmts === null) {
            return null;
        }

        $classNode = $this->finder->findFirstInstanceOf($stmts, Class_::class);
        if ($classNode === null) {
            return null;
        }

        // Verify FQCN matches
        $resolvedName = $this->getFullyQualifiedClassName($classNode, $namespace);
        if ($resolvedName !== $className) {
            return null;
        }

        if ($classNode->stmts === null) {
            return null;
        }

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === $methodName) {
                return new ActionAst(
                    node: $stmt,
                    file: $filePath,
                    line: $stmt->getLine(),
                    useImports: $imports,
                    namespace: $namespace,
                    classDocComment: $classNode->getDocComment()?->getText(),
                );
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $imports
     */
    private function parseClosure(string $filePath, ?int $lineNumber, ?string $namespace, array $imports): ?ActionAst
    {
        $stmts = $this->parseFile($filePath);
        if ($stmts === null) {
            return null;
        }

        $closureNodes = $this->finder->findInstanceOf($stmts, Closure::class);

        if (empty($closureNodes)) {
            return null;
        }

        if ($lineNumber !== null) {
            $best = null;
            $bestLine = PHP_INT_MAX;
            foreach ($closureNodes as $c) {
                $dist = abs($c->getLine() - $lineNumber);
                if ($dist < $bestLine) {
                    $bestLine = $dist;
                    $best = $c;
                }
            }

            return $best !== null
                ? new ActionAst($best, $filePath, $best->getLine(), $imports, $namespace)
                : null;
        }

        $closure = $closureNodes[0];

        return new ActionAst($closure, $filePath, $closure->getLine(), $imports, $namespace);
    }

    private function getFullyQualifiedClassName(Class_ $classNode, ?string $namespace): string
    {
        if (isset($classNode->namespacedName) && $classNode->namespacedName !== null) {
            return $classNode->namespacedName->toString();
        }

        if ($namespace !== null) {
            return $namespace.'\\'.$classNode->name->toString();
        }

        return $classNode->name->toString();
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

    /**
     * @return array<null|Node>|null
     */
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
