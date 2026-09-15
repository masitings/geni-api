<?php

declare(strict_types=1);

namespace Geni\Inference\Document;

use JsonSerializable;

final class Server implements JsonSerializable
{
    public string $url;

    public ?string $description = null;

    /**
     * @param  array{url: string, description?: ?string}  $data
     */
    public function __construct(array $data)
    {
        $this->url = $data['url'];
        $this->description = $data['description'] ?? null;
    }

    public function jsonSerialize(): array
    {
        $arr = ['url' => $this->url];
        if ($this->description !== null) {
            $arr['description'] = $this->description;
        }

        return $arr;
    }
}
