<?php

declare(strict_types=1);

namespace Geni\SchemaReader;

final class MacroExpander
{
    private const MACROS = [
        'id',
        'timestamps',
        'timestampsTz',
        'softDeletes',
        'softDeletesTz',
        'morphs',
        'nullableMorphs',
        'uuidMorphs',
        'nullableUuidMorphs',
        'ulidMorphs',
        'nullableUlidMorphs',
        'rememberToken',
        'foreignId',
        'foreignUuid',
        'foreignUlid',
        'foreignIdFor',
    ];

    public static function isMacro(string $method): bool
    {
        return in_array($method, self::MACROS, true);
    }

    /**
     * Expand a macro method into one or more ColumnSchema objects.
     * Returns null if arguments cannot be resolved statically.
     *
     * @param  list<mixed>  $resolvedArgs
     * @return list<ColumnSchema>|null
     */
    public static function expand(string $method, array $resolvedArgs): ?array
    {
        switch ($method) {
            case 'id':
                $col = isset($resolvedArgs[0]) && is_string($resolvedArgs[0]) ? $resolvedArgs[0] : 'id';

                return [
                    new ColumnSchema(
                        name: $col,
                        type: ColumnType::Integer,
                        blueprintMethod: 'id',
                        unsigned: true,
                        autoIncrement: true,
                    ),
                ];

            case 'timestamps':
                $precision = isset($resolvedArgs[0]) && is_int($resolvedArgs[0]) ? $resolvedArgs[0] : null;

                return [
                    new ColumnSchema(
                        name: 'created_at',
                        type: ColumnType::DateTime,
                        blueprintMethod: 'timestamp',
                        nullable: true,
                        precision: $precision,
                    ),
                    new ColumnSchema(
                        name: 'updated_at',
                        type: ColumnType::DateTime,
                        blueprintMethod: 'timestamp',
                        nullable: true,
                        precision: $precision,
                    ),
                ];

            case 'timestampsTz':
                $precision = isset($resolvedArgs[0]) && is_int($resolvedArgs[0]) ? $resolvedArgs[0] : null;

                return [
                    new ColumnSchema(
                        name: 'created_at',
                        type: ColumnType::DateTime,
                        blueprintMethod: 'timestampTz',
                        nullable: true,
                        precision: $precision,
                    ),
                    new ColumnSchema(
                        name: 'updated_at',
                        type: ColumnType::DateTime,
                        blueprintMethod: 'timestampTz',
                        nullable: true,
                        precision: $precision,
                    ),
                ];

            case 'softDeletes':
                $col = isset($resolvedArgs[0]) && is_string($resolvedArgs[0]) ? $resolvedArgs[0] : 'deleted_at';
                $precision = isset($resolvedArgs[1]) && is_int($resolvedArgs[1]) ? $resolvedArgs[1] : null;

                return [
                    new ColumnSchema(
                        name: $col,
                        type: ColumnType::DateTime,
                        blueprintMethod: 'softDeletes',
                        nullable: true,
                        precision: $precision,
                    ),
                ];

            case 'softDeletesTz':
                $col = isset($resolvedArgs[0]) && is_string($resolvedArgs[0]) ? $resolvedArgs[0] : 'deleted_at';
                $precision = isset($resolvedArgs[1]) && is_int($resolvedArgs[1]) ? $resolvedArgs[1] : null;

                return [
                    new ColumnSchema(
                        name: $col,
                        type: ColumnType::DateTime,
                        blueprintMethod: 'softDeletesTz',
                        nullable: true,
                        precision: $precision,
                    ),
                ];

            case 'morphs':
                if (! isset($resolvedArgs[0]) || ! is_string($resolvedArgs[0])) {
                    return null;
                }
                $name = $resolvedArgs[0];

                return [
                    new ColumnSchema(
                        name: $name.'_type',
                        type: ColumnType::String,
                        blueprintMethod: 'string',
                        length: 255,
                    ),
                    new ColumnSchema(
                        name: $name.'_id',
                        type: ColumnType::Integer,
                        blueprintMethod: 'unsignedBigInteger',
                        unsigned: true,
                    ),
                ];

            case 'nullableMorphs':
                if (! isset($resolvedArgs[0]) || ! is_string($resolvedArgs[0])) {
                    return null;
                }
                $name = $resolvedArgs[0];

                return [
                    new ColumnSchema(
                        name: $name.'_type',
                        type: ColumnType::String,
                        blueprintMethod: 'string',
                        nullable: true,
                        length: 255,
                    ),
                    new ColumnSchema(
                        name: $name.'_id',
                        type: ColumnType::Integer,
                        blueprintMethod: 'unsignedBigInteger',
                        nullable: true,
                        unsigned: true,
                    ),
                ];

            case 'uuidMorphs':
                if (! isset($resolvedArgs[0]) || ! is_string($resolvedArgs[0])) {
                    return null;
                }
                $name = $resolvedArgs[0];

                return [
                    new ColumnSchema(
                        name: $name.'_type',
                        type: ColumnType::String,
                        blueprintMethod: 'string',
                        length: 255,
                    ),
                    new ColumnSchema(
                        name: $name.'_id',
                        type: ColumnType::Uuid,
                        blueprintMethod: 'uuid',
                    ),
                ];

            case 'nullableUuidMorphs':
                if (! isset($resolvedArgs[0]) || ! is_string($resolvedArgs[0])) {
                    return null;
                }
                $name = $resolvedArgs[0];

                return [
                    new ColumnSchema(
                        name: $name.'_type',
                        type: ColumnType::String,
                        blueprintMethod: 'string',
                        nullable: true,
                        length: 255,
                    ),
                    new ColumnSchema(
                        name: $name.'_id',
                        type: ColumnType::Uuid,
                        blueprintMethod: 'uuid',
                        nullable: true,
                    ),
                ];

            case 'ulidMorphs':
                if (! isset($resolvedArgs[0]) || ! is_string($resolvedArgs[0])) {
                    return null;
                }
                $name = $resolvedArgs[0];

                return [
                    new ColumnSchema(
                        name: $name.'_type',
                        type: ColumnType::String,
                        blueprintMethod: 'string',
                        length: 255,
                    ),
                    new ColumnSchema(
                        name: $name.'_id',
                        type: ColumnType::Ulid,
                        blueprintMethod: 'ulid',
                    ),
                ];

            case 'nullableUlidMorphs':
                if (! isset($resolvedArgs[0]) || ! is_string($resolvedArgs[0])) {
                    return null;
                }
                $name = $resolvedArgs[0];

                return [
                    new ColumnSchema(
                        name: $name.'_type',
                        type: ColumnType::String,
                        blueprintMethod: 'string',
                        nullable: true,
                        length: 255,
                    ),
                    new ColumnSchema(
                        name: $name.'_id',
                        type: ColumnType::Ulid,
                        blueprintMethod: 'ulid',
                        nullable: true,
                    ),
                ];

            case 'rememberToken':
                return [
                    new ColumnSchema(
                        name: 'remember_token',
                        type: ColumnType::String,
                        blueprintMethod: 'rememberToken',
                        nullable: true,
                        length: 100,
                    ),
                ];

            case 'foreignId':
                if (! isset($resolvedArgs[0]) || ! is_string($resolvedArgs[0])) {
                    return null;
                }

                return [
                    new ColumnSchema(
                        name: $resolvedArgs[0],
                        type: ColumnType::Integer,
                        blueprintMethod: 'foreignId',
                        unsigned: true,
                    ),
                ];

            case 'foreignUuid':
                if (! isset($resolvedArgs[0]) || ! is_string($resolvedArgs[0])) {
                    return null;
                }

                return [
                    new ColumnSchema(
                        name: $resolvedArgs[0],
                        type: ColumnType::Uuid,
                        blueprintMethod: 'foreignUuid',
                    ),
                ];

            case 'foreignUlid':
                if (! isset($resolvedArgs[0]) || ! is_string($resolvedArgs[0])) {
                    return null;
                }

                return [
                    new ColumnSchema(
                        name: $resolvedArgs[0],
                        type: ColumnType::Ulid,
                        blueprintMethod: 'foreignUlid',
                    ),
                ];

            case 'foreignIdFor':
                if (! isset($resolvedArgs[0]) || ! is_string($resolvedArgs[0])) {
                    return null;
                }
                $modelClass = $resolvedArgs[0];
                if (isset($resolvedArgs[1]) && is_string($resolvedArgs[1])) {
                    $colName = $resolvedArgs[1];
                } else {
                    $base = AstHelper::classBasename($modelClass);
                    $colName = AstHelper::snakeCase($base).'_id';
                }

                return [
                    new ColumnSchema(
                        name: $colName,
                        type: ColumnType::Integer,
                        blueprintMethod: 'foreignIdFor',
                        unsigned: true,
                    ),
                ];

            default:
                return null;
        }
    }
}
