<?php

declare(strict_types=1);

use Geni\Inference\DocumentAssembler;
use Geni\Laravel\RouteDiscoverer;
use Geni\SchemaReader\SchemaReader;
use Opis\JsonSchema\Validator;
use Tests\TestCase;

/**
 * @internal
 */
final class GeniFeatureTest extends TestCase
{
    /**
     * TASK-020: OpenAPI 3.1.0 schema validation test
     */
    public function test_validates_against_openapi_3_1_0_schema(): void
    {
        $document = $this->generateDocument();

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json, 'Failed to encode document to JSON');

        // Validate against the official OpenAPI 3.1.0 JSON Schema meta-schema
        $validator = new Validator;
        $validator->setMaxErrors(10);

        $schema = $this->getOpenApiSchema();
        $data = json_decode($json);
        $result = $validator->validate($data, $schema);

        if (! $result->isValid()) {
            $this->fail('OpenAPI document failed validation against official OpenAPI 3.1.0 JSON Schema: '.$result->error()->message());
        }

        $this->assertTrue($result->isValid(), 'OpenAPI document must validate against official OpenAPI 3.1.0 JSON Schema');
    }

    /**
     * TASK-021: docs UI render smoke test
     */
    public function test_renders_docs_ui_without_error_in_local_environment(): void
    {
        $response = $this->get('/docs/api');

        $response->assertStatus(200);
        $response->assertSee('geniDocs', false);
        $response->assertSee('cdn.tailwindcss.com', false);
    }

    /**
     * TASK-022: Exit criterion validation (5-endpoint fixture, end-to-end)
     */
    public function test_fixture_app_produces_valid_openapi_document_and_docs_ui_renders(): void
    {
        $document = $this->generateDocument();

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($json);

        // Validate against the official OpenAPI 3.1.0 JSON Schema meta-schema
        $validator = new Validator;
        $schema = $this->getOpenApiSchema();

        $data = json_decode($json);
        $result = $validator->validate($data, $schema);
        if (! $result->isValid()) {
            $this->fail('Document failed validation against OpenAPI 3.1.0 JSON Schema: '.$result->error()->message());
        }
        $this->assertTrue($result->isValid(), 'Document must validate against official OpenAPI 3.1.0 JSON Schema');

        // Test docs UI renders without error
        $response = $this->get('/docs/api');
        $response->assertStatus(200);
        $response->assertSee('geniDocs', false);

        // Determinism (FR-013)
        $json2 = json_encode($this->generateDocument(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->assertSame($json, $json2, 'Export must be deterministic (byte-identical across runs)');

        // Validate that document contains all 5 fixture endpoints
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('paths', $decoded);
        $paths = $decoded['paths'];

        // Debug: dump discovered paths
        if (empty($paths)) {
            $uris = [];
            foreach (Route::getRoutes() as $route) {
                $uris[] = $route->uri();
            }

            $this->fail('No paths found in document. Discovered route URIs: '.implode(', ', $uris));
        }

        $expectedPaths = [
            '/api/posts',
            '/api/posts/{post}',
        ];

        foreach ($expectedPaths as $expected) {
            $this->assertArrayHasKey($expected, $paths, "Missing expected path: {$expected}");
        }

        // Check operations on /api/posts: GET (index), POST (store)
        $this->assertArrayHasKey('get', $paths['/api/posts']);
        $this->assertArrayHasKey('post', $paths['/api/posts']);

        // Check operations on /api/posts/{post}: GET (show), PUT (update), DELETE (destroy)
        $this->assertArrayHasKey('get', $paths['/api/posts/{post}']);
        $this->assertArrayHasKey('put', $paths['/api/posts/{post}']);
        $this->assertArrayHasKey('delete', $paths['/api/posts/{post}']);

        // Check path parameter on /api/posts/{post}
        $showGet = $paths['/api/posts/{post}']['get'];
        $this->assertArrayHasKey('parameters', $showGet);
        $this->assertSame('post', $showGet['parameters'][0]['name']);
        $this->assertSame('path', $showGet['parameters'][0]['in']);

        // Check request body on /api/posts (POST store)
        $storePost = $paths['/api/posts']['post'];
        $this->assertArrayHasKey('requestBody', $storePost);
        $this->assertArrayHasKey('application/json', $storePost['requestBody']['content']);
        $reqSchema = $storePost['requestBody']['content']['application/json']['schema'];
        $this->assertArrayHasKey('properties', $reqSchema);
        $this->assertArrayHasKey('title', $reqSchema['properties']);
        $this->assertArrayHasKey('body', $reqSchema['properties']);
    }

    /**
     * Load the official OpenAPI 3.1.0 JSON Schema meta-schema.
     */
    protected function getOpenApiSchema(): object
    {
        $schemaPath = __DIR__.'/../../Fixtures/schemas/openapi-3.1.json';
        $schemaJson = file_get_contents($schemaPath);

        return json_decode($schemaJson, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Generate the OpenAPI document for live routes (fixture app + package).
     */
    protected function generateDocument()
    {
        $routes = RouteDiscoverer::discover();

        $plainRoutes = array_map(function ($route) {
            return [
                'uri' => $route->uri,
                'methods' => $route->methods,
                'action' => $route->action,
                'middleware' => $route->middleware,
                'name' => $route->name,
            ];
        }, $routes);

        $migrationPaths = [__DIR__.'/../../Fixtures/App/database/migrations'];
        $dbSchema = null;

        if (class_exists(SchemaReader::class)) {
            $schemaReader = new SchemaReader;
            $dbSchema = $schemaReader->read($migrationPaths);
        }

        $assembler = new DocumentAssembler;

        return $assembler->assemble(
            $plainRoutes,
            ['title' => 'Laravel API', 'version' => '1.0.0'],
            $dbSchema
        );
    }
}
