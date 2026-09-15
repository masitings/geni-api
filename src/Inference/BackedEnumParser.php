<?php

declare(strict_types=1);

namespace Geni\Inference;

use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Statically parses backed enum classes to extract scalar case values.
 *
 * Framework-free: AST traversal only, no enum instantiation or autoloading side effects.
 */
final class BackedEnumParser
{
    private Parser $parser;

    private NodeFinder $finder;

    public function __construct(?Parser $parser = null, ?NodeFinder $finder = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
        $this->finder = $finder ?? new NodeFinder;
    }

    /**
     * @param  string  $enumClass  FQCN e.g. 'App\Enums\PostStatus'
     * @param  string|null  $filePath  Optional explicit file path
     * @return array{type: 'string'|'integer'|null, cases: list<string|int>, diagnostics: list<InferenceDiagnostic>}
     */
    public function parseCases(string $enumClass, ?string $filePath = null): array
    {
        $file = $filePath ?? $this->resolveClassFile($enumClass);
        if ($file === null || ! is_file($file)) {
            return [
                'type' => null,
                'cases' => [],
                'diagnostics' => [
                    new InferenceDiagnostic('unknown', 0, sprintf('Could not locate file for enum %s', $enumClass)),
                ],
            ];
        }

        try {
            $code = file_get_contents($file);
            if ($code === false) {
                return ['type' => null, 'cases' => [], 'diagnostics' => []];
            }

            $stmts = $this->parser->parse($code);
            if ($stmts === null) {
                return ['type' => null, 'cases' => [], 'diagnostics' => []];
            }

            /** @var Enum_|null $enumNode */
            $enumNode = $this->finder->findFirstInstanceOf($stmts, Enum_::class);
            if ($enumNode === null) {
                return [
                    'type' => null,
                    'cases' => [],
                    'diagnostics' => [
                        new InferenceDiagnostic($file, 1, sprintf('Class %s is not an enum', $enumClass)),
                    ],
                ];
            }

            $backingType = $enumNode->scalarType ? $enumNode->scalarType->toString() : null;
            $type = ($backingType === 'int' || $backingType === 'integer') ? 'integer' : ($backingType === 'string' ? 'string' : null);

            $cases = [];
            foreach ($enumNode->stmts as $stmt) {
                if ($stmt instanceof EnumCase && $stmt->expr !== null) {
                    if ($stmt->expr instanceof String_) {
                        $cases[] = $stmt->expr->value;
                    } elseif ($stmt->expr instanceof Int_) {
                        $cases[] = $stmt->expr->value;
                    }
                }
            }

            return [
                'type' => $type,
                'cases' => $cases,
                'diagnostics' => [],
            ];
        } catch (\Throwable $e) {
            return [
                'type' => null,
                'cases' => [],
                'diagnostics' => [
                    new InferenceDiagnostic($file, 1, sprintf('Failed to parse enum %s: %s', $enumClass, $e->getMessage())),
                ],
            ];
        }
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
}
