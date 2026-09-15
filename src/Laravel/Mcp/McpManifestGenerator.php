<?php

declare(strict_types=1);

namespace Geni\Laravel\Mcp;

use Geni\Inference\Document\OpenApiDocument;
use Geni\Inference\Document\Operation;
use Geni\Inference\Document\PathItem;
use Geni\Inference\Document\Schema;

/**
 * Transforms an OpenApiDocument into a compliant Model Context Protocol (MCP) Tool Manifest.
 *
 * Adheres to the official MCP specification:
 * - tools/list payload structure
 * - Clean self-contained JSON Schema inputSchemas (dereferencing local $ref pointers)
 * - Tool names conforming to ^[a-zA-Z0-9_-]{1,64}$
 */
final class McpManifestGenerator
{
    /**
     * Generate an array of McpTool objects from an OpenApiDocument.
     *
     * @param  array<string, mixed>  $config
     * @return array{tools: list<McpTool>}
     */
    public function generate(OpenApiDocument $document, array $config = []): array
    {
        $excludeMethods = array_map('strtoupper', $config['exclude_methods'] ?? ['HEAD', 'OPTIONS']);
        $includeTags = $config['include_tags'] ?? [];
        $excludeTags = $config['exclude_tags'] ?? [];
        $toolNaming = $config['tool_naming'] ?? 'snake';
        $nestBody = $config['nest_body'] ?? false;

        $schemas = $document->components->schemas ?? [];

        $tools = [];

        /** @var PathItem $pathItem */
        foreach ($document->paths as $uri => $pathItem) {
            foreach ($pathItem->operations as $method => $operation) {
                $httpMethod = strtoupper($method);

                if (in_array($httpMethod, $excludeMethods, true)) {
                    continue;
                }

                // Tag filtering
                $operationTags = $operation->tags ?? [];
                if ($includeTags !== [] && array_intersect($operationTags, $includeTags) === []) {
                    continue;
                }
                if ($excludeTags !== [] && array_intersect($operationTags, $excludeTags) !== []) {
                    continue;
                }

                // Tool Name
                $toolName = $this->deriveToolName($operation, $httpMethod, $uri, $toolNaming);

                // Tool Description
                $description = $this->buildDescription($operation, $httpMethod, $uri);

                // Build inputSchema
                $inputSchema = $this->buildInputSchema($operation, $schemas, $nestBody);

                // Invocation metadata
                $meta = $this->buildMeta($operation, $httpMethod, $uri);

                $tools[] = new McpTool(
                    name: $toolName,
                    description: $description,
                    inputSchema: $inputSchema,
                    meta: $meta
                );
            }
        }

        return ['tools' => $tools];
    }

    /**
     * Derive a sanitized tool name from operationId or method_path.
     */
    private function deriveToolName(Operation $operation, string $method, string $uri, string $toolNaming): string
    {
        if ($toolNaming === 'operation_id' && ! empty($operation->operationId)) {
            return $operation->operationId;
        }

        if (! empty($operation->operationId)) {
            // Convert camelCase or PascalCase to snake_case
            $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $operation->operationId) ?? $operation->operationId);

            return McpTool::sanitizeName($snake);
        }

        // Fallback: {method}_{clean_path} e.g. get_api_posts_id
        $cleanPath = trim($uri, '/');
        $cleanPath = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '$1', $cleanPath) ?? $cleanPath;
        $combined = strtolower($method).'_'.$cleanPath;

        return McpTool::sanitizeName($combined);
    }

    /**
     * Build rich description for AI model.
     */
    private function buildDescription(Operation $operation, string $method, string $uri): string
    {
        $lines = [];

        if (! empty($operation->summary)) {
            $lines[] = $operation->summary;
        }

        if (! empty($operation->description) && $operation->description !== $operation->summary) {
            $lines[] = $operation->description;
        }

        if (empty($lines)) {
            $lines[] = sprintf('%s %s', $method, $uri);
        }

        $metaParts = [sprintf('HTTP %s %s', $method, $uri)];
        if (! empty($operation->tags)) {
            $metaParts[] = 'Tags: '.implode(', ', $operation->tags);
        }
        if ($operation->deprecated) {
            $metaParts[] = 'DEPRECATED';
        }

        $lines[] = '('.implode(' | ', $metaParts).')';

        return implode("\n", $lines);
    }

    /**
     * Consolidate path, query, header parameters and JSON request body into a self-contained inputSchema.
     *
     * @param  array<string, mixed>  $schemas
     * @return array<string, mixed>
     */
    private function buildInputSchema(Operation $operation, array $schemas, bool $nestBody): array
    {
        $properties = [];
        $required = [];

        // 1. Process parameters
        foreach ($operation->parameters as $param) {
            $paramSchema = $param->schema instanceof Schema
                ? $param->schema->jsonSerialize()
                : (is_array($param->schema) ? $param->schema : ['type' => 'string']);

            $paramSchema = $this->dereference($paramSchema, $schemas);

            if (! empty($param->description) && ! isset($paramSchema['description'])) {
                $paramSchema['description'] = $param->description;
            }

            $properties[$param->name] = $paramSchema;

            if ($param->required || $param->in === 'path') {
                $required[] = $param->name;
            }
        }

        // 2. Process Request Body
        $requestBody = $operation->requestBody;
        if ($requestBody !== null && ! empty($requestBody->content)) {
            $bodyContent = $requestBody->content['application/json']['schema'] ?? null;
            if ($bodyContent !== null) {
                $rawBodySchema = $bodyContent instanceof Schema
                    ? $bodyContent->jsonSerialize()
                    : (is_array($bodyContent) ? $bodyContent : []);

                $resolvedBodySchema = $this->dereference($rawBodySchema, $schemas);

                if ($nestBody) {
                    // Nest under 'body' property
                    $properties['body'] = $resolvedBodySchema;
                    if ($requestBody->required ?? true) {
                        $required[] = 'body';
                    }
                } else {
                    // Flatten properties into top-level schema
                    $bodyProperties = $resolvedBodySchema['properties'] ?? [];
                    $bodyRequired = $resolvedBodySchema['required'] ?? [];

                    foreach ($bodyProperties as $propName => $propDef) {
                        if (isset($properties[$propName])) {
                            // Collision handling: prefix with body_
                            $properties['body_'.$propName] = $propDef;
                            if (in_array($propName, $bodyRequired, true)) {
                                $required[] = 'body_'.$propName;
                            }
                        } else {
                            $properties[$propName] = $propDef;
                            if (in_array($propName, $bodyRequired, true)) {
                                $required[] = $propName;
                            }
                        }
                    }
                }
            }
        }

        $inputSchema = [
            'type' => 'object',
            'properties' => (object) $properties,
            'additionalProperties' => false,
        ];

        if ($required !== []) {
            $inputSchema['required'] = array_values(array_unique($required));
        }

        return $inputSchema;
    }

    /**
     * Recursively dereference $ref pointers against components.schemas.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $componentsSchemas
     * @return array<string, mixed>
     */
    private function dereference(array $schema, array $componentsSchemas, int $depth = 0): array
    {
        if ($depth > 8) {
            return $schema;
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $ref = $schema['$ref'];
            $name = substr(strrchr($ref, '/') ?: $ref, 1);

            if (isset($componentsSchemas[$name])) {
                $target = $componentsSchemas[$name] instanceof Schema
                    ? $componentsSchemas[$name]->jsonSerialize()
                    : (is_array($componentsSchemas[$name]) ? $componentsSchemas[$name] : []);

                unset($schema['$ref']);
                $schema = array_merge($target, $schema);
            }
        }

        if (isset($schema['properties']) && (is_array($schema['properties']) || is_object($schema['properties']))) {
            $newProps = [];
            foreach ((array) $schema['properties'] as $k => $v) {
                if (is_array($v)) {
                    $newProps[$k] = $this->dereference($v, $componentsSchemas, $depth + 1);
                } else {
                    $newProps[$k] = $v;
                }
            }
            $schema['properties'] = $newProps;
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->dereference($schema['items'], $componentsSchemas, $depth + 1);
        }

        return $schema;
    }

    /**
     * Build invocation metadata (_meta) for tool invocation.
     *
     * @return array<string, mixed>
     */
    private function buildMeta(Operation $operation, string $method, string $uri): array
    {
        $security = [];
        if (! empty($operation->security)) {
            foreach ($operation->security as $sec) {
                foreach (array_keys($sec) as $schemeName) {
                    $security[] = $schemeName;
                }
            }
        }

        return [
            'method' => $method,
            'path' => $uri,
            'security' => array_values(array_unique($security)),
        ];
    }
}
