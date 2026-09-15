<?php

declare(strict_types=1);

namespace Geni\Inference\Document;

use JsonSerializable;

final class Components implements JsonSerializable
{
    /** @var array<string, Schema|array<string, mixed>> */
    public array $schemas = [];

    /** @var array<string, array<string, mixed>> */
    public array $securitySchemes = [];

    /**
     * @param  Schema|array<string, mixed>  $schema
     */
    public function addSchema(string $name, $schema): void
    {
        $this->schemas[$name] = $schema instanceof JsonSerializable ? $schema->jsonSerialize() : $schema;
    }

    /**
     * @param  array<string, mixed>  $scheme
     */
    public function addSecurityScheme(string $name, array $scheme): void
    {
        $this->securitySchemes[$name] = $scheme;
    }

    public function jsonSerialize(): mixed
    {
        ksort($this->schemas);
        ksort($this->securitySchemes);

        $res = [];
        if ($this->schemas !== []) {
            $res['schemas'] = $this->schemas;
        }
        if ($this->securitySchemes !== []) {
            $res['securitySchemes'] = $this->securitySchemes;
        }

        if ($res === []) {
            return (object) [];
        }

        return $res;
    }
}
