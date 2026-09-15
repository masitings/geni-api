<?php

declare(strict_types=1);

namespace Geni\SchemaReader;

final class SchemaDumpDetector
{
    /**
     * Detect schema dump SQL files associated with the provided migration paths.
     *
     * @param  list<string>  $paths
     * @return list<string> Found SQL dump files
     */
    public function detect(array $paths): array
    {
        $dumpFiles = [];

        foreach ($paths as $path) {
            $dir = is_file($path) ? dirname($path) : $path;

            // Check sibling schema directory (e.g. database/migrations -> database/schema)
            $candidates = [
                $dir.'/../schema',
                $dir.'/schema',
                dirname($dir).'/schema',
            ];

            foreach ($candidates as $candidate) {
                if (is_dir($candidate)) {
                    $matches = glob($candidate.'/*.sql');
                    if ($matches !== false) {
                        foreach ($matches as $match) {
                            $realMatch = realpath($match) ?: $match;
                            $dumpFiles[$realMatch] = $realMatch;
                        }
                    }
                }
            }
        }

        return array_values($dumpFiles);
    }
}
