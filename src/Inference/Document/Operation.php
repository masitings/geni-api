<?php

declare(strict_types=1);

namespace Geni\Inference\Document;

use JsonSerializable;

/**
 * @see https://spec.openapis.org/oas/v3.1.0#operation-object
 */
final class Operation implements JsonSerializable
{
    public string $method;

    public string $pathSummary;

    public ?string $summary = null;

    /** @var list<string> */
    public array $tags = [];

    /** @var list<Parameter> */
    public array $parameters = [];

    public ?RequestBody $requestBody = null;

    /** @var array{status: int, description: string, content: array<string, mixed>}|null */
    public $response = null;

    /** @var array<string, array{description: string, content?: array<string, mixed>}> */
    public array $responses = [];

    public ?string $description = null;

    public ?string $operationId = null;

    public ?bool $deprecated = null;

    /** @var list<array<string, list<string>>>|null */
    public ?array $security = null;

    /** @var array<string, mixed> */
    public array $extensions = [];

    /** @var array{source: string, reason: string}|null */
    public $diagnostic = null;

    /**
     * @param  string  $method  HTTP method (GET, POST, etc.)
     */
    public function __construct(string $method, string $summary = '')
    {
        $this->method = strtoupper($method);
        $this->pathSummary = $summary;
        $this->summary = $summary !== '' ? $summary : null;
    }

    public function jsonSerialize(): array
    {
        $responses = $this->responses;

        if (! isset($responses['200'])) {
            $responses['200'] = $this->response !== null
                ? [
                    'description' => $this->response['description'],
                    'content' => $this->serializeContent($this->response['content']),
                ]
                : ['description' => 'Successful response'];
        }

        // Sort response keys (e.g. 200, 401, 403, 404, 422)
        ksort($responses);

        $result = [
            'summary' => $this->pathSummary,
            'responses' => $responses,
        ];

        if ($this->tags !== []) {
            $result['tags'] = $this->tags;
        }

        if ($this->description !== null) {
            $result['description'] = $this->description;
        }

        if ($this->operationId !== null) {
            $result['operationId'] = $this->operationId;
        }

        if ($this->deprecated !== null) {
            $result['deprecated'] = $this->deprecated;
        }

        if ($this->security !== null) {
            $result['security'] = $this->security;
        }

        if ($this->parameters !== []) {
            $result['parameters'] = array_map(
                fn (Parameter $p) => $p->jsonSerialize(),
                $this->parameters
            );
        }

        if ($this->requestBody !== null) {
            $result['requestBody'] = $this->requestBody->jsonSerialize();
        }

        if ($this->extensions !== []) {
            $result['x-geni-unresolved'] = $this->extensions;
        }

        return $result;
    }

    private function serializeContent(array $content): array
    {
        $res = [];
        foreach ($content as $mediaType => $info) {
            $schema = $info['schema'] ?? null;
            $res[$mediaType] = [
                'schema' => $schema instanceof JsonSerializable ? $schema->jsonSerialize() : $schema,
            ];
        }

        return $res;
    }
}
