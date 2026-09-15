<?php

declare(strict_types=1);

namespace Geni\SchemaReader;

final class TableSchema
{
    public string $name;

    public ?string $connection;

    /** @var array<string, ColumnSchema> */
    public array $columns;

    /**
     * @param  array<string, ColumnSchema>  $columns
     */
    public function __construct(
        string $name,
        ?string $connection = null,
        array $columns = [],
    ) {
        $this->name = $name;
        $this->connection = $connection;
        $this->columns = $columns;
    }
}
