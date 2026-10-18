<?php

declare(strict_types=1);

namespace Geni\Inference;

/**
 * Finds the source file of a class through the autoloader (falling back to
 * PSR-4 style guesses), so it can be parsed statically.
 */
final class ClassFileLocator
{
    public static function locate(string $class): ?string
    {
        $class = ltrim($class, '\\');

        try {
            if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
                $file = (new \ReflectionClass($class))->getFileName();
                if ($file !== false && is_file($file)) {
                    return $file;
                }
            }
        } catch (\Throwable) {
            // A class whose file fails to load cannot be parsed either.
        }

        $relativePath = str_replace('\\', '/', $class).'.php';

        foreach ([__DIR__.'/../../../../src/'.$relativePath, __DIR__.'/../../../../app/'.$relativePath, $relativePath] as $path) {
            if (is_file($path)) {
                return realpath($path) ?: $path;
            }
        }

        return null;
    }
}
