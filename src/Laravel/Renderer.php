<?php

declare(strict_types=1);

namespace Geni\Laravel;

use Geni\Inference\Document\OpenApiDocument;
use Illuminate\Http\Response;

/**
 * Renderer contract for outputting OpenAPI documents.
 */
interface Renderer
{
    /**
     * Render the OpenAPI document to an HTTP response.
     *
     * @param  array<string, mixed>  $config  Renderer-specific configuration
     */
    public function render(OpenApiDocument $document, array $config): Response;
}
