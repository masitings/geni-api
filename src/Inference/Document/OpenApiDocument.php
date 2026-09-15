<?php

declare(strict_types=1);

namespace Geni\Inference\Document;

use JsonSerializable;

final class OpenApiDocument implements JsonSerializable
{
    public string $openapi = '3.1.0';

    public Info $info;

    public Servers $servers;

    public Paths $paths;

    public Components $components;

    /** @var array<string, array{status: int, description: string, content: array<string, Schema>}> */
    public array $security = [];

    public function __construct()
    {
        $this->info = new Info;
        $this->servers = new Servers;
        $this->paths = new Paths;
        $this->components = new Components;
    }

    /**
     * @param  array{title: string, version: string, description?: string, termsOfService?: string, contact?: Contact, license?: License}  $info
     */
    public function setInfo(array $info): void
    {
        $this->info->title = $info['title'];
        $this->info->version = $info['version'];
        $this->info->description = $info['description'] ?? null;
        $this->info->termsOfService = $info['termsOfService'] ?? null;
        $this->info->contact = isset($info['contact']) ? new Contact($info['contact']) : null;
        $this->info->license = isset($info['license']) ? new License($info['license']) : null;
    }

    /**
     * @return array{openapi: string, info: array, paths: array, components?: array, security?: array, servers?: array}
     */
    public function jsonSerialize(): array
    {
        $result = [
            'openapi' => $this->openapi,
            'info' => $this->info->jsonSerialize(),
            'paths' => $this->paths->jsonSerialize(),
        ];

        $compSerialized = $this->components->jsonSerialize();
        if (is_array($compSerialized) && $compSerialized !== []) {
            $result['components'] = $compSerialized;
        }

        if ($this->security !== []) {
            $result['security'] = $this->security;
        }

        if ($this->servers->items !== []) {
            $result['servers'] = $this->servers->jsonSerialize();
        }

        return $result;
    }
}
