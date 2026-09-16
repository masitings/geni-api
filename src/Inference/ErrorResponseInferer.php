<?php

declare(strict_types=1);

namespace Geni\Inference;

use Geni\Inference\Document\Schema;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\NodeFinder;

/**
 * Infers error responses (422, 403, 404, abort codes, @throws) from action AST.
 *
 * Framework-free.
 */
final class ErrorResponseInferer
{
    private NodeFinder $finder;

    /** @var list<ExceptionToResponse> */
    private array $customExceptionMappers;

    /**
     * @param  list<ExceptionToResponse>  $customExceptionMappers
     */
    public function __construct(?NodeFinder $finder = null, array $customExceptionMappers = [])
    {
        $this->finder = $finder ?? new NodeFinder;
        $this->customExceptionMappers = $customExceptionMappers;
    }

    /**
     * @param  bool  $hasValidation  True if 422 should be included
     * @param  bool  $hasModelBinding  True if 404 should be included
     * @return array<string, array{description: string, content?: array<string, mixed>}> Keyed by HTTP status code string
     */
    public function infer(
        ?ActionAst $ast,
        bool $hasValidation = false,
        bool $hasModelBinding = false,
    ): array {
        $responses = [];

        // FR-017: 422 on validation present
        if ($hasValidation) {
            $responses['422'] = [
                'description' => 'Validation error',
                'content' => [
                    'application/json' => [
                        'schema' => $this->createErrorSchema('The given data was invalid.'),
                    ],
                ],
            ];
        }

        // FR-019: 404 on route-model-binding present
        if ($hasModelBinding) {
            $responses['404'] = [
                'description' => 'Resource not found',
                'content' => [
                    'application/json' => [
                        'schema' => $this->createErrorSchema('Not Found'),
                    ],
                ],
            ];
        }

        if ($ast === null) {
            return $responses;
        }

        // FR-018: 403 when $this->authorize(...) or Gate::authorize/allows present
        if ($this->hasAuthorizationCall($ast->node)) {
            $responses['403'] = [
                'description' => 'Forbidden / unauthorized action',
                'content' => [
                    'application/json' => [
                        'schema' => $this->createErrorSchema('This action is unauthorized.'),
                    ],
                ],
            ];
        }

        // 403 when action class contains an authorize() method
        if (! isset($responses['403']) && $this->hasActionAuthorization($ast)) {
            $responses['403'] = [
                'description' => 'This action is unauthorized.',
                'content' => [
                    'application/json' => [
                        'schema' => $this->createErrorSchema('This action is unauthorized.'),
                    ],
                ],
            ];
        }

        // FR-020: abort($code), abort_if($cond, $code), abort_unless($cond, $code)
        $abortCodes = $this->extractAbortStatusCodes($ast->node);
        foreach ($abortCodes as $code) {
            $codeStr = (string) $code;
            if (! isset($responses[$codeStr])) {
                $responses[$codeStr] = [
                    'description' => sprintf('HTTP %d response', $code),
                    'content' => [
                        'application/json' => [
                            'schema' => $this->createErrorSchema('Error message'),
                        ],
                    ],
                ];
            }
        }

        // FR-021: @throws docblock tags
        $docThrows = $this->extractThrowsFromDocblock($ast);
        foreach ($docThrows as $status) {
            $codeStr = (string) $status;
            if (! isset($responses[$codeStr])) {
                $responses[$codeStr] = [
                    'description' => sprintf('Error response (HTTP %d)', $status),
                    'content' => [
                        'application/json' => [
                            'schema' => $this->createErrorSchema('Error'),
                        ],
                    ],
                ];
            }
        }

        return $responses;
    }

    private function hasAuthorizationCall(Node $node): bool
    {
        // 1. $this->authorize(...)
        $calls = $this->finder->find($node, function (Node $n) {
            if ($n instanceof MethodCall && $n->var instanceof Variable && $n->var->name === 'this') {
                return $n->name instanceof Node\Identifier && in_array($n->name->toString(), ['authorize', 'authorizeForUser'], true);
            }
            if ($n instanceof StaticCall && $n->class instanceof Name) {
                $classStr = $n->class->toString();
                if (str_ends_with($classStr, 'Gate')) {
                    return $n->name instanceof Node\Identifier && in_array($n->name->toString(), ['authorize', 'allows', 'check', 'any'], true);
                }
            }

            return false;
        });

        return count($calls) > 0;
    }

    /**
     * @return list<int>
     */
    private function extractAbortStatusCodes(Node $node): array
    {
        $codes = [];

        $calls = $this->finder->find($node, function (Node $n) {
            if ($n instanceof FuncCall && $n->name instanceof Name) {
                return in_array($n->name->toString(), ['abort', 'abort_if', 'abort_unless'], true);
            }

            return false;
        });

        foreach ($calls as $call) {
            $fnName = $call->name->toString();
            // abort($code) -> arg 0 is status code
            // abort_if($cond, $code) -> arg 1 is status code
            // abort_unless($cond, $code) -> arg 1 is status code
            $codeArgIdx = ($fnName === 'abort') ? 0 : 1;

            if (isset($call->args[$codeArgIdx]) && $call->args[$codeArgIdx]->value instanceof Int_) {
                $codes[] = $call->args[$codeArgIdx]->value->value;
            }
        }

        return array_unique($codes);
    }

    /**
     * @return list<int>
     */
    private function extractThrowsFromDocblock(ActionAst $ast): array
    {
        $statuses = [];
        $doc = $ast->node->getDocComment();
        if ($doc === null) {
            return [];
        }

        $text = $doc->getText();
        preg_match_all('/@throws\s+([A-Za-z0-9_\\\\]+)/', $text, $matches);

        foreach ($matches[1] as $exceptionClass) {
            // Check custom mappers first
            foreach ($this->customExceptionMappers as $mapper) {
                if ($mapper->matches($exceptionClass)) {
                    $st = $mapper->statusFor($exceptionClass);
                    if ($st !== null) {
                        $statuses[] = $st;
                    }

                    continue 2;
                }
            }

            // Built-in exception mappings (FR-021)
            $shortName = substr(strrchr('\\'.$exceptionClass, '\\'), 1);

            $status = match ($shortName) {
                'ValidationException' => 422,
                'AuthorizationException', 'AccessDeniedHttpException' => 403,
                'AuthenticationException', 'UnauthorizedHttpException' => 401,
                'ModelNotFoundException', 'NotFoundHttpException' => 404,
                'ConflictHttpException' => 409,
                default => null,
            };

            if ($status !== null) {
                $statuses[] = $status;
            }
        }

        return array_unique($statuses);
    }

    private function createErrorSchema(string $exampleMessage): Schema
    {
        $schema = new Schema;
        $schema->type = 'object';

        $msgSchema = new Schema;
        $msgSchema->type = 'string';
        $msgSchema->default = $exampleMessage;

        $schema->properties = [
            'message' => $msgSchema,
        ];
        $schema->extensions['required'] = ['message'];

        return $schema;
    }

    private function hasActionAuthorization(?ActionAst $ast): bool
    {
        if ($ast === null || ! ($ast->node instanceof Node\Stmt\ClassMethod)) {
            return false;
        }

        if (! is_file($ast->file)) {
            return false;
        }

        $detector = new LaravelActionDetector(null, $this->finder);
        $parsed = $detector->parseFileAndExtractClass($ast->file, '');
        $classNode = $parsed['class'];
        if ($classNode === null) {
            return false;
        }

        if (! $detector->isActionClass($classNode, $ast->useImports)) {
            return false;
        }

        return $detector->hasAuthorizeMethod($classNode);
    }
}
