<?php

declare(strict_types=1);

namespace Geni\Laravel\Contracts;

use Geni\Inference\Document\OpenApiDocument;

/**
 * Changes the finished OpenAPI document of an API (info, servers, security,
 * components, paths) before it is cached, served or exported.
 */
interface DocumentTransformer
{
    public function handle(OpenApiDocument $document, string $apiName): void;
}
