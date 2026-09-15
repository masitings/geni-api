<?php

declare(strict_types=1);

namespace Geni\Inference;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Extracts validation rules from controller action body AST, Form Requests,
 * Validator::make calls, and request accessor methods.
 *
 * Framework-free: uses php-parser and AST traversal only.
 */
final class ValidationRuleExtractor
{
    private NodeFinder $nodeFinder;

    private Parser $parser;

    public function __construct(?NodeFinder $nodeFinder = null, ?Parser $parser = null)
    {
        $this->nodeFinder = $nodeFinder ?? new NodeFinder;
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
    }

    /**
     * @return array{
     *     fields: array<string, string|list<string>>,
     *     accessors: array<string, array{type: string, default: mixed}>,
     *     diagnostics: list<InferenceDiagnostic>
     * }
     */
    public function extractFromActionAst(ActionAst $ast): array
    {
        $results = [
            'fields' => [],
            'accessors' => [],
            'diagnostics' => [],
        ];

        // 1. Check for Form Request parameters in ClassMethod signature (FR-001)
        if ($ast->node instanceof ClassMethod) {
            $formRequestRules = $this->extractFromFormRequestParams($ast);
            foreach ($formRequestRules['fields'] as $field => $rules) {
                $results['fields'][$field] = $rules;
            }
            foreach ($formRequestRules['diagnostics'] as $d) {
                $results['diagnostics'][] = $d;
            }
        }

        // 2. Find validate() calls on $request or $this (FR-001/Phase 3)
        $validateCalls = $this->nodeFinder->find($ast->node, function (Node $node) {
            return $node instanceof MethodCall
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toString(), ['validate', 'validateWithBag'], true);
        });

        foreach ($validateCalls as $call) {
            if (! self::isRequestCall($call)) {
                continue;
            }

            if (! isset($call->args[0]) || ! $call->args[0] instanceof Arg) {
                continue;
            }

            $rulesArray = $call->args[0]->value;
            if (! ($rulesArray instanceof Array_)) {
                $results['diagnostics'][] = new InferenceDiagnostic(
                    $ast->file,
                    $call->args[0]->value->getLine(),
                    'Non-literal rules array passed to validate(): rules not extracted',
                );

                continue;
            }

            $extracted = $this->extractFieldsFromLiteralArray($rulesArray, $ast);
            foreach ($extracted['fields'] as $field => $rules) {
                $results['fields'][$field] = $rules;
            }
            foreach ($extracted['diagnostics'] as $diagnostic) {
                $results['diagnostics'][] = $diagnostic;
            }
        }

        // 3. Find Validator::make($data, $rules) calls (FR-002)
        $validatorCalls = $this->nodeFinder->find($ast->node, function (Node $node) {
            if ($node instanceof StaticCall && $node->name instanceof Node\Identifier && $node->name->toString() === 'make') {
                $className = $node->class instanceof Name ? $node->class->toString() : '';

                return str_ends_with($className, 'Validator');
            }
            if ($node instanceof FuncCall && $node->name instanceof Name && $node->name->toString() === 'validator') {
                return count($node->args) >= 2;
            }

            return false;
        });

        foreach ($validatorCalls as $vCall) {
            // Second argument is the rules array
            if (! isset($vCall->args[1]) || ! $vCall->args[1] instanceof Arg) {
                continue;
            }

            $rulesVal = $vCall->args[1]->value;
            if (! ($rulesVal instanceof Array_)) {
                $results['diagnostics'][] = new InferenceDiagnostic(
                    $ast->file,
                    $rulesVal->getLine(),
                    'Non-literal rules array passed to Validator::make(): rules not extracted'
                );

                continue;
            }

            $extracted = $this->extractFieldsFromLiteralArray($rulesVal, $ast);
            foreach ($extracted['fields'] as $field => $rules) {
                $results['fields'][$field] = $rules;
            }
            foreach ($extracted['diagnostics'] as $diagnostic) {
                $results['diagnostics'][] = $diagnostic;
            }
        }

        // 4. Find request accessor methods: $request->integer('name', default), etc. (FR-010)
        $accessorCalls = $this->nodeFinder->find($ast->node, function (Node $node) {
            return $node instanceof MethodCall
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toString(), ['integer', 'string', 'boolean', 'float'], true)
                && self::isRequestCall($node);
        });

        foreach ($accessorCalls as $accCall) {
            if (! isset($accCall->args[0]) || ! $accCall->args[0]->value instanceof String_) {
                continue;
            }

            $paramName = $accCall->args[0]->value->value;
            $methodType = $accCall->name->toString();
            $schemaType = match ($methodType) {
                'integer' => 'integer',
                'float' => 'number',
                'boolean' => 'boolean',
                default => 'string',
            };

            $defaultVal = null;
            if (isset($accCall->args[1])) {
                $defNode = $accCall->args[1]->value;
                if ($defNode instanceof String_) {
                    $defaultVal = $defNode->value;
                } elseif ($defNode instanceof Int_) {
                    $defaultVal = $defNode->value;
                } elseif ($defNode instanceof Float_) {
                    $defaultVal = $defNode->value;
                } elseif ($defNode instanceof Node\Expr\ConstFetch) {
                    $valLower = strtolower($defNode->name->toString());
                    if ($valLower === 'true') {
                        $defaultVal = true;
                    } elseif ($valLower === 'false') {
                        $defaultVal = false;
                    } elseif ($valLower === 'null') {
                        $defaultVal = null;
                    }
                } else {
                    $results['diagnostics'][] = new InferenceDiagnostic(
                        $ast->file,
                        $defNode->getLine(),
                        sprintf('Non-literal default value passed to $request->%s(\'%s\')', $methodType, $paramName)
                    );
                }
            }

            $results['accessors'][$paramName] = [
                'type' => $schemaType,
                'default' => $defaultVal,
            ];
        }

        return $results;
    }

    /**
     * Inspect ClassMethod parameters for classes extending FormRequest.
     *
     * @return array{fields: array<string, string|list<string>>, diagnostics: list<InferenceDiagnostic>}
     */
    private function extractFromFormRequestParams(ActionAst $ast): array
    {
        $results = ['fields' => [], 'diagnostics' => []];

        /** @var ClassMethod $method */
        $method = $ast->node;
        foreach ($method->params as $param) {
            if ($param->type instanceof Name) {
                $paramClass = $this->resolveFqcn($param->type, $ast);
                if ($this->isFormRequestClass($paramClass)) {
                    $formRules = $this->parseFormRequestRules($paramClass, $ast);
                    foreach ($formRules['fields'] as $f => $r) {
                        $results['fields'][$f] = $r;
                    }
                    foreach ($formRules['diagnostics'] as $d) {
                        $results['diagnostics'][] = $d;
                    }
                }
            }
        }

        return $results;
    }

    private function isFormRequestClass(string $className): bool
    {
        if (str_ends_with($className, 'Request') || is_subclass_of($className, 'Illuminate\Foundation\Http\FormRequest')) {
            return true;
        }

        // Check AST of class file
        $file = $this->resolveClassFile($className);
        if ($file === null || ! is_file($file)) {
            return false;
        }

        $code = file_get_contents($file);
        if ($code === false) {
            return false;
        }

        try {
            $stmts = $this->parser->parse($code);
            if ($stmts === null) {
                return false;
            }

            /** @var Class_|null $class */
            $class = $this->nodeFinder->findFirstInstanceOf($stmts, Class_::class);
            if ($class === null || $class->extends === null) {
                return false;
            }

            $extendsName = $class->extends->toString();

            return str_ends_with($extendsName, 'FormRequest') || str_ends_with($extendsName, 'Request');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Parse the rules() method of a FormRequest class.
     *
     * @return array{fields: array<string, string|list<string>>, diagnostics: list<InferenceDiagnostic>}
     */
    private function parseFormRequestRules(string $className, ActionAst $actionAst): array
    {
        $results = ['fields' => [], 'diagnostics' => []];

        $file = $this->resolveClassFile($className);
        if ($file === null || ! is_file($file)) {
            return $results;
        }

        $code = file_get_contents($file);
        if ($code === false) {
            return $results;
        }

        try {
            $stmts = $this->parser->parse($code);
            if ($stmts === null) {
                return $results;
            }

            /** @var Class_|null $class */
            $class = $this->nodeFinder->findFirstInstanceOf($stmts, Class_::class);
            if ($class === null) {
                return $results;
            }

            $rulesMethod = null;
            foreach ($class->stmts as $stmt) {
                if ($stmt instanceof ClassMethod && $stmt->name->toString() === 'rules') {
                    $rulesMethod = $stmt;
                    break;
                }
            }

            if ($rulesMethod === null || $rulesMethod->stmts === null) {
                return $results;
            }

            // Find Return_ statement in rules()
            $returns = $this->nodeFinder->findInstanceOf($rulesMethod->stmts, Return_::class);
            foreach ($returns as $ret) {
                if ($ret->expr instanceof Array_) {
                    $extracted = $this->extractFieldsFromLiteralArray($ret->expr, $actionAst);
                    foreach ($extracted['fields'] as $f => $r) {
                        $results['fields'][$f] = $r;
                    }
                    foreach ($extracted['diagnostics'] as $d) {
                        $results['diagnostics'][] = $d;
                    }
                } else {
                    $results['diagnostics'][] = new InferenceDiagnostic(
                        $file,
                        $ret->getLine(),
                        sprintf('Non-literal return expression in FormRequest %s::rules(): rules not extracted', $className)
                    );
                }
            }
        } catch (\Throwable $e) {
            $results['diagnostics'][] = new InferenceDiagnostic(
                $file,
                1,
                sprintf('Failed to parse FormRequest %s: %s', $className, $e->getMessage())
            );
        }

        return $results;
    }

    private static function isRequestCall(MethodCall $call): bool
    {
        $var = $call->var;
        if ($var instanceof Variable) {
            $name = $var->name;
            if (is_string($name) && in_array($name, ['request', 'this'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{fields: array<string, string|list<string>>, diagnostics: list<InferenceDiagnostic>}
     */
    private function extractFieldsFromLiteralArray(Array_ $arrayNode, ActionAst $actionAst): array
    {
        $results = ['fields' => [], 'diagnostics' => []];

        foreach ($arrayNode->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->key === null) {
                continue;
            }

            if (! ($item->key instanceof String_)) {
                $results['diagnostics'][] = new InferenceDiagnostic(
                    $actionAst->file,
                    $item->key->getLine(),
                    'Non-string field key in rules array: skipped',
                );

                continue;
            }

            $fieldName = $item->key->value;

            if ($item->value instanceof String_) {
                $results['fields'][$fieldName] = $item->value->value;
            } elseif ($item->value instanceof Array_) {
                $extractedRules = $this->extractArrayOfRules($item->value, $actionAst);
                $results['fields'][$fieldName] = $extractedRules['rules'];
                foreach ($extractedRules['diagnostics'] as $d) {
                    $results['diagnostics'][] = $d;
                }
            } else {
                $results['diagnostics'][] = new InferenceDiagnostic(
                    $actionAst->file,
                    $item->value->getLine(),
                    'Non-literal rule value for field "'.$fieldName.'": skipped',
                );
            }
        }

        return $results;
    }

    /**
     * @return array{rules: list<string>, diagnostics: list<InferenceDiagnostic>}
     */
    private function extractArrayOfRules(Array_ $arrayNode, ActionAst $actionAst): array
    {
        $rules = [];
        $diagnostics = [];

        foreach ($arrayNode->items as $item) {
            if ($item === null) {
                continue;
            }

            $val = $item->value;

            // 1. Literal string rule
            if ($val instanceof String_) {
                $rules[] = $val->value;

                continue;
            }

            // 2. Rule::in([...]) or Rule::in('a', 'b') (FR-004)
            if ($val instanceof StaticCall && $val->name instanceof Node\Identifier && $val->name->toString() === 'in') {
                $inValues = [];
                $isLiteral = true;

                if (isset($val->args[0])) {
                    $firstArg = $val->args[0]->value;
                    if ($firstArg instanceof Array_) {
                        foreach ($firstArg->items as $ai) {
                            if ($ai !== null && ($ai->value instanceof String_ || $ai->value instanceof Int_)) {
                                $inValues[] = (string) $ai->value->value;
                            } else {
                                $isLiteral = false;
                                break;
                            }
                        }
                    } else {
                        // Multi-arg: Rule::in('a', 'b')
                        foreach ($val->args as $arg) {
                            if ($arg->value instanceof String_ || $arg->value instanceof Int_) {
                                $inValues[] = (string) $arg->value->value;
                            } else {
                                $isLiteral = false;
                                break;
                            }
                        }
                    }
                }

                if ($isLiteral && $inValues !== []) {
                    $rules[] = 'in:'.implode(',', $inValues);
                } else {
                    $diagnostics[] = new InferenceDiagnostic(
                        $actionAst->file,
                        $val->getLine(),
                        'Rule::in() called with non-literal arguments: falling back to base type'
                    );
                }

                continue;
            }

            // 3. Rule::exists('table', 'column') (FR-007)
            if ($val instanceof StaticCall && $val->name instanceof Node\Identifier && $val->name->toString() === 'exists') {
                if (isset($val->args[0]) && $val->args[0]->value instanceof String_) {
                    $table = $val->args[0]->value->value;
                    $col = 'id';
                    if (isset($val->args[1]) && $val->args[1]->value instanceof String_) {
                        $col = $val->args[1]->value->value;
                    }
                    $rules[] = sprintf('exists:%s,%s', $table, $col);
                } else {
                    $diagnostics[] = new InferenceDiagnostic(
                        $actionAst->file,
                        $val->getLine(),
                        'Rule::exists() called with non-literal table name'
                    );
                }

                continue;
            }

            // 4. Rule::enum(Status::class) or new Enum(Status::class) (FR-005)
            if (($val instanceof StaticCall && $val->name instanceof Node\Identifier && $val->name->toString() === 'enum')
                || ($val instanceof New_ && $val->class instanceof Name && str_ends_with($val->class->toString(), 'Enum'))
            ) {
                if (isset($val->args[0])) {
                    $enumClassNode = $val->args[0]->value;
                    $enumFqcn = null;
                    if ($enumClassNode instanceof ClassConstFetch && $enumClassNode->class instanceof Name) {
                        $enumFqcn = $this->resolveFqcn($enumClassNode->class, $actionAst);
                    } elseif ($enumClassNode instanceof String_) {
                        $enumFqcn = $enumClassNode->value;
                    }

                    if ($enumFqcn !== null) {
                        $rules[] = 'enum:'.$enumFqcn;
                    } else {
                        $diagnostics[] = new InferenceDiagnostic(
                            $actionAst->file,
                            $val->getLine(),
                            'Rule::enum() argument could not be resolved to a class string'
                        );
                    }
                }

                continue;
            }
        }

        return ['rules' => $rules, 'diagnostics' => $diagnostics];
    }

    private function resolveFqcn(Name $name, ActionAst $actionAst): string
    {
        $parts = $name->getParts();
        $firstPart = $parts[0];

        if (isset($actionAst->useImports[$firstPart])) {
            $parts[0] = $actionAst->useImports[$firstPart];

            return implode('\\', $parts);
        }

        if (! empty($actionAst->namespace)) {
            return $actionAst->namespace.'\\'.implode('\\', $parts);
        }

        return implode('\\', $parts);
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
