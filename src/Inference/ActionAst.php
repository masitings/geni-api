<?php

declare(strict_types=1);

namespace Geni\Inference;

use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;

final class ActionAst
{
    /** @var ClassMethod|Closure */
    public Node $node;

    public string $file;

    public int $line;

    /** @var array<string, string> Alias => FQCN use map */
    public array $useImports;

    public ?string $namespace;

    /**
     * The declaring class's own doc comment text, when the action is a
     * controller method (null for closures, which have no enclosing class).
     */
    public ?string $classDocComment;

    /**
     * @param  ClassMethod|Closure  $node
     * @param  array<string, string>  $useImports
     */
    public function __construct(
        Node $node,
        string $file,
        int $line,
        array $useImports = [],
        ?string $namespace = null,
        ?string $classDocComment = null,
    ) {
        $this->node = $node;
        $this->file = $file;
        $this->line = $line;
        $this->useImports = $useImports;
        $this->namespace = $namespace;
        $this->classDocComment = $classDocComment;
    }
}
