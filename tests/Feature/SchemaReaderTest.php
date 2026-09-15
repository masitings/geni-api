<?php

declare(strict_types=1);

use Geni\SchemaReader\ColumnType;
use Geni\SchemaReader\SchemaReader;

test('reconstructs schema from synthetic migrations matching all acceptance criteria', function () {
    $reader = new SchemaReader;
    $fixturesDir = __DIR__.'/../Fixtures/synthetic/migrations';

    $schema = $reader->read([$fixturesDir]);

    // AC-007: Connection prefix check
    expect(isset($schema->tables['tenant.orders']))->toBeTrue('tenant.orders table should exist');
    $ordersTable = $schema->tables['tenant.orders'];
    expect($ordersTable->connection)->toBe('tenant');
    expect($ordersTable->name)->toBe('orders');
    // AC-008: foreignIdFor resolution
    expect(isset($ordersTable->columns['user_id']))->toBeTrue();
    expect($ordersTable->columns['user_id']->type)->toBe(ColumnType::Integer);
    expect($ordersTable->columns['user_id']->unsigned)->toBeTrue();
    expect($ordersTable->columns['total']->type)->toBe(ColumnType::Decimal);
    expect($ordersTable->columns['total']->precision)->toBe(10);
    expect($ordersTable->columns['total']->scale)->toBe(2);

    // AC-006: Rename and drop tables
    // tags was renamed to categories
    expect(isset($schema->tables['tags']))->toBeFalse('tags should have been renamed');
    expect(isset($schema->tables['categories']))->toBeTrue('categories table should exist');
    // taggables was dropped
    expect(isset($schema->tables['taggables']))->toBeFalse('taggables should have been dropped');

    // AC-005: Users table mutations
    expect(isset($schema->tables['users']))->toBeTrue();
    $users = $schema->tables['users'];

    // 'name' was renamed to 'full_name'
    expect(isset($users->columns['name']))->toBeFalse('name should be renamed');
    expect(isset($users->columns['full_name']))->toBeTrue('full_name should exist');
    // 'password' was dropped
    expect(isset($users->columns['password']))->toBeFalse('password should be dropped');
    // 'phone' was added as nullable
    expect(isset($users->columns['phone']))->toBeTrue();
    expect($users->columns['phone']->nullable)->toBeTrue();
    // 'email' was changed with length 150
    expect(isset($users->columns['email']))->toBeTrue();
    expect($users->columns['email']->length)->toBe(150);

    // AC-003: Posts table used $blueprint parameter name
    expect(isset($schema->tables['posts']))->toBeTrue();
    $posts = $schema->tables['posts'];
    expect(isset($posts->columns['title']))->toBeTrue();
    expect($posts->columns['title']->type)->toBe(ColumnType::String);
    expect(isset($posts->columns['body']))->toBeTrue();
    expect($posts->columns['body']->type)->toBe(ColumnType::Text);
    expect($posts->columns['is_published']->type)->toBe(ColumnType::Boolean);
    expect($posts->columns['is_published']->default)->toBe(false);
    expect(isset($posts->columns['deleted_at']))->toBeTrue();

    // AC-009: Unresolved constructs recorded
    expect($schema->unresolved)->not->toBeEmpty();
    $reasons = array_map(fn ($u) => $u->reason, $schema->unresolved);
    $hasControlFlow = false;
    $hasVariableCol = false;
    foreach ($reasons as $reason) {
        if (str_contains($reason, 'Control flow construct')) {
            $hasControlFlow = true;
        }
        if (str_contains($reason, 'Cannot statically resolve column name') || str_contains($reason, 'variable')) {
            $hasVariableCol = true;
        }
    }
    expect($hasControlFlow)->toBeTrue('Should record control flow construct as UnresolvedConstruct');
    expect($hasVariableCol)->toBeTrue('Should record dynamic variable column as UnresolvedConstruct');
});

test('determinism: running reader twice on same input produces identical output', function () {
    $reader = new SchemaReader;
    $fixturesDir = __DIR__.'/../Fixtures/synthetic/migrations';

    $schema1 = $reader->read([$fixturesDir]);
    $schema2 = $reader->read([$fixturesDir]);

    expect($schema1)->toEqual($schema2);
});

test('syntax error in migration records unresolved construct and does not throw', function () {
    $reader = new SchemaReader;

    // Create a temporary file with syntax error
    $tempFile = sys_get_temp_dir().'/2024_01_01_999999_invalid_syntax.php';
    file_put_contents($tempFile, '<?php this is not valid php syntax !!!');

    try {
        $schema = $reader->read([$tempFile]);
        expect($schema->unresolved)->not->toBeEmpty();
        $hasParseError = false;
        foreach ($schema->unresolved as $u) {
            if (str_contains($u->reason, 'PHP parse error')) {
                $hasParseError = true;
            }
        }
        expect($hasParseError)->toBeTrue('Should record PHP parse error without throwing');
    } finally {
        @unlink($tempFile);
    }
});
