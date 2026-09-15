<?php

declare(strict_types=1);

namespace Geni\Inference\Document;

use JsonSerializable;

final class License implements JsonSerializable
{
    public string $name;

    public ?string $url = null;

    /**
     * @param  array{name: string, url?: ?string}  $data
     */
    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->url = $data['url'] ?? null;
    }

    public function jsonSerialize(): array
    {
        $arr = ['name' => $this->name];
        if ($this->url !== null) {
            $arr['url'] = $this->url;
        }

        return $arr;
    }
}
