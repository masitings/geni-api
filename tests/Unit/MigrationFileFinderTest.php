<?php

declare(strict_types=1);

use Geni\SchemaReader\MigrationFileFinder;

test('finds migration files recursively and sorts them globally by filename', function () {
    $finder = new MigrationFileFinder;
    $fixturesDir = __DIR__.'/../Fixtures/synthetic/migrations';

    $files = $finder->find([$fixturesDir]);

    expect($files)->not->toBeEmpty();
    expect(count($files))->toBe(7);

    // Ensure sorted globally by filename
    $basenames = array_map('basename', $files);
    $sorted = $basenames;
    sort($sorted);

    expect($basenames)->toBe($sorted);
    expect($basenames[0])->toBe('2024_01_01_000001_create_users_table.php');
});

test('generates cache key that changes when files are modified', function () {
    $finder = new MigrationFileFinder;
    $fixturesDir = __DIR__.'/../Fixtures/synthetic/migrations';
    $files = $finder->find([$fixturesDir]);

    $key1 = $finder->generateCacheKey($files);
    expect($key1)->toBeString();
    expect(strlen($key1))->toBe(64); // SHA-256

    $key2 = $finder->generateCacheKey($files);
    expect($key1)->toBe($key2);

    $subset = array_slice($files, 0, 2);
    $key3 = $finder->generateCacheKey($subset);
    expect($key3)->not->toBe($key1);
});
