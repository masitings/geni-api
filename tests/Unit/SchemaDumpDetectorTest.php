<?php

declare(strict_types=1);

use Geni\SchemaReader\SchemaDumpDetector;

test('detects schema dump sql files', function () {
    $detector = new SchemaDumpDetector;
    $fixturesDir = __DIR__.'/../Fixtures/synthetic/migrations';

    // Before dump file exists
    $dumps = $detector->detect([$fixturesDir]);
    expect($dumps)->toBeEmpty();

    // Create a dummy schema dump file in a mock schema directory
    $schemaDir = dirname($fixturesDir).'/schema';
    @mkdir($schemaDir, 0777, true);
    $dumpFile = $schemaDir.'/mysql-schema.sql';
    file_put_contents($dumpFile, '-- dump');

    try {
        $detected = $detector->detect([$fixturesDir]);
        expect($detected)->not->toBeEmpty();
        expect(basename($detected[0]))->toBe('mysql-schema.sql');
    } finally {
        @unlink($dumpFile);
        @rmdir($schemaDir);
    }
});
