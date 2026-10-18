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

    /** @var list<Schema>|null */
    public ?array $anyOf = null;

    /** @var array<string, mixed> */
    public array $extensions = [];

    /**
     * Build a schema that only references a component.
     */
    public static function ref(string $componentName): self
    {
        $schema = new self;
        $schema->ref = '#/components/schemas/'.$componentName;

        return $schema;
    }

    /**
     * Build a schema with a single type (and optional format).
     *
     * @param  string|list<string>  $type
     */
    public static function ofType($type, ?string $format = null): self
    {
        $schema = new self;
        $schema->type = $type;
        $schema->format = $format;

        return $schema;
    }

    /**
     * Return a copy that also accepts null: `["string", "null"]` for typed
     * schemas, `anyOf: [..., {type: null}]` for references and unions.
     */
    public function nullable(): self
    {
        $copy = clone $this;

        if ($copy->ref !== null || $copy->anyOf !== null) {
            if ($copy->anyOf !== null) {
                foreach ($copy->anyOf as $option) {
                    if ($option->ref === null && $option->type === 'null') {
                        return $copy;
                    }
                }
            }

            $union = new self;
            $union->description = $copy->description;
            $copy->description = null;
            $union->anyOf = array_merge($copy->anyOf ?? [$copy], [self::ofType('null')]);

            return $union;
        }

        if ($copy->type === null) {
            return $copy;
        }

        $types = is_array($copy->type) ? $copy->type : [$copy->type];
        if (! in_array('null', $types, true)) {
            $types[] = 'null';
        }
        $copy->type = $types;

        return $copy;
    }

    /**
     * Whether the schema accepts null.
     */
    public function isNullable(): bool
    {
        if (is_array($this->type) && in_array('null', $this->type, true)) {
            return true;
        }

        foreach ($this->anyOf ?? [] as $option) {
            if ($option->ref === null && $option->type === 'null') {
                return true;
            }
        }

        return $this->type === 'null';
    }

    public function __clone()
    {
        if ($this->items !== null) {
            $this->items = clone $this->items;
        }

        if (is_array($this->properties)) {
            foreach ($this->properties as $name => $property) {
                if ($property instanceof self) {
                    $this->properties[$name] = clone $property;
                }
            }
        }

        if ($this->anyOf !== null) {
            $this->anyOf = array_map(fn (self $option) => clone $option, $this->anyOf);
        }
    }

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

        if ($this->anyOf !== null) {
            if ($this->description !== null) {
                $result['description'] = $this->description;
            }
            $result['anyOf'] = array_map(fn (self $option) => $option->jsonSerialize(), $this->anyOf);

            return array_merge($result, $this->extensions);
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
