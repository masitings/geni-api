<?php

declare(strict_types=1);

use Geni\SchemaReader\ColumnDefinitionTable;
use Geni\SchemaReader\ColumnType;

test('standard column definition table maps all FR-012 methods', function () {
    $methods = [
        'string', 'char', 'text', 'mediumText', 'longText', 'tinyText',
        'integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger',
        'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger', 'unsignedMediumInteger', 'unsignedBigInteger',
        'float', 'double', 'decimal', 'unsignedDecimal',
        'boolean', 'date', 'dateTime', 'dateTimeTz', 'time', 'timeTz', 'timestamp', 'timestampTz', 'year',
        'binary', 'uuid', 'ulid', 'json', 'jsonb', 'enum', 'set', 'ipAddress', 'macAddress',
        'geometry', 'point', 'lineString', 'polygon', 'multiPoint', 'multiLineString', 'multiPolygon', 'geometryCollection',
    ];

    foreach ($methods as $method) {
        expect(ColumnDefinitionTable::isStandardColumnMethod($method))->toBeTrue("Method {$method} should be standard column method");
        $def = ColumnDefinitionTable::getDefinition($method);
        expect($def)->not->toBeNull();
        expect($def['type'])->toBeInstanceOf(ColumnType::class);
    }
});
