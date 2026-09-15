<?php

declare(strict_types=1);

use Geni\Inference\DocumentAssembler;
use Geni\Laravel\RouteDiscoverer;
use Opis\JsonSchema\Validator;
use Tests\Fixtures\AnnotatedApp\Controllers\ArticleController;

test('validates phase 5 exit criterion: annotated project compatibility and official meta-schema validation', function () {
    // Register annotated routes under /api
    app('router')->prefix('api')->group(function () {
        app('router')->get('articles', [ArticleController::class, 'index'])->name('articles.index');
        app('router')->post('articles', [ArticleController::class, 'store'])->name('articles.store');
        app('router')->get('articles/{article}', [ArticleController::class, 'show'])->name('articles.show');
    });

    $routes = RouteDiscoverer::discover();
    $plainRoutes = [];
    foreach ($routes as $r) {
        if (str_contains($r->uri, 'articles')) {
            $plainRoutes[] = [
                'uri' => $r->uri,
                'methods' => $r->methods,
                'action' => $r->action,
                'middleware' => $r->middleware,
                'name' => $r->name,
            ];
        }
    }

    expect($plainRoutes)->toHaveCount(3);

    $assembler = new DocumentAssembler;
    $document = $assembler->assemble($plainRoutes, ['title' => 'Annotated API', 'version' => '1.0.0']);
    $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    // 1. Verify against official OpenAPI 3.1.0 JSON Schema meta-schema
    $validator = new Validator;
    $metaSchemaJson = file_get_contents(__DIR__.'/../../Fixtures/schemas/openapi-3.1.json');
    $metaSchema = json_decode($metaSchemaJson);

    $validationResult = $validator->validate(json_decode($json), $metaSchema);
    expect($validationResult->isValid())->toBeTrue(
        'Annotated document must validate against official OpenAPI 3.1.0 JSON Schema meta-schema'
    );

    $data = json_decode($json, true);
    $paths = $data['paths'];

    // 2. Verify GET /api/articles metadata & parameters
    $getIndex = $paths['/api/articles']['get'];
    expect($getIndex['summary'])->toBe('List Articles');
    expect($getIndex['extensions']['operationId'] ?? $getIndex['operationId'] ?? null)->toBe('listArticles');
    expect($getIndex['tags'])->toContain('Articles');

    // Verify QueryParameter with infer: false
    $pageParam = array_values(array_filter($getIndex['parameters'] ?? [], fn ($p) => $p['name'] === 'page'))[0] ?? null;
    expect($pageParam)->not->toBeNull();
    expect($pageParam['in'])->toBe('query');
    expect($pageParam['schema']['type'])->toBe('integer');
    expect($pageParam['schema']['default'])->toBe(1);
    expect($pageParam['description'])->toBe('Page number');

    // 3. Verify POST /api/articles response override
    $postStore = $paths['/api/articles']['post'];
    expect($postStore['responses'])->toHaveKey('201');
    expect($postStore['responses']['201']['description'])->toBe('Article created');

    // FormRequest rules inferred
    expect($postStore['requestBody']['content']['application/json']['schema']['properties'])->toHaveKeys(['title', 'content', 'status']);

    // 4. Verify GET /api/articles/{article} path parameter override
    $getShow = $paths['/api/posts/{post}'] ?? $paths['/api/articles/{article}']['get'];
    $articleParam = array_values(array_filter($getShow['parameters'] ?? [], fn ($p) => $p['name'] === 'article'))[0] ?? null;
    expect($articleParam)->not->toBeNull();
    expect($articleParam['in'])->toBe('path');
    expect($articleParam['description'])->toBe('Article identifier');
    expect($articleParam['schema']['type'])->toBe('integer');
});
