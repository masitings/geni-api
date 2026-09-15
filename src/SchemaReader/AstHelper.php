<?php

declare(strict_types=1);

namespace Geni\SchemaReader;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

final class AstHelper
{
    /**
     * Attempt to resolve a literal value from an AST expression node.
     * Returns true and sets $value if resolved; returns false if not statically resolvable.
     *
     * @param  mixed  $value  Output resolved value
     */
    public static function tryResolveLiteral(Node $node, mixed &$value): bool
    {
        if ($node instanceof Scalar\String_) {
            $value = $node->value;

            return true;
        }

        if ($node instanceof Scalar\Int_) {
            $value = $node->value;

            return true;
        }

        if ($node instanceof Scalar\Float_) {
            $value = $node->value;

            return true;
        }

        if ($node instanceof Expr\ConstFetch) {
            $name = strtolower($node->name->toLowerString());
            if ($name === 'true') {
                $value = true;

                return true;
            }
            if ($name === 'false') {
                $value = false;

                return true;
            }
            if ($name === 'null') {
                $value = null;

                return true;
            }
        }

        if ($node instanceof Expr\UnaryMinus) {
            $subValue = null;
            if (self::tryResolveLiteral($node->expr, $subValue) && is_numeric($subValue)) {
                $value = -$subValue;

                return true;
            }
        }

        if ($node instanceof Expr\ClassConstFetch) {
            if ($node->name instanceof Node\Identifier && $node->name->toLowerString() === 'class') {
                if ($node->class instanceof Node\Name) {
                    $value = $node->class->toString();

                    return true;
                }
            }
        }

        if ($node instanceof Expr\Array_) {
            $array = [];
            foreach ($node->items as $item) {
                if ($item === null) {
                    continue;
                }

                $itemVal = null;
                if (! self::tryResolveLiteral($item->value, $itemVal)) {
                    return false;
                }

                if ($item->key !== null) {
                    $keyVal = null;
                    if (! self::tryResolveLiteral($item->key, $keyVal) || (! is_string($keyVal) && ! is_int($keyVal))) {
                        return false;
                    }
                    $array[$keyVal] = $itemVal;
                } else {
                    $array[] = $itemVal;
                }
            }
            $value = $array;

            return true;
        }

        return false;
    }

    /**
     * Get the class basename from a fully qualified or unqualified class name.
     */
    public static function classBasename(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }

    /**
     * Convert a string to snake_case.
     */
    public static function snakeCase(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);

        return strtolower($value ?? '');
    }
}
