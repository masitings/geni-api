<?php

declare(strict_types=1);

namespace Geni\Inference\Document;

use JsonSerializable;

/**
 * @implements \IteratorAggregate<string, PathItem>
 */
final class Paths implements \IteratorAggregate, JsonSerializable
{
    /** @var array<string, PathItem> keyed by URI template */
    public array $items = [];

    public function add(PathItem $pathItem): void
    {
        $this->items[$pathItem->uri] = $pathItem;
    }

    /**
     * @return \ArrayIterator<string, PathItem>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function jsonSerialize(): mixed
    {
        $result = [];
        foreach ($this->items as $uri => $pathItem) {
            $result[$uri] = $pathItem->jsonSerialize();
        }

        if ($result === []) {
            return (object) [];
        }

        return $result;
    }
}
