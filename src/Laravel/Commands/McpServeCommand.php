<?php

declare(strict_types=1);

namespace Geni\Laravel\Commands;

use Geni\Laravel\Controllers\DocumentationController;
use Geni\Laravel\Mcp\McpProtocolHandler;
use Illuminate\Console\Command;
use Throwable;

final class McpServeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geni:mcp:serve
                            {--api=default : The API configuration name}
                            {--base-url= : Override the API base URL for execution}
                            {--timeout=15 : HTTP request timeout in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the Model Context Protocol (MCP) server over standard input/output (stdio)';

    public function handle(): int
    {
        $apiName = $this->option('api') ?: 'default';
        $baseUrl = $this->option('base-url') ?: null;
        $timeout = (int) ($this->option('timeout') ?: 15);

        /** @var DocumentationController $controller */
        $controller = app(DocumentationController::class);
        $document = $controller->generateDocument($apiName);

        $mcpConfig = config('geni.mcp', []);
        if ($baseUrl !== null) {
            $mcpConfig['execution']['base_url'] = $baseUrl;
        }
        $mcpConfig['execution']['timeout'] = $timeout;

        $handler = new McpProtocolHandler;

        // Logging goes to STDERR so STDOUT remains a clean JSON-RPC stream
        fwrite(STDERR, "[geni:mcp:serve] MCP stdio server started. Listening on STDIN...\n");

        while (! feof(STDIN)) {
            $line = fgets(STDIN);

            if ($line === false) {
                break;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $payload = json_decode($line, true);

                if (! is_array($payload)) {
                    $this->sendRawResponse([
                        'jsonrpc' => '2.0',
                        'id' => null,
                        'error' => [
                            'code' => -32700,
                            'message' => 'Parse error: Invalid JSON',
                        ],
                    ]);

                    continue;
                }

                // Refresh mcp execution config dynamically per request so env vars injected at runtime are picked up
                $currentMcpConfig = config('geni.mcp', []);
                if ($baseUrl !== null) {
                    $currentMcpConfig['execution']['base_url'] = $baseUrl;
                }
                $currentMcpConfig['execution']['timeout'] = $timeout;

                $response = $handler->handle($payload, $document, [
                    'mcp' => $currentMcpConfig,
                ]);

                if ($response !== null) {
                    $this->sendRawResponse($response);
                }
            } catch (Throwable $e) {
                fwrite(STDERR, sprintf("[geni:mcp:serve] Error: %s\n", $e->getMessage()));
                $this->sendRawResponse([
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => [
                        'code' => -32603,
                        'message' => sprintf('Internal error: %s', $e->getMessage()),
                    ],
                ]);
            }
        }

        fwrite(STDERR, "[geni:mcp:serve] Connection closed. Exiting.\n");

        return self::SUCCESS;
    }

    /**
     * Write JSON-RPC response message to standard output followed by newline.
     *
     * @param  array<string, mixed>  $data
     */
    private function sendRawResponse(array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo $json."\n";
        flush();
    }
}
