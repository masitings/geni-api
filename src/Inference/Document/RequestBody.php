<?php

declare(strict_types=1);

namespace Geni\Inference\Document;

use JsonSerializable;

final class RequestBody implements JsonSerializable
{
    public ?string $description = null;

    public bool $required = true;

    /** @var array<string, array{schema: Schema|array<string, mixed>}> */
    public array $content = [];

    /**
     * @param  Schema|array<string, mixed>  $schema
     */
    public static function json($schema, ?string $description = null, bool $required = true): self
    {
        $body = new self;
        $body->description = $description;
        $body->required = $required;
        $body->content['application/json'] = [
            'schema' => $schema instanceof JsonSerializable ? $schema->jsonSerialize() : $schema,
        ];

        return $body;
    }

    public function jsonSerialize(): array
    {
        $res = [
            'required' => $this->required,
            'content' => $this->content,
        ];

        if ($this->description !== null) {
            $res['description'] = $this->description;
        }

        return $res;
    }
}
