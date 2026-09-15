<?php

declare(strict_types=1);

use Geni\Inference\Document\OpenApiDocument;
use Geni\Inference\DocumentAssembler;
use Geni\Inference\PathParameterInferer;
use Geni\Laravel\Controllers\DocumentationController;
use Geni\Laravel\Renderer;
use Geni\SchemaReader\DatabaseSchema;
use Geni\SchemaReader\TableSchema;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

test('geni:analyze command lists diagnostics as human table and json', function () {
    // 1. Table output
    $this->artisan('geni:analyze')
        ->assertSuccessful();

    // 2. JSON output
    $this->artisan('geni:analyze --json')
        ->assertSuccessful();
});

test('geni:check command detects matching spec and reports non-zero on drift', function () {
    $specPath = __DIR__.'/../../Fixtures/spec/openapi.json';

    // 1. Matching spec exits 0
    $this->artisan('geni:check', ['--path' => 'tests/Fixtures/spec/openapi.json'])
        ->assertSuccessful();

    // 2. Drifted spec exits non-zero (FAILURE)
    $tempDriftFile = sys_get_temp_dir().'/drifted_openapi.json';
    file_put_contents($tempDriftFile, json_encode([
        'openapi' => '3.1.0',
        'info' => ['title' => 'Drifted', 'version' => '1.0.0'],
        'paths' => (object) [],
    ]));

    try {
        $this->artisan('geni:check', ['--path' => $tempDriftFile])
            ->assertFailed();
    } finally {
        @unlink($tempDriftFile);
    }
});

test('geni:check treats key order as insignificant (no false drift across environments)', function () {
    // Regression: areStructurallyEqual() used strict `===` on decoded arrays, which is
    // order-sensitive for JSON objects. Route/reflection iteration order can differ across
    // environments (e.g. local macOS vs CI Ubuntu) even when the document content is
    // identical, causing spurious drift failures. A committed spec with the same content
    // but differently-ordered object keys must still be reported as up to date.
    $original = json_decode(
        file_get_contents(__DIR__.'/../../Fixtures/spec/openapi.json'),
        true
    );

    $reordered = array_reverse($original, true);
    $reordered['paths'] = array_reverse($original['paths'], true);

    $tempFile = sys_get_temp_dir().'/reordered_openapi.json';
    file_put_contents($tempFile, json_encode($reordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    try {
        $this->artisan('geni:check', ['--path' => $tempFile])
            ->assertSuccessful();
    } finally {
        @unlink($tempFile);
    }
});

test('geni:cache and geni:clear commands manage cached specification', function () {
    config(['geni.cache.enabled' => true]);

    $cacheKey = config('geni.cache.key', 'geni.openapi').'.default';
    Cache::forget($cacheKey);

    // 1. geni:cache
    $this->artisan('geni:cache')
        ->assertSuccessful();
    expect(Cache::has($cacheKey))->toBeTrue();

    // 2. geni:clear
    $this->artisan('geni:clear')
        ->assertSuccessful();
    expect(Cache::has($cacheKey))->toBeFalse();
});

test('schema_overrides resolves column type when missing from migration history', function () {
    $inferer = new PathParameterInferer;

    $dbSchema = new DatabaseSchema;
    $table = new TableSchema('posts', null, []);
    $dbSchema->tables['posts'] = $table;

    $overrides = [
        'posts.legacy_code' => 'string',
        'posts.custom_int' => 'integer',
    ];

    $res = $inferer->inferParam(
        paramName: 'legacy_code',
        schema: $dbSchema,
        boundModelClass: 'Tests\Fixtures\App\Models\Post',
        routeKeyName: 'legacy_code',
        file: 'test.php',
        line: 10,
        schemaOverrides: $overrides
    );

    expect($res['parameter']->schema->type)->toBe('string');
    expect($res['diagnostics'])->toBeEmpty();
});

test('middleware-derived security adds security requirement and 401 response while @unauthenticated overrides it', function () {
    $routes = [
        [
            'uri' => '/api/protected',
            'methods' => ['GET'],
            'action' => ['type' => 'closure', 'file' => __FILE__, 'line' => __LINE__],
            'middleware' => ['auth:sanctum'],
            'name' => 'protected.route',
        ],
        [
            'uri' => '/api/public',
            'methods' => ['GET'],
            'action' => ['type' => 'closure', 'file' => __FILE__, 'line' => __LINE__],
            'middleware' => [],
            'name' => 'public.route',
        ],
    ];

    $assembler = new DocumentAssembler;
    $document = $assembler->assemble(
        routes: $routes,
        infoOptions: ['title' => 'Security Test', 'version' => '1.0.0'],
        dbSchema: null,
        schemaOverrides: [],
        securityConfig: [
            'enabled' => true,
            'middleware' => ['auth', 'auth:*'],
            'scheme_name' => 'bearerAuth',
            'scheme' => ['type' => 'http', 'scheme' => 'bearer'],
        ]
    );

    $json = json_decode(json_encode($document), true);

    // Protected route has security requirement & 401 response
    $protectedGet = $json['paths']['/api/protected']['get'];
    expect($protectedGet['security'])->toBe([['bearerAuth' => []]]);
    expect($protectedGet['responses'])->toHaveKey('401');
    expect($json['components']['securitySchemes'])->toHaveKey('bearerAuth');

    // Public route has explicitly empty security requirement & no 401 response
    $publicGet = $json['paths']['/api/public']['get'];
    expect($publicGet['security'])->toBe([]);
    expect($publicGet['responses'])->not->toHaveKey('401');
});

test('per-middleware security rules document different schemes for different middleware', function () {
    $routes = [
        [
            'uri' => '/api/sanctum-route',
            'methods' => ['GET'],
            'action' => ['type' => 'closure', 'file' => __FILE__, 'line' => __LINE__],
            'middleware' => ['auth:sanctum'],
            'name' => 'sanctum.route',
        ],
        [
            'uri' => '/api/apikey-route',
            'methods' => ['GET'],
            'action' => ['type' => 'closure', 'file' => __FILE__, 'line' => __LINE__],
            'middleware' => ['auth:api-key'],
            'name' => 'apikey.route',
        ],
        [
            'uri' => '/api/fallback-route',
            'methods' => ['GET'],
            'action' => ['type' => 'closure', 'file' => __FILE__, 'line' => __LINE__],
            'middleware' => ['auth'],
            'name' => 'fallback.route',
        ],
    ];

    $assembler = new DocumentAssembler;
    $document = $assembler->assemble(
        routes: $routes,
        infoOptions: ['title' => 'Security Rules Test', 'version' => '1.0.0'],
        dbSchema: null,
        schemaOverrides: [],
        securityConfig: [
            'enabled' => true,
            'middleware' => ['auth', 'auth:*'],
            'scheme_name' => 'bearerAuth',
            'scheme' => ['type' => 'http', 'scheme' => 'bearer'],
            'rules' => [
                [
                    'middleware' => ['auth:sanctum'],
                    'name' => 'sanctumAuth',
                    'scheme' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                ],
                [
                    'middleware' => ['auth:api-key'],
                    'name' => 'apiKeyAuth',
                    'scheme' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                ],
            ],
        ]
    );

    $json = json_decode(json_encode($document), true);

    // Matched by the sanctum rule
    expect($json['paths']['/api/sanctum-route']['get']['security'])->toBe([['sanctumAuth' => []]]);
    expect($json['components']['securitySchemes']['sanctumAuth'])->toBe([
        'type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT',
    ]);

    // Matched by the api-key rule, a completely different scheme type
    expect($json['paths']['/api/apikey-route']['get']['security'])->toBe([['apiKeyAuth' => []]]);
    expect($json['components']['securitySchemes']['apiKeyAuth'])->toBe([
        'type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key',
    ]);

    // Not matched by any rule, falls back to the global scheme
    expect($json['paths']['/api/fallback-route']['get']['security'])->toBe([['bearerAuth' => []]]);
    expect($json['components']['securitySchemes'])->toHaveKey('bearerAuth');
});

test('a route behind two matching middleware requires both schemes at once (AND, not OR)', function () {
    $routes = [
        [
            'uri' => '/api/two-layer-route',
            'methods' => ['GET'],
            'action' => ['type' => 'closure', 'file' => __FILE__, 'line' => __LINE__],
            'middleware' => ['auth:sanctum', 'api-key'],
            'name' => 'two.layer.route',
        ],
    ];

    $assembler = new DocumentAssembler;
    $document = $assembler->assemble(
        routes: $routes,
        infoOptions: ['title' => 'Two-Layer Auth Test', 'version' => '1.0.0'],
        dbSchema: null,
        schemaOverrides: [],
        securityConfig: [
            'enabled' => true,
            'middleware' => ['auth', 'auth:*'],
            'scheme_name' => 'bearerAuth',
            'scheme' => ['type' => 'http', 'scheme' => 'bearer'],
            'rules' => [
                [
                    'middleware' => ['auth:sanctum'],
                    'name' => 'sanctumAuth',
                    'scheme' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                ],
                [
                    'middleware' => ['api-key'],
                    'name' => 'apiKeyAuth',
                    'scheme' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
                ],
            ],
        ]
    );

    $json = json_decode(json_encode($document), true);

    // Both schemes required in ONE requirement object = AND semantics
    expect($json['paths']['/api/two-layer-route']['get']['security'])->toBe([[
        'sanctumAuth' => [],
        'apiKeyAuth' => [],
    ]]);
    expect($json['components']['securitySchemes'])->toHaveKeys(['sanctumAuth', 'apiKeyAuth']);
});

test('config(geni.renderer) resolves a custom Renderer class, not just "elements"', function () {
    // Regression: resolveRenderer() ignored config('geni.renderer') entirely
    // and always returned ElementsRenderer, making it impossible to plug in
    // a custom Renderer implementation via config as the docs advertise.
    config(['geni.renderer' => CustomRendererFixture::class]);

    $controller = app(DocumentationController::class);
    $response = $controller->ui();

    expect($response->getContent())->toBe('custom-renderer-output');
});

test('config(geni.renderer) falls back to BladeRenderer for an invalid class', function () {
    config(['geni.renderer' => 'Not\A\Real\Class']);

    $controller = app(DocumentationController::class);
    $response = $controller->ui();

    expect($response->getContent())->toContain('geniDocs');
});

test('live docs JSON route applies middleware_security_schemes, not just geni:export', function () {
    // Regression: DocumentationController used to build its own securityConfig
    // inline instead of the shared BuildsSecurityConfig trait, so a scheme
    // set via geni:export never applied to the actual /docs/api.json route.
    config(['geni.middleware_security_schemes' => [
        'auth:sanctum' => [
            'name' => 'sanctumAuth',
            'scheme' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
        ],
    ]]);

    Route::middleware('auth:sanctum')->get('/api/sanctum-probe', fn () => []);

    $controller = app(DocumentationController::class);
    $document = $controller->generateDocument('default');
    $json = json_decode(json_encode($document), true);

    expect($json['components']['securitySchemes'] ?? [])->toHaveKey('sanctumAuth');
});

test('live docs JSON route serves from cache on a hit instead of regenerating', function () {
    config(['geni.cache.enabled' => true]);
    $cacheKey = config('geni.cache.key', 'geni.openapi').'.default';

    Cache::put($cacheKey, json_encode(['openapi' => '3.1.0', 'info' => ['title' => 'From Cache', 'version' => '9.9.9'], 'paths' => (object) []]));

    $response = $this->get('/docs/api.json');

    $response->assertOk();
    expect(json_decode($response->getContent(), true)['info']['title'])->toBe('From Cache');

    Cache::forget($cacheKey);
});

test('document info (title, description, contact, license) is populated from config', function () {
    config([
        'geni.title' => 'My Custom API',
        'geni.description' => 'An overview describing what this API does.',
        'geni.version' => '2.5.0',
        'geni.terms_of_service' => 'https://example.com/terms',
        'geni.contact' => ['name' => 'Support', 'email' => 'support@example.com', 'url' => null],
        'geni.license' => ['name' => 'MIT', 'url' => 'https://opensource.org/licenses/MIT'],
    ]);

    $controller = app(DocumentationController::class);
    $document = $controller->generateDocument('default');
    $json = json_decode(json_encode($document), true);

    expect($json['info'])->toBe([
        'title' => 'My Custom API',
        'version' => '2.5.0',
        'description' => 'An overview describing what this API does.',
        'termsOfService' => 'https://example.com/terms',
        'contact' => ['name' => 'Support', 'email' => 'support@example.com'],
        'license' => ['name' => 'MIT', 'url' => 'https://opensource.org/licenses/MIT'],
    ]);
});

test('document info falls back to app name and omits unset optional fields', function () {
    config(['geni.title' => null, 'geni.description' => null, 'geni.contact' => [], 'geni.license' => ['name' => null]]);
    config(['app.name' => 'Fallback App']);

    $controller = app(DocumentationController::class);
    $document = $controller->generateDocument('default');
    $json = json_decode(json_encode($document), true);

    expect($json['info']['title'])->toBe('Fallback App');
    expect($json['info'])->not->toHaveKeys(['description', 'contact', 'license', 'termsOfService']);
});

test('backward compatibility: single API document projects require zero config changes', function () {
    // Top-level config with no 'apis' array
    config([
        'geni.api_path' => 'api',
        'geni.apis' => [],
    ]);

    $controller = new DocumentationController;
    $doc = $controller->generateDocument();

    expect($doc->openapi)->toBe('3.1.0');
    expect($doc->paths->items)->not->toBeEmpty();
});

/**
 * @internal
 */
final class CustomRendererFixture implements Renderer
{
    public function render(OpenApiDocument $document, array $config): Response
    {
        return new Response('custom-renderer-output');
    }
}
