<?php

declare(strict_types=1);

namespace Geni\SchemaReader;

final class ColumnSchema
{
    public string $name;

    public ColumnType $type;

    public string $blueprintMethod;

    public bool $nullable;

    public mixed $default;

    public ?int $length;

    public ?int $precision;

    public ?int $scale;

    public bool $unsigned;

    /** @var list<string>|null */
    public ?array $allowed;

    public bool $autoIncrement;

    public ?string $comment;

    /**
     * @param  list<string>|null  $allowed
     */
    public function __construct(
        string $name,
        ColumnType $type,
        string $blueprintMethod,
        bool $nullable = false,
        mixed $default = null,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
        bool $unsigned = false,
        ?array $allowed = null,
        bool $autoIncrement = false,
        ?string $comment = null,
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->blueprintMethod = $blueprintMethod;
        $this->nullable = $nullable;
        $this->default = $default;
        $this->length = $length;
        $this->precision = $precision;
        $this->scale = $scale;
        $this->unsigned = $unsigned;
        $this->allowed = $allowed;
        $this->autoIncrement = $autoIncrement;
        $this->comment = $comment;
    }
}
