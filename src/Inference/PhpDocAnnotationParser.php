<?php

declare(strict_types=1);

namespace Geni\Inference;

use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Parses PHPDoc docblocks using phpstan/phpdoc-parser into structured metadata.
 *
 * Implements scramble_compatibility.md section 3 matrix.
 *
 * Framework-free.
 */
final class PhpDocAnnotationParser
{
    private PhpDocParser $parser;

    private Lexer $lexer;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->parser = new PhpDocParser($config, $typeParser, $constExprParser);
    }

    /**
     * Parse method-level PHPDoc.
     *
     * @return array{
     *     response?: array{status: int, type: string, description: ?string},
     *     description?: string,
     *     operationId?: string,
     *     requestMediaType?: string,
     *     deprecated?: bool,
     *     deprecatedMessage?: ?string,
     *     notDeprecated?: bool,
     *     unauthenticated?: bool,
     *     ignoredParams: list<string>,
     *     tags: list<string>,
     *     throws: list<string>
     * }
     */
    public function parseMethodDocblock(?string $docComment): array
    {
        $result = [
            'ignoredParams' => [],
            'tags' => [],
            'throws' => [],
        ];

        if ($docComment === null || trim($docComment) === '') {
            return $result;
        }

        try {
            $tokens = new TokenIterator($this->lexer->tokenize($docComment));
            $phpDocNode = $this->parser->parse($tokens);
        } catch (\Throwable) {
            return $result;
        }

        $pendingStatus = null;
        $pendingBody = null;

        foreach ($phpDocNode->getTags() as $tag) {
            $name = $tag->name;
            $valStr = trim((string) $tag->value);

            // @response [status] <type> [description]
            if ($name === '@response') {
                $parsedResponse = $this->parseResponseTag($valStr);
                if ($parsedResponse !== null) {
                    $result['response'] = $parsedResponse;
                }

                continue;
            }

            // @status <int>
            if ($name === '@status' && is_numeric($valStr)) {
                $pendingStatus = (int) $valStr;

                continue;
            }

            // @body <type>
            if ($name === '@body') {
                $pendingBody = $valStr;

                continue;
            }

            // @description <text>
            if ($name === '@description') {
                $result['description'] = $valStr;

                continue;
            }

            // @operationId <id>
            if ($name === '@operationId') {
                $result['operationId'] = $valStr;

                continue;
            }

            // @requestMediaType <type>
            if ($name === '@requestMediaType') {
                $result['requestMediaType'] = $valStr;

                continue;
            }

            // @deprecated [message]
            if ($name === '@deprecated') {
                $result['deprecated'] = true;
                if ($valStr !== '') {
                    $result['deprecatedMessage'] = $valStr;
                }

                continue;
            }

            // @notDeprecated
            if ($name === '@notDeprecated') {
                $result['notDeprecated'] = true;

                continue;
            }

            // @unauthenticated
            if ($name === '@unauthenticated') {
                $result['unauthenticated'] = true;

                continue;
            }

            // @ignoreParam <name>
            if ($name === '@ignoreParam') {
                $pName = ltrim($valStr, '$');
                if ($pName !== '') {
                    $result['ignoredParams'][] = $pName;
                }

                continue;
            }

            // @tags Tag1, Tag2
            if ($name === '@tags') {
                foreach (explode(',', $valStr) as $t) {
                    $trimmed = trim($t);
                    if ($trimmed !== '') {
                        $result['tags'][] = $trimmed;
                    }
                }

                continue;
            }

            // @throws <Exception>
            if ($name === '@throws') {
                $parts = preg_split('/\s+/', $valStr);
                if (! empty($parts[0])) {
                    $result['throws'][] = $parts[0];
                }

                continue;
            }
        }

        // Pair @status and @body if present
        if ($pendingStatus !== null && $pendingBody !== null && ! isset($result['response'])) {
            $result['response'] = [
                'status' => $pendingStatus,
                'type' => $pendingBody,
                'description' => $result['description'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Parse class-level PHPDoc.
     *
     * @return array{
     *     tags: list<string>,
     *     deprecated?: bool,
     *     backingModel?: string
     * }
     */
    public function parseClassDocblock(?string $docComment): array
    {
        $result = [
            'tags' => [],
        ];

        if ($docComment === null || trim($docComment) === '') {
            return $result;
        }

        try {
            $tokens = new TokenIterator($this->lexer->tokenize($docComment));
            $phpDocNode = $this->parser->parse($tokens);
        } catch (\Throwable) {
            return $result;
        }

        foreach ($phpDocNode->getTags() as $tag) {
            $name = $tag->name;
            $valStr = trim((string) $tag->value);

            if ($name === '@tags') {
                foreach (explode(',', $valStr) as $t) {
                    $trimmed = trim($t);
                    if ($trimmed !== '') {
                        $result['tags'][] = $trimmed;
                    }
                }

                continue;
            }

            if ($name === '@deprecated') {
                $result['deprecated'] = true;

                continue;
            }

            // @mixin <Model> (FR-009)
            if ($name === '@mixin' && $valStr !== '') {
                $parts = preg_split('/\s+/', $valStr);
                $result['backingModel'] = $parts[0];

                continue;
            }

            // @property <Model> $resource or @property-read <Model> $resource
            if (($name === '@property' || $name === '@property-read') && str_contains($valStr, '$resource')) {
                $parts = preg_split('/\s+/', $valStr);
                if (isset($parts[0]) && $parts[0] !== '') {
                    $result['backingModel'] = $parts[0];
                }

                continue;
            }
        }

        return $result;
    }

    /**
     * Parse field/property-level PHPDoc (e.g. above an array key in validate() or resource).
     *
     * @return array{
     *     type?: string,
     *     example?: mixed,
     *     format?: string,
     *     default?: mixed,
     *     query?: bool,
     *     hidden?: bool,
     *     ignoreParam?: bool
     * }
     */
    public function parseFieldDocblock(?string $docComment): array
    {
        $result = [];

        if ($docComment === null || trim($docComment) === '') {
            return $result;
        }

        try {
            $tokens = new TokenIterator($this->lexer->tokenize($docComment));
            $phpDocNode = $this->parser->parse($tokens);
        } catch (\Throwable) {
            return $result;
        }

        foreach ($phpDocNode->getTags() as $tag) {
            $name = $tag->name;
            $valStr = trim((string) $tag->value);

            // @var <type>
            if ($name === '@var' && $valStr !== '') {
                $parts = preg_split('/\s+/', $valStr);
                $result['type'] = $parts[0];

                continue;
            }

            // @example <val>
            if ($name === '@example' && $valStr !== '') {
                $decoded = json_decode($valStr, true);
                $result['example'] = json_last_error() === JSON_ERROR_NONE ? $decoded : $valStr;

                continue;
            }

            // @format <format>
            if ($name === '@format' && $valStr !== '') {
                $result['format'] = $valStr;

                continue;
            }

            // @default <val>
            if ($name === '@default' && $valStr !== '') {
                $decoded = json_decode($valStr, true);
                $result['default'] = json_last_error() === JSON_ERROR_NONE ? $decoded : $valStr;

                continue;
            }

            // @query
            if ($name === '@query') {
                $result['query'] = true;

                continue;
            }

            // @hidden
            if ($name === '@hidden') {
                $result['hidden'] = true;

                continue;
            }

            // @ignoreParam
            if ($name === '@ignoreParam') {
                $result['ignoreParam'] = true;

                continue;
            }
        }

        return $result;
    }

    /**
     * Parse @response [status] <type> [description]
     *
     * @return array{status: int, type: string, description: ?string}|null
     */
    private function parseResponseTag(string $value): ?array
    {
        $parts = preg_split('/\s+/', trim($value), 3);
        if (empty($parts) || $parts[0] === '') {
            return null;
        }

        $status = 200;
        $type = null;
        $description = null;

        if (is_numeric($parts[0])) {
            $status = (int) $parts[0];
            $type = $parts[1] ?? '';
            $description = $parts[2] ?? null;
        } else {
            $type = $parts[0];
            $description = $parts[1] ?? null;
        }

        if ($type === '') {
            return null;
        }

        return [
            'status' => $status,
            'type' => $type,
            'description' => $description,
        ];
    }
}
