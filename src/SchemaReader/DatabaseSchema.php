<?php

declare(strict_types=1);

namespace Geni\SchemaReader;

final class DatabaseSchema
{
    /** @var array<string, TableSchema> keyed by "connection.table" or "table" */
    public array $tables;

    /** @var list<UnresolvedConstruct> */
    public array $unresolved;

    public bool $hasSchemaDump;

    public ?string $schemaDumpWarning;

    /**
     * @param  array<string, TableSchema>  $tables
     * @param  list<UnresolvedConstruct>  $unresolved
     */
    public function __construct(
        array $tables = [],
        array $unresolved = [],
        bool $hasSchemaDump = false,
        ?string $schemaDumpWarning = null,
    ) {
        $this->tables = $tables;
        $this->unresolved = $unresolved;
        $this->hasSchemaDump = $hasSchemaDump;
        $this->schemaDumpWarning = $schemaDumpWarning;
    }
}
