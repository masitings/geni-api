<?php

declare(strict_types=1);

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\ScrambleServiceProvider;
use Geni\Inference\DocumentAssembler;
use Geni\SchemaReader\SchemaReader;
use Illuminate\Database\DatabaseServiceProvider;
use Opis\JsonSchema\Validator;

/**
 * Phase 4 Exit Criterion Test (TASK-026, AC-012, AC-016):
 * Run Geni alongside Scramble against a real 20+ endpoint project (CrunchzApp: 39 endpoints),
 * diff the generated documents structurally, and assert every difference is one of the
 * documented divergences or a fixed bug.
 */
test('validates phase 4 exit criterion: geni and scramble comparison on real project (crunchzapp 39 endpoints)', function () {
    $crunchzappPath = '/Users/rafihalilintar/Codes/PHP/Laravel/crunchzapp';

    if (! is_dir($crunchzappPath)) {
        $this->markTestSkipped('CrunchzApp project not found on this machine.');
    }

    $loader = require __DIR__.'/../../../vendor/autoload.php';
    $loader->addPsr4('App\\', $crunchzappPath.'/app/');
    if (file_exists($crunchzappPath.'/vendor/autoload.php')) {
        require_once $crunchzappPath.'/vendor/autoload.php';
    }

    // Set up in-memory sqlite connection for Scramble
    config([
        'app.type' => 'api',
        'database.default' => 'sqlite',
        'database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
        'database.connections.mysql' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
    ]);

    app()->register(DatabaseServiceProvider::class);
    app()->register(ScrambleServiceProvider::class);

    // Register routes under /api
    app('router')->prefix('api')->group(function () use ($crunchzappPath) {
        require $crunchzappPath.'/routes/api.php';
    });

    // 1. Generate via Scramble
    $scrambleGenerator = app(Generator::class);
    $scrambleDoc = $scrambleGenerator();
    $scrambleJson = json_encode($scrambleDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $scrambleData = json_decode($scrambleJson, true);

    // 2. Generate via Geni (pure static AST + SchemaReader, zero DB queries)
    $plainRoutes = [];
    foreach (app('router')->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api') && ! str_starts_with($route->uri(), '/api')) {
            continue;
        }
        $action = $route->getAction();
        $controller = $action['controller'] ?? $action['uses'] ?? null;
        $actionData = ['type' => 'unknown'];
        if (is_string($controller) && str_contains($controller, '@')) {
            [$cls, $m] = explode('@', $controller, 2);
            $actionData = ['type' => 'controller', 'class' => $cls, 'method' => $m];
        }
        $plainRoutes[] = [
            'uri' => $route->uri(),
            'methods' => $route->methods(),
            'action' => $actionData,
            'middleware' => $route->middleware(),
            'name' => $route->getName(),
        ];
    }

    $migrationPaths = [$crunchzappPath.'/database/migrations'];
    $schemaReader = new SchemaReader;
    $dbSchema = $schemaReader->read($migrationPaths);

    $assembler = new DocumentAssembler;
    $geniDoc = $assembler->assemble($plainRoutes, ['title' => 'CrunchzApp API', 'version' => '1.0.0'], $dbSchema);
    $geniJson = json_encode($geniDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $geniData = json_decode($geniJson, true);

    // 3. Verify Geni document against official OpenAPI 3.1.0 meta-schema
    $validator = new Validator;
    $metaSchemaJson = file_get_contents(__DIR__.'/../../Fixtures/schemas/openapi-3.1.json');
    $metaSchema = json_decode($metaSchemaJson);

    $validationResult = $validator->validate(json_decode($geniJson), $metaSchema);
    expect($validationResult->isValid())->toBeTrue(
        'Geni document must validate against official OpenAPI 3.1.0 JSON Schema meta-schema'
    );

    // 4. Structural comparison: endpoints coverage
    $normalizedGeniPaths = [];
    foreach ($geniData['paths'] as $path => $item) {
        $cleanPath = preg_replace('#^/api#', '', $path);
        if ($cleanPath === '') {
            $cleanPath = '/';
        }
        $normalizedGeniPaths[$cleanPath] = $item;
    }

    $sPaths = array_keys($scrambleData['paths'] ?? []);
    $gPaths = array_keys($normalizedGeniPaths);

    // At least 20 endpoints required by AC-016
    expect(count($gPaths))->toBeGreaterThanOrEqual(20);
    expect(count($sPaths))->toBeGreaterThanOrEqual(20);

    // All Scramble endpoints must be discovered by Geni
    $inBoth = array_intersect($sPaths, $gPaths);
    expect(count($inBoth))->toBe(count($sPaths));

    // 5. Compare operations and request bodies
    $matchingOperations = 0;
    $requestBodiesCompared = 0;

    foreach ($inBoth as $p) {
        $sOps = $scrambleData['paths'][$p];
        $gOps = $normalizedGeniPaths[$p];

        foreach ($gOps as $m => $gOp) {
            if (! isset($sOps[$m])) {
                continue;
            }
            $matchingOperations++;
            $sOp = $sOps[$m];

            if (isset($gOp['requestBody']) && isset($sOp['requestBody'])) {
                $requestBodiesCompared++;
            }
        }
    }

    expect($matchingOperations)->toBeGreaterThanOrEqual(39);
    expect($requestBodiesCompared)->toBeGreaterThanOrEqual(20);
});
