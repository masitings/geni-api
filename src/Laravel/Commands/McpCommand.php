<?php

declare(strict_types=1);

namespace Geni\Laravel\Commands;

use Geni\Laravel\Controllers\DocumentationController;
use Geni\Laravel\Mcp\McpManifestGenerator;
use Illuminate\Console\Command;

final class McpCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geni:mcp
                            {--path=mcp-manifest.json : Destination file path (use "-" for stdout)}
                            {--api=default : The API configuration name}
                            {--format=manifest : Output format: "manifest" (raw tools array) or "client-config" (claude_desktop_config snippet)}
                            {--base-url= : Override the API base URL for client configuration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a Model Context Protocol (MCP) tool manifest or client configuration from OpenAPI spec';

    public function handle(): int
    {
        $apiName = $this->option('api') ?: 'default';
        $format = $this->option('format') ?: 'manifest';
        $path = $this->option('path') ?: 'mcp-manifest.json';
        $baseUrl = $this->option('base-url') ?: url('/');

        /** @var DocumentationController $controller */
        $controller = app(DocumentationController::class);
        $document = $controller->generateDocument($apiName);

        $mcpConfig = config('geni.mcp', []);
        $generator = new McpManifestGenerator;
        $manifest = $generator->generate($document, $mcpConfig);

        if ($format === 'client-config') {
            $specUrl = url(config('geni.docs_json_path', 'docs/api.json'));
            $outputData = [
                'mcpServers' => [
                    'api' => [
                        'command' => 'npx',
                        'args' => [
                            '-y',
                            '@modelcontextprotocol/server-openapi',
                            $specUrl,
                        ],
                        'env' => [
                            'API_BASE_URL' => $baseUrl,
                        ],
                    ],
                ],
            ];
        } else {
            $outputData = $manifest;
        }

        $json = json_encode($outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->error('Failed to encode MCP manifest to JSON.');

            return self::FAILURE;
        }

        if ($path === '-') {
            $this->output->write($json);

            return self::SUCCESS;
        }

        $destination = file_exists($path) || str_starts_with($path, '/') ? $path : base_path($path);
        $directory = dirname($destination);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($destination, $json);

        $this->info(sprintf('MCP tool manifest successfully written to %s (%d tools generated)', $path, count($manifest['tools'])));

        return self::SUCCESS;
    }
}
