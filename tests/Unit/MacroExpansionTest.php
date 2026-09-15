<?php

declare(strict_types=1);

use Geni\SchemaReader\ColumnType;
use Geni\SchemaReader\MacroExpander;

test('expands id macro', function () {
    $cols = MacroExpander::expand('id', []);
    expect($cols)->toHaveCount(1);
    expect($cols[0]->name)->toBe('id');
    expect($cols[0]->type)->toBe(ColumnType::Integer);
    expect($cols[0]->unsigned)->toBeTrue();
    expect($cols[0]->autoIncrement)->toBeTrue();

    $customCols = MacroExpander::expand('id', ['user_id']);
    expect($customCols[0]->name)->toBe('user_id');
});

test('expands timestamps macro', function () {
    $cols = MacroExpander::expand('timestamps', []);
    expect($cols)->toHaveCount(2);
    expect($cols[0]->name)->toBe('created_at');
    expect($cols[0]->type)->toBe(ColumnType::DateTime);
    expect($cols[0]->nullable)->toBeTrue();
    expect($cols[1]->name)->toBe('updated_at');
    expect($cols[1]->type)->toBe(ColumnType::DateTime);
    expect($cols[1]->nullable)->toBeTrue();
});

test('expands morphs and nullableMorphs macro', function () {
    $cols = MacroExpander::expand('morphs', ['taggable']);
    expect($cols)->toHaveCount(2);
    expect($cols[0]->name)->toBe('taggable_type');
    expect($cols[0]->type)->toBe(ColumnType::String);
    expect($cols[1]->name)->toBe('taggable_id');
    expect($cols[1]->type)->toBe(ColumnType::Integer);
    expect($cols[1]->unsigned)->toBeTrue();

    $nullableCols = MacroExpander::expand('nullableMorphs', ['imageable']);
    expect($nullableCols)->toHaveCount(2);
    expect($nullableCols[0]->nullable)->toBeTrue();
    expect($nullableCols[1]->nullable)->toBeTrue();
});

test('expands uuidMorphs and ulidMorphs macro', function () {
    $uuidCols = MacroExpander::expand('uuidMorphs', ['record']);
    expect($uuidCols)->toHaveCount(2);
    expect($uuidCols[1]->type)->toBe(ColumnType::Uuid);

    $ulidCols = MacroExpander::expand('ulidMorphs', ['record']);
    expect($ulidCols)->toHaveCount(2);
    expect($ulidCols[1]->type)->toBe(ColumnType::Ulid);
});

test('expands foreignIdFor macro', function () {
    $cols = MacroExpander::expand('foreignIdFor', ['App\\Models\\User']);
    expect($cols)->toHaveCount(1);
    expect($cols[0]->name)->toBe('user_id');
    expect($cols[0]->type)->toBe(ColumnType::Integer);
    expect($cols[0]->unsigned)->toBeTrue();

    $customCols = MacroExpander::expand('foreignIdFor', ['App\\Models\\TeamMember', 'owner_id']);
    expect($customCols)->toHaveCount(1);
    expect($customCols[0]->name)->toBe('owner_id');
});
