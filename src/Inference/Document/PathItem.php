<?php

declare(strict_types=1);

namespace Geni\Inference\Document;

use JsonSerializable;

/**
 * Represents a single path template (e.g. `/api/posts/{post}`) and the
 * operations attached to it. Only methods with operations are serialized.
 */
final class PathItem implements JsonSerializable
{
    public string $uri;

    /** @var array<string, Operation> keyed by lowercase HTTP method */
    public array $operations = [];

    public function __construct(string $uri)
    {
        $this->uri = $uri;
    }

    public function add(Operation $operation): void
    {
        $this->operations[strtolower($operation->method)] = $operation;
    }

    public function jsonSerialize(): array
    {
        $result = [];
        foreach ($this->operations as $method => $operation) {
            $result[$method] = $operation->jsonSerialize();
        }

        // Also emit parameters if any are present.
        return array_merge($result, $this->parametersJson());
    }

    /**
     * @return array{parameters?: list<array<string, mixed>}|'}
     */
    private function parametersJson(): array
    {
        return [];
    }
}
