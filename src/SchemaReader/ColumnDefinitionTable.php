<?php

declare(strict_types=1);

namespace Geni\SchemaReader;

final class ColumnDefinitionTable
{
    /**
     * Map of Blueprint column method names to their metadata.
     *
     * @var array<string, array{
     *     type: ColumnType,
     *     unsigned: bool,
     *     length: ?int,
     *     precision: ?int,
     *     scale: ?int
     * }>
     */
    private const DEFINITIONS = [
        'string' => [
            'type' => ColumnType::String,
            'unsigned' => false,
            'length' => 255,
            'precision' => null,
            'scale' => null,
        ],
        'char' => [
            'type' => ColumnType::String,
            'unsigned' => false,
            'length' => 255,
            'precision' => null,
            'scale' => null,
        ],
        'text' => [
            'type' => ColumnType::Text,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'mediumText' => [
            'type' => ColumnType::Text,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'longText' => [
            'type' => ColumnType::Text,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'tinyText' => [
            'type' => ColumnType::Text,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'integer' => [
            'type' => ColumnType::Integer,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'tinyInteger' => [
            'type' => ColumnType::Integer,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'smallInteger' => [
            'type' => ColumnType::Integer,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'mediumInteger' => [
            'type' => ColumnType::Integer,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'bigInteger' => [
            'type' => ColumnType::Integer,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'increments' => [
            'type' => ColumnType::Integer,
            'unsigned' => true,
            'autoIncrement' => true,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'tinyIncrements' => [
            'type' => ColumnType::Integer,
            'unsigned' => true,
            'autoIncrement' => true,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'smallIncrements' => [
            'type' => ColumnType::Integer,
            'unsigned' => true,
            'autoIncrement' => true,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'mediumIncrements' => [
            'type' => ColumnType::Integer,
            'unsigned' => true,
            'autoIncrement' => true,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'bigIncrements' => [
            'type' => ColumnType::Integer,
            'unsigned' => true,
            'autoIncrement' => true,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'unsignedInteger' => [
            'type' => ColumnType::Integer,
            'unsigned' => true,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'unsignedTinyInteger' => [
            'type' => ColumnType::Integer,
            'unsigned' => true,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'unsignedSmallInteger' => [
            'type' => ColumnType::Integer,
            'unsigned' => true,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'unsignedMediumInteger' => [
            'type' => ColumnType::Integer,
            'unsigned' => true,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'unsignedBigInteger' => [
            'type' => ColumnType::Integer,
            'unsigned' => true,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'float' => [
            'type' => ColumnType::Float,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'double' => [
            'type' => ColumnType::Float,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'decimal' => [
            'type' => ColumnType::Decimal,
            'unsigned' => false,
            'length' => null,
            'precision' => 8,
            'scale' => 2,
        ],
        'unsignedDecimal' => [
            'type' => ColumnType::Decimal,
            'unsigned' => true,
            'length' => null,
            'precision' => 8,
            'scale' => 2,
        ],
        'boolean' => [
            'type' => ColumnType::Boolean,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'date' => [
            'type' => ColumnType::Date,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'dateTime' => [
            'type' => ColumnType::DateTime,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'dateTimeTz' => [
            'type' => ColumnType::DateTime,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'time' => [
            'type' => ColumnType::Time,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'timeTz' => [
            'type' => ColumnType::Time,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'timestamp' => [
            'type' => ColumnType::DateTime,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'timestampTz' => [
            'type' => ColumnType::DateTime,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'year' => [
            'type' => ColumnType::Integer,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'binary' => [
            'type' => ColumnType::Binary,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'uuid' => [
            'type' => ColumnType::Uuid,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'ulid' => [
            'type' => ColumnType::Ulid,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'json' => [
            'type' => ColumnType::Json,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'jsonb' => [
            'type' => ColumnType::Json,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'enum' => [
            'type' => ColumnType::String,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'set' => [
            'type' => ColumnType::String,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'ipAddress' => [
            'type' => ColumnType::String,
            'unsigned' => false,
            'length' => 45,
            'precision' => null,
            'scale' => null,
        ],
        'macAddress' => [
            'type' => ColumnType::String,
            'unsigned' => false,
            'length' => 17,
            'precision' => null,
            'scale' => null,
        ],
        'geometry' => [
            'type' => ColumnType::Unknown,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'point' => [
            'type' => ColumnType::Unknown,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'lineString' => [
            'type' => ColumnType::Unknown,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'polygon' => [
            'type' => ColumnType::Unknown,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'multiPoint' => [
            'type' => ColumnType::Unknown,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'multiLineString' => [
            'type' => ColumnType::Unknown,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'multiPolygon' => [
            'type' => ColumnType::Unknown,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
        'geometryCollection' => [
            'type' => ColumnType::Unknown,
            'unsigned' => false,
            'length' => null,
            'precision' => null,
            'scale' => null,
        ],
    ];

    public static function isStandardColumnMethod(string $method): bool
    {
        return isset(self::DEFINITIONS[$method]);
    }

    /**
     * @return array{
     *     type: ColumnType,
     *     unsigned: bool,
     *     length: ?int,
     *     precision: ?int,
     *     scale: ?int
     * }|null
     */
    public static function getDefinition(string $method): ?array
    {
        return self::DEFINITIONS[$method] ?? null;
    }
}
