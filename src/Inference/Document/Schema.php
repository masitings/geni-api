<?php

declare(strict_types=1);

namespace Geni\Inference\Document;

use JsonSerializable;

/**
 * Generic JSON Schema value object, enough for this phase's inference.
 * Keep properties that map directly from validation rules and resource types.
 * Extensions are passed through untouched.
 */
final class Schema implements JsonSerializable
{
    public ?string $title = null;

    public ?string $description = null;

    public ?string $ref = null;

    /** @var string|list<string>|null */
    public $type = null;

    public ?string $format = null;

    public ?Schema $items = null;

    /** @var string|int|float|bool|null */
    public $default = null;

    public ?int $minLength = null;

    public ?int $maxLength = null;

    public ?int $minimum = null;

    public ?int $maximum = null;

    public ?string $pattern = null;

    public bool $exclusiveMinimum = false;

    public bool $exclusiveMaximum = false;

    /** @var list<mixed> */
    public array $enum = [];

    /** @var array<string, Schema|array<string, mixed>>|null */
    public $properties = null;

    /** @var list<string>|null */
    public $required = null;

    /** @var array<string, mixed> */
    public array $extensions = [];

    public function jsonSerialize(): array
    {
        $result = [];

        if ($this->ref !== null) {
            $result['$ref'] = $this->ref;

            if ($this->description !== null) {
                $result['description'] = $this->description;
            }

            return $result;
        }

        if ($this->title !== null) {
            $result['title'] = $this->title;
        }

        if ($this->description !== null) {
            $result['description'] = $this->description;
        }

        if ($this->type !== null) {
            $result['type'] = $this->type;
        }

        if ($this->format !== null) {
            $result['format'] = $this->format;
        }

        if ($this->items !== null) {
            $result['items'] = $this->items->jsonSerialize();
        }

        if ($this->default !== null) {
            $result['default'] = $this->default;
        }

        if ($this->minLength !== null) {
            $result['minLength'] = $this->minLength;
        }

        if ($this->maxLength !== null) {
            $result['maxLength'] = $this->maxLength;
        }

        if ($this->minimum !== null) {
            $result['minimum'] = $this->minimum;
        }

        if ($this->maximum !== null) {
            $result['maximum'] = $this->maximum;
        }

        if ($this->pattern !== null) {
            $result['pattern'] = $this->pattern;
        }

        if ($this->exclusiveMinimum) {
            $result['exclusiveMinimum'] = true;
        }

        if ($this->exclusiveMaximum) {
            $result['exclusiveMaximum'] = true;
        }

        if ($this->enum !== []) {
            $result['enum'] = $this->enum;
        }

        if ($this->properties !== null) {
            $result['properties'] = [];
            foreach ($this->properties as $name => $prop) {
                $result['properties'][$name] = $prop instanceof JsonSerializable
                    ? $prop->jsonSerialize()
                    : $prop;
            }
        }

        if ($this->required !== null && $this->required !== []) {
            $result['required'] = $this->required;
        }

        if ($this->extensions !== []) {
            $result = array_merge($result, $this->extensions);
        }

        return $result;
    }
}
