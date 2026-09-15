<?php

declare(strict_types=1);

namespace Geni\Inference\Document;

use JsonSerializable;

final class Servers implements JsonSerializable
{
    public array $items = [];

    public function add(Server $server): void
    {
        $this->items[] = $server;
    }

    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
