<?php

declare(strict_types=1);

namespace Geni\Laravel\Mcp;

use Geni\Inference\Document\OpenApiDocument;
use Throwable;

/**
 * Handles JSON-RPC 2.0 requests according to the official Model Context Protocol specification.
 *
 * Supported methods:
 * - initialize
 * - notifications/initialized
 * - ping
 * - tools/list
 * - tools/call
 */
final class McpProtocolHandler
{
    /**
     * MCP protocol revisions this server speaks, newest first. A client asking
     * for an unknown revision is answered with the newest one, per the spec's
     * version negotiation.
     *
     * @var list<string>
     */
    private const SUPPORTED_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private McpManifestGenerator $generator;

    private McpToolExecutor $executor;

    public function __construct(
        ?McpManifestGenerator $generator = null,
        ?McpToolExecutor $executor = null
    ) {
        $this->generator = $generator ?? new McpManifestGenerator;
        $this->executor = $executor ?? new McpToolExecutor;
    }

    /**
     * Handle an incoming JSON-RPC 2.0 payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null Returns null for notifications that require no response
     */
    public function handle(array $payload, OpenApiDocument $document, array $config = []): ?array
    {
        $id = $payload['id'] ?? null;
        $method = $payload['method'] ?? null;
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        // Validate JSON-RPC 2.0 version
        if (($payload['jsonrpc'] ?? '') !== '2.0' || ! is_string($method)) {
            return $this->errorResponse($id, -32600, 'Invalid Request');
        }

        // JSON-RPC notifications carry no id and must never receive a response.
        if (! array_key_exists('id', $payload)) {
            return null;
        }

        try {
            return match ($method) {
                'initialize' => $this->handleInitialize($id, $params),
                'ping' => $this->successResponse($id, (object) []),
                'tools/list' => $this->handleToolsList($id, $document, $config),
                'tools/call' => ($config['allow_tools_call'] ?? true)
                    ? $this->handleToolsCall($id, $params, $document, $config)
                    : $this->errorResponse($id, -32601, 'tools/call is disabled over HTTP. Set geni.mcp.http_execution to true or use php artisan geni:mcp:serve.'),
                default => $this->errorResponse($id, -32601, sprintf('Method not found: %s', $method)),
            };
        } catch (Throwable $e) {
            report($e);

            return $this->errorResponse($id, -32603, 'Internal error');
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function handleInitialize(mixed $id, array $params): array
    {
        $requestedVersion = $params['protocolVersion'] ?? null;
        $protocolVersion = in_array($requestedVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)
            ? $requestedVersion
            : self::SUPPORTED_PROTOCOL_VERSIONS[0];

        return $this->successResponse($id, [
            'protocolVersion' => $protocolVersion,
            'capabilities' => [
                'tools' => (object) [],
            ],
            'serverInfo' => [
                'name' => 'geni-mcp',
                'version' => '1.0.0',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function handleToolsList(mixed $id, OpenApiDocument $document, array $config): array
    {
        $mcpConfig = $config['mcp'] ?? config('geni.mcp', []);
        $manifest = $this->generator->generate($document, $mcpConfig);

        return $this->successResponse($id, $manifest);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function handleToolsCall(mixed $id, array $params, OpenApiDocument $document, array $config): array
    {
        $toolName = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (! is_string($toolName) || $toolName === '') {
            return $this->errorResponse($id, -32602, 'Invalid params: missing tool "name"');
        }

        if (! is_array($arguments)) {
            $arguments = [];
        }

        $mcpConfig = $config['mcp'] ?? config('geni.mcp', []);
        $manifest = $this->generator->generate($document, $mcpConfig);

        /** @var McpTool|null $targetTool */
        $targetTool = null;
        foreach ($manifest['tools'] as $tool) {
            if ($tool->name === $toolName) {
                $targetTool = $tool;
                break;
            }
        }

        if ($targetTool === null) {
            // In MCP specification, an unknown tool call returns CallToolResult with isError: true
            return $this->successResponse($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => sprintf('Tool not found: %s', $toolName),
                    ],
                ],
                'isError' => true,
            ]);
        }

        $execConfig = $mcpConfig['execution'] ?? config('geni.mcp.execution', []);
        $callResult = $this->executor->execute($targetTool, $arguments, $execConfig);

        return $this->successResponse($id, $callResult);
    }

    /**
     * @return array<string, mixed>
     */
    private function successResponse(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
