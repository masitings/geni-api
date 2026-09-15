<?php

declare(strict_types=1);

namespace Geni\SchemaReader;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class MigrationFileFinder
{
    /**
     * Find and sort all migration files across given directory or file paths.
     *
     * @param  list<string>  $paths
     * @return list<string> Sorted absolute file paths
     */
    public function find(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (! file_exists($path)) {
                continue;
            }

            if (is_file($path)) {
                if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
                    $realPath = realpath($path) ?: $path;
                    $files[$realPath] = $realPath;
                }

                continue;
            }

            if (is_dir($path)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                /** @var SplFileInfo $file */
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $realPath = $file->getRealPath() ?: $file->getPathname();
                        $files[$realPath] = $realPath;
                    }
                }
            }
        }

        $sortedFiles = array_values($files);

        // Sort globally by filename (not directory order)
        usort($sortedFiles, function (string $a, string $b): int {
            return strcmp(basename($a), basename($b));
        });

        return $sortedFiles;
    }

    /**
     * Generate a deterministic cache key derived from the ordered list of migration
     * file paths and each file's modification time.
     *
     * @param  list<string>  $files
     */
    public function generateCacheKey(array $files): string
    {
        $payload = [];
        foreach ($files as $file) {
            $mtime = file_exists($file) ? filemtime($file) : 0;
            $payload[] = $file.':'.$mtime;
        }

        return hash('sha256', implode('|', $payload));
    }
}
