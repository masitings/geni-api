<?php

declare(strict_types=1);

use Geni\Laravel\Controllers\DocumentationController;
use Geni\Laravel\Mcp\McpManifestGenerator;
use Geni\Laravel\Mcp\McpTool;
use Tests\TestCase;

class McpManifestTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('geni.docs_auth.username', 'admin');
        $app['config']->set('geni.docs_auth.password', 'secret');
    }

    public function test_mcp_command_generates_valid_manifest_file(): void
    {
        $manifestPath = sys_get_temp_dir().'/test-mcp-manifest.json';
        if (file_exists($manifestPath)) {
            unlink($manifestPath);
        }

        $this->artisan('geni:mcp', ['--path' => $manifestPath])
            ->assertSuccessful();

        expect(file_exists($manifestPath))->toBeTrue();
        $json = json_decode((string) file_get_contents($manifestPath), true);

        expect($json)->toHaveKey('tools');
        expect($json['tools'])->toBeArray();
        expect(count($json['tools']))->toBeGreaterThan(0);

        // Verify structure of the first tool
        $firstTool = $json['tools'][0];
        expect($firstTool)->toHaveKeys(['name', 'description', 'inputSchema']);
        expect($firstTool['inputSchema'])->toHaveKey('type');
        expect($firstTool['inputSchema']['type'])->toBe('object');
        expect($firstTool['name'])->toMatch('/^[a-zA-Z0-9_-]{1,64}$/');

        @unlink($manifestPath);
    }

    public function test_mcp_command_outputs_client_config(): void
    {
        $this->artisan('geni:mcp', [
            '--path' => '-',
            '--format' => 'client-config',
            '--base-url' => 'https://api.example.com',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('mcpServers');
    }

    public function test_mcp_http_endpoint_serves_tools_list_json(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('admin:secret'),
        ])->get('/docs/api/mcp');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');

        $data = $response->json();
        expect($data)->toHaveKey('tools');
        expect($data['tools'])->toBeArray();
        expect(count($data['tools']))->toBeGreaterThan(0);
    }

    public function test_mcp_tool_name_sanitizer(): void
    {
        expect(McpTool::sanitizeName('GET /api/v1/users/{id}'))->toBe('GET_api_v1_users_id');
        expect(McpTool::sanitizeName('create-user-account!'))->toBe('create-user-account');
        expect(strlen(McpTool::sanitizeName(str_repeat('a', 100))))->toBe(64);
    }

    public function test_mcp_generator_filtering_and_schema_dereferencing(): void
    {
        /** @var DocumentationController $controller */
        $controller = app(DocumentationController::class);
        $document = $controller->generateDocument();

        $generator = new McpManifestGenerator;

        // 1. Exclude all posts methods
        $filtered = $generator->generate($document, [
            'exclude_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
        ]);
        expect($filtered['tools'])->toBeEmpty();

        // 2. Normal generation
        $manifest = $generator->generate($document);
        expect($manifest['tools'])->not->toBeEmpty();

        // Check tool schema details
        $postStore = null;
        foreach ($manifest['tools'] as $tool) {
            if (str_contains($tool->name, 'posts') && str_starts_with($tool->name, 'post')) {
                $postStore = $tool;
                break;
            }
        }

        expect($postStore)->not->toBeNull();
        expect($postStore->inputSchema['type'])->toBe('object');
        expect($postStore->inputSchema['properties'])->toHaveKey('title');
        expect($postStore->inputSchema['required'])->toContain('title');
        expect($postStore->meta['method'])->toBe('POST');
    }

    public function test_mcp_endpoint_inherits_docs_auth_protection(): void
    {
        config(['geni.docs_auth.mode' => 'basic']);
        config(['geni.docs_auth.username' => 'admin']);
        config(['geni.docs_auth.password' => 'secret']);

        // Unauthenticated request
        $this->get('/docs/api/mcp')
            ->assertStatus(401);

        // Authenticated request
        $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('admin:secret'),
        ])->get('/docs/api/mcp')->assertOk();
    }
}
