<?php

declare(strict_types=1);

use Geni\Laravel\Controllers\DocumentationController;
use Geni\Laravel\Mcp\McpProtocolHandler;
use Geni\Laravel\Mcp\McpTool;
use Geni\Laravel\Mcp\McpToolExecutor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpExecutionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('geni.mcp.execution.base_url', 'http://localhost');
        $app['config']->set('geni.mcp.execution.auth.default_bearer_token', 'test-token-xyz');
        $app['config']->set('geni.mcp.execution.auth.default_api_key', 'test-api-key-123');
    }

    public function test_protocol_handler_handles_initialize_ping_and_tools_list(): void
    {
        /** @var DocumentationController $controller */
        $controller = app(DocumentationController::class);
        $document = $controller->generateDocument();

        $handler = new McpProtocolHandler;

        // 1. initialize
        $initResp = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05'],
        ], $document);

        expect($initResp)->toHaveKeys(['jsonrpc', 'id', 'result']);
        expect($initResp['result']['serverInfo']['name'])->toBe('geni-mcp');
        expect($initResp['result']['capabilities'])->toHaveKey('tools');

        // 2. ping
        $pingResp = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'ping',
        ], $document);

        expect($pingResp['result'])->toBeObject();

        // 3. tools/list
        $toolsResp = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/list',
        ], $document);

        expect($toolsResp['result'])->toHaveKey('tools');
        expect($toolsResp['result']['tools'])->toBeArray();
        expect(count($toolsResp['result']['tools']))->toBeGreaterThan(0);
    }

    public function test_executor_executes_get_endpoint_with_path_parameters_and_auth(): void
    {
        Http::fake([
            'http://localhost/api/posts/42' => Http::response([
                'id' => 42,
                'title' => 'Real Fetched Post',
            ], 200),
        ]);

        $tool = new McpTool(
            name: 'get_api_posts_id',
            description: 'Get post by ID',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
            ],
            meta: [
                'method' => 'GET',
                'path' => '/api/posts/{id}',
                'security' => ['bearerAuth'],
            ]
        );

        $executor = new McpToolExecutor;
        $result = $executor->execute($tool, ['id' => 42], [
            'base_url' => 'http://localhost',
            'auth' => [
                'default_bearer_token' => 'secret-bearer-123',
            ],
        ]);

        expect($result['isError'])->toBeFalse();
        expect($result['content'][0]['text'])->toContain('HTTP 200 OK');
        expect($result['content'][0]['text'])->toContain('Real Fetched Post');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost/api/posts/42'
                && $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'Bearer secret-bearer-123')
                && $request->hasHeader('Accept', 'application/json');
        });
    }

    public function test_executor_executes_post_endpoint_with_json_payload(): void
    {
        Http::fake([
            'http://localhost/api/posts' => Http::response([
                'id' => 100,
                'title' => 'Created Post',
            ], 201),
        ]);

        $tool = new McpTool(
            name: 'post_api_posts',
            description: 'Create post',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                ],
                'required' => ['title'],
            ],
            meta: [
                'method' => 'POST',
                'path' => '/api/posts',
                'security' => [],
            ]
        );

        $executor = new McpToolExecutor;
        $result = $executor->execute($tool, [
            'title' => 'Created Post',
            'body' => 'Post content here',
        ], [
            'base_url' => 'http://localhost',
        ]);

        expect($result['isError'])->toBeFalse();
        expect($result['content'][0]['text'])->toContain('HTTP 201 Created');
        expect($result['content'][0]['text'])->toContain('Created Post');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost/api/posts'
                && $request->method() === 'POST'
                && $request['title'] === 'Created Post'
                && $request['body'] === 'Post content here';
        });
    }

    public function test_executor_formats_http_error_responses_with_is_error_true(): void
    {
        Http::fake([
            'http://localhost/api/posts/999' => Http::response([
                'message' => 'Post not found.',
            ], 404),
        ]);

        $tool = new McpTool(
            name: 'get_api_posts_id',
            description: 'Get post by ID',
            inputSchema: ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            meta: ['method' => 'GET', 'path' => '/api/posts/{id}']
        );

        $executor = new McpToolExecutor;
        $result = $executor->execute($tool, ['id' => 999], ['base_url' => 'http://localhost']);

        expect($result['isError'])->toBeTrue();
        expect($result['content'][0]['text'])->toContain('HTTP 404 Not Found');
        expect($result['content'][0]['text'])->toContain('Post not found.');
    }

    public function test_executor_handles_missing_required_path_parameter(): void
    {
        $tool = new McpTool(
            name: 'get_api_posts_id',
            description: 'Get post by ID',
            inputSchema: ['type' => 'object'],
            meta: ['method' => 'GET', 'path' => '/api/posts/{id}']
        );

        $executor = new McpToolExecutor;
        $result = $executor->execute($tool, [], ['base_url' => 'http://localhost']);

        expect($result['isError'])->toBeTrue();
        expect($result['content'][0]['text'])->toContain('Missing required path parameter: id');
    }

    public function test_http_endpoint_executes_tools_call_via_post(): void
    {
        Http::fake([
            'http://localhost/api/posts' => Http::response([
                ['id' => 1, 'title' => 'Post One'],
            ], 200),
        ]);

        $response = $this->postJson('/docs/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'call-1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_api_posts',
                'arguments' => [],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('jsonrpc', '2.0');
        $response->assertJsonPath('id', 'call-1');
        $response->assertJsonPath('result.isError', false);

        $text = $response->json('result.content.0.text');
        expect($text)->toContain('HTTP 200 OK');
        expect($text)->toContain('Post One');
    }

    public function test_declarative_get_endpoint_still_serves_manifest(): void
    {
        $response = $this->get('/docs/api/mcp');

        $response->assertOk();
        $response->assertJsonPath('tools.0.name', 'get_api_posts');
    }
}
