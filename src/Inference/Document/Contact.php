<?php

declare(strict_types=1);

namespace Geni\Inference\Document;

use JsonSerializable;

final class Contact implements JsonSerializable
{
    public ?string $name = null;

    public ?string $url = null;

    public ?string $email = null;

    /**
     * @param  array{name?: ?string, url?: ?string, email?: ?string}  $data
     */
    public function __construct(array $data = [])
    {
        $this->name = $data['name'] ?? null;
        $this->url = $data['url'] ?? null;
        $this->email = $data['email'] ?? null;
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'name' => $this->name,
            'url' => $this->url,
            'email' => $this->email,
        ], fn ($val) => $val !== null);
    }
}
