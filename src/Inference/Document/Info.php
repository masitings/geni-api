<?php

declare(strict_types=1);

namespace Geni\Inference\Document;

use JsonSerializable;

final class Info implements JsonSerializable
{
    public string $title = 'API Documentation';

    public string $version = '1.0.0';

    public ?string $description = null;

    public ?string $termsOfService = null;

    public ?Contact $contact = null;

    public ?License $license = null;

    public function jsonSerialize(): array
    {
        $arr = [
            'title' => $this->title,
            'version' => $this->version,
        ];

        if ($this->description !== null) {
            $arr['description'] = $this->description;
        }

        if ($this->termsOfService !== null) {
            $arr['termsOfService'] = $this->termsOfService;
        }

        if ($this->contact !== null) {
            $arr['contact'] = $this->contact->jsonSerialize();
        }

        if ($this->license !== null) {
            $arr['license'] = $this->license->jsonSerialize();
        }

        return $arr;
    }
}
