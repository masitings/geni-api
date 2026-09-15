<?php

declare(strict_types=1);

namespace Geni\Inference\Document;

use JsonSerializable;

final class Parameter implements JsonSerializable
{
    public string $name;

    public string $in; // 'path', 'query', 'header', 'cookie'

    public bool $required;

    public ?string $description = null;

    /** @var Schema|array<string, mixed> */
    public $schema;

    /** @var array<string, mixed> */
    public array $extensions = [];

    /**
     * @param  string  $in  'path' | 'query' | 'header' | 'cookie'
     * @param  Schema|array<string, mixed>  $schema
     */
    public function __construct(string $name, string $in, bool $required, $schema)
    {
        $this->name = $name;
        $this->in = $in;
        $this->required = $required;
        $this->schema = $schema;
    }

    public function jsonSerialize(): array
    {
        $res = [
            'name' => $this->name,
            'in' => $this->in,
            'required' => $this->required,
            'schema' => $this->schema instanceof JsonSerializable ? $this->schema->jsonSerialize() : $this->schema,
        ];

        if ($this->description !== null) {
            $res['description'] = $this->description;
        }

        if ($this->extensions !== []) {
            $res['x-geni-unresolved'] = $this->extensions;
        }

        return $res;
    }
}
