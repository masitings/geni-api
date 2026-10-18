<?php

declare(strict_types=1);

use Geni\Laravel\DocumentGenerator;
use Geni\Laravel\Mcp\McpProtocolHandler;
use Geni\Laravel\Mcp\McpTool;
use Geni\Laravel\Mcp\McpToolExecutor;
use Geni\Laravel\RouteDiscoverer;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('geni.mcp.execution.base_url', 'http://localhost');
        $app['config']->set('geni.mcp.execution.auth.default_bearer_token', 'server-token');
        $app['config']->set('geni.apis', [
            "o'neil" => [
                'title' => 'Quoted API',
                'api_path' => 'api',
            ],
        ]);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        Route::get('apiary/bees', fn () => response()->json([]));
        Route::get('api-legacy/users', fn () => response()->json([]));
    }

    public function test_unknown_api_query_parameter_returns_404(): void
    {
        $this->get('/docs/api?api=missing')->assertNotFound();
        $this->get('/docs/api.json?api=missing')->assertNotFound();
        $this->get("/docs/api?api=x'-alert(1)-'")->assertNotFound();
    }

    public function test_api_query_parameter_cannot_reach_nested_config_values(): void
    {
        config(['geni.apis.v1' => ['api_path' => 'api', 'routes' => fn () => []]]);

        $this->get('/docs/api?api=v1.routes')->assertNotFound();
    }

    public function test_api_name_is_json_escaped_inside_alpine_attribute(): void
    {
        $response = $this->get("/docs/api?api=o'neil");

        $response->assertOk();
        expect($response->getContent())
            ->not->toContain("currentApi: 'o'neil'")
            ->toContain("currentApi: 'o\\u0027neil'");
    }

    public function test_custom_css_cannot_close_the_style_element(): void
    {
        config(['geni.renderers.blade.custom_css' => 'body{}</style><script>alert(1)</script>']);

        $response = $this->get('/docs/api');

        $response->assertOk();
        expect($response->getContent())->not->toContain('</style><script>alert(1)</script>');
    }

    public function test_mcp_post_requires_json_content_type(): void
    {
        $this->call('POST', '/docs/api/mcp', [], [], [], ['CONTENT_TYPE' => 'text/plain'],
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']))
            ->assertStatus(415);
    }

    public function test_mcp_tools_call_over_http_is_disabled_by_default(): void
    {
        Http::fake();

        $response = $this->postJson('/docs/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => ['name' => 'get_api_posts', 'arguments' => []],
        ]);

        $response->assertOk();
        $response->assertJsonPath('error.code', -32601);
        Http::assertNothingSent();
    }

    public function test_mcp_tools_list_over_http_still_works_when_execution_is_disabled(): void
    {
        $this->postJson('/docs/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'get_api_posts');
    }

    public function test_mcp_notifications_receive_no_response(): void
    {
        $this->postJson('/docs/api/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled'])
            ->assertNoContent();
    }

    public function test_mcp_initialize_negotiates_protocol_version(): void
    {
        $handler = new McpProtocolHandler;
        $document = app(DocumentGenerator::class)->generate();

        $known = $handler->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']], $document);
        $unknown = $handler->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']], $document);

        expect($known['result']['protocolVersion'])->toBe('2024-11-05');
        expect($unknown['result']['protocolVersion'])->toBe('2025-06-18');
    }

    public function test_mcp_executor_keeps_the_configured_http_scheme(): void
    {
        Http::fake(['http://app.test/*' => Http::response(['ok' => true])]);

        $tool = new McpTool('get_api_posts', 'List posts', ['type' => 'object', 'properties' => []], [
            'method' => 'GET',
            'path' => '/api/posts',
        ]);

        $result = (new McpToolExecutor)->execute($tool, [], ['base_url' => 'http://app.test']);

        expect($result['isError'])->toBeFalse();
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://app.test/api/posts');
    }

    public function test_api_path_prefix_matches_whole_segments_only(): void
    {
        $uris = array_map(fn ($route) => $route->uri, RouteDiscoverer::discover());

        expect($uris)->toContain('api/posts')
            ->not->toContain('apiary/bees')
            ->not->toContain('api-legacy/users');
    }
}
