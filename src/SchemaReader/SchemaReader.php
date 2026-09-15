<?php

declare(strict_types=1);

namespace Geni\SchemaReader;

final class SchemaReader
{
    private MigrationFileFinder $finder;

    private SchemaDumpDetector $dumpDetector;

    private MigrationParser $parser;

    public function __construct(
        ?MigrationFileFinder $finder = null,
        ?SchemaDumpDetector $dumpDetector = null,
        ?MigrationParser $parser = null,
    ) {
        $this->finder = $finder ?? new MigrationFileFinder;
        $this->dumpDetector = $dumpDetector ?? new SchemaDumpDetector;
        $this->parser = $parser ?? new MigrationParser;
    }

    /**
     * Read and reconstruct the database schema from migration paths or files.
     *
     * @param  list<string>  $paths  Directory paths or explicit file paths
     */
    public function read(array $paths): DatabaseSchema
    {
        $schema = new DatabaseSchema;

        // Check for schema dump SQL files
        $dumps = $this->dumpDetector->detect($paths);
        if (count($dumps) > 0) {
            $schema->hasSchemaDump = true;
            $schema->schemaDumpWarning = sprintf(
                'Detected %d schema dump file(s) (%s). Migrations before the dump may have been pruned, which can result in an incomplete schema.',
                count($dumps),
                implode(', ', array_map('basename', $dumps))
            );
        }

        $files = $this->finder->find($paths);

        foreach ($files as $file) {
            $this->parser->parseFile($file, $schema);
        }

        return $schema;
    }

    /**
     * Generate a deterministic cache key for given migration paths.
     *
     * @param  list<string>  $paths
     */
    public function generateCacheKey(array $paths): string
    {
        $files = $this->finder->find($paths);

        return $this->finder->generateCacheKey($files);
    }
}
