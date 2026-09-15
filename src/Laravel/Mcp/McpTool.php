<?php

declare(strict_types=1);

namespace Geni\Laravel\Mcp;

use JsonSerializable;

/**
 * Data representation of a Model Context Protocol (MCP) Tool definition.
 *
 * Conforms strictly to the official MCP specification:
 * name: ^[a-zA-Z0-9_-]{1,64}$
 * description: string
 * inputSchema: JSON Schema object
 */
final class McpTool implements JsonSerializable
{
    public string $name;

    public string $description;

    /** @var array<string, mixed> */
    public array $inputSchema;

    /** @var array<string, mixed> */
    public array $meta;

    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        string $name,
        string $description,
        array $inputSchema,
        array $meta = []
    ) {
        $this->name = self::sanitizeName($name);
        $this->description = $description;
        $this->inputSchema = $inputSchema;
        $this->meta = $meta;
    }

    /**
     * Sanitize tool name to strictly conform to ^[a-zA-Z0-9_-]{1,64}$.
     */
    public static function sanitizeName(string $name): string
    {
        // Replace slashes, braces, dots, and colons with underscores
        $clean = preg_replace('/[^\w-]/', '_', $name) ?? $name;

        // Collapse multiple underscores
        $clean = preg_replace('/_+/', '_', $clean) ?? $clean;

        // Trim leading and trailing non-alphanumeric chars
        $clean = trim($clean, '_-');

        if ($clean === '') {
            $clean = 'tool_endpoint';
        }

        // Cap at 64 characters
        return substr($clean, 0, 64);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];

        if ($this->meta !== []) {
            $data['_meta'] = $this->meta;
        }

        return $data;
    }
}
