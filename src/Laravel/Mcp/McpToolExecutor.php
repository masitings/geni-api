<?php

declare(strict_types=1);

namespace Geni\Laravel\Mcp;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Executes an MCP tool call by dispatching a real HTTP request against the host application.
 *
 * Adheres strictly to the official Model Context Protocol CallToolResult specification:
 * {
 *   "content": [
 *     { "type": "text", "text": "..." }
 *   ],
 *   "isError": bool
 * }
 */
final class McpToolExecutor
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $config
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    public function execute(McpTool $tool, array $arguments, array $config = []): array
    {
        $meta = $tool->meta;
        $method = strtoupper($meta['method'] ?? 'GET');
        $rawPath = $meta['path'] ?? '/';
        $toolSecurity = $meta['security'] ?? [];

        // 1. Resolve Path Parameters
        $path = $rawPath;
        $consumedParams = [];
        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $rawPath, $pathMatches);
        $expectedPathParams = $pathMatches[1] ?? [];

        foreach ($expectedPathParams as $paramName) {
            if (! array_key_exists($paramName, $arguments)) {
                return $this->errorResult(sprintf('Missing required path parameter: %s', $paramName));
            }
            $val = (string) $arguments[$paramName];
            $path = str_replace('{'.$paramName.'}', rawurlencode($val), $path);
            $consumedParams[$paramName] = true;
        }

        // 2. Build Base URL & Full Request URL
        $baseUrl = $config['base_url'] ?? config('geni.mcp.execution.base_url') ?? config('app.url') ?? url('/');
        $baseUrl = rtrim((string) $baseUrl, '/');

        // If baseUrl is http://... and local environment uses https:// (like Laravel Herd), preserve scheme
        if (str_starts_with($baseUrl, 'http://') && ! str_contains($baseUrl, 'localhost') && ! str_contains($baseUrl, '127.0.0.1')) {
            // Check if https responds or prefer configured scheme
            $baseUrl = preg_replace('#^http://#', 'https://', $baseUrl) ?? $baseUrl;
        }

        $url = $baseUrl.(str_starts_with($path, '/') ? $path : '/'.$path);

        // 3. Resolve Query, Header, & Body Parameters
        $queryParams = [];
        $headerParams = [];
        $bodyData = [];

        // Check inputSchema properties to know which params are marked as header
        $schemaProperties = $tool->inputSchema['properties'] ?? [];

        $isGetOrDelete = in_array($method, ['GET', 'DELETE', 'HEAD'], true);

        // Handle nested body if present
        if (isset($arguments['body']) && is_array($arguments['body'])) {
            $bodyData = $arguments['body'];
            $consumedParams['body'] = true;
        }

        foreach ($arguments as $key => $value) {
            if (isset($consumedParams[$key])) {
                continue;
            }

            if ($key === '_headers' && ($config['allow_caller_headers'] ?? config('geni.mcp.execution.allow_caller_headers', false))) {
                if (is_array($value)) {
                    foreach ($value as $hk => $hv) {
                        $headerParams[(string) $hk] = (string) $hv;
                    }
                }

                continue;
            }

            if (isset($schemaProperties[$key])) {
                // If it's a known property from inputSchema
                if ($isGetOrDelete) {
                    $queryParams[$key] = $value;
                } else {
                    $bodyData[$key] = $value;
                }
            } else {
                // Default handling
                if ($isGetOrDelete) {
                    $queryParams[$key] = $value;
                } else {
                    $bodyData[$key] = $value;
                }
            }
        }

        // 4. Resolve Authentication Headers (FR-003)
        $authConfig = $config['auth'] ?? config('geni.mcp.execution.auth', []);
        $headers = array_merge([
            'Accept' => 'application/json',
        ], $headerParams);

        $headers = $this->attachAuthHeaders($headers, $toolSecurity, $authConfig);

        // 5. Dispatch HTTP Request
        $timeout = (int) ($config['timeout'] ?? config('geni.mcp.execution.timeout', 15));

        try {
            $httpClient = Http::timeout($timeout)
                ->withHeaders($headers);

            if (config('geni.mcp.execution.verify_ssl', true) === false) {
                $httpClient = $httpClient->withoutVerifying();
            }

            if (! empty($queryParams)) {
                $httpClient->withQueryParameters($queryParams);
            }

            $response = match ($method) {
                'GET' => $httpClient->get($url),
                'POST' => $httpClient->post($url, $bodyData),
                'PUT' => $httpClient->put($url, $bodyData),
                'PATCH' => $httpClient->patch($url, $bodyData),
                'DELETE' => $httpClient->delete($url, $bodyData),
                default => $httpClient->send($method, $url, ['json' => $bodyData]),
            };

            $statusCode = $response->status();
            $bodyText = $response->body();
            $isError = $statusCode >= 400;

            // Attempt JSON pretty-printing
            $decodedJson = json_decode($bodyText, true);
            $formattedBody = $decodedJson !== null
                ? json_encode($decodedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : $bodyText;

            $statusText = sprintf('HTTP %d %s', $statusCode, $this->statusTextFor($statusCode));
            $textOutput = $statusText."\n\n".($formattedBody !== '' ? $formattedBody : '(Empty response)');

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $textOutput,
                    ],
                ],
                'isError' => $isError,
            ];
        } catch (Throwable $e) {
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => sprintf("Request failed: %s\nTarget URL: %s %s", $e->getMessage(), $method, $url),
                    ],
                ],
                'isError' => true,
            ];
        }
    }

    /**
     * Attach authentication credentials matching tool's required security schemes.
     *
     * @param  array<string, string>  $headers
     * @param  list<string>  $toolSecurity
     * @param  array<string, mixed>  $authConfig
     * @return array<string, string>
     */
    private function attachAuthHeaders(array $headers, array $toolSecurity, array $authConfig): array
    {
        $schemes = $authConfig['schemes'] ?? [];

        // 1. Check scheme-specific configuration
        foreach ($toolSecurity as $schemeName) {
            if (isset($schemes[$schemeName])) {
                $scheme = $schemes[$schemeName];
                if (($scheme['type'] ?? '') === 'bearer' && ! empty($scheme['token'])) {
                    $headers['Authorization'] = 'Bearer '.$scheme['token'];
                } elseif (($scheme['type'] ?? '') === 'header' && ! empty($scheme['header']) && ! empty($scheme['value'])) {
                    $headers[$scheme['header']] = $scheme['value'];
                }
            }
        }

        // 2. Fallback to default tokens if Authorization header is not yet set
        if (! isset($headers['Authorization']) && ! empty($authConfig['default_bearer_token'])) {
            $headers['Authorization'] = 'Bearer '.$authConfig['default_bearer_token'];
        }

        // 3. Fallback to default API key if configured
        $apiKeyHeader = $authConfig['api_key_header'] ?? 'X-API-Key';
        if (! isset($headers[$apiKeyHeader]) && ! empty($authConfig['default_api_key'])) {
            $headers[$apiKeyHeader] = $authConfig['default_api_key'];
        }

        return $headers;
    }

    /**
     * @return array{content: list<array{type: string, text: string}>, isError: true}
     */
    private function errorResult(string $message): array
    {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $message,
                ],
            ],
            'isError' => true,
        ];
    }

    private function statusTextFor(int $code): string
    {
        return match ($code) {
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            422 => 'Unprocessable Content',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => '',
        };
    }
}
