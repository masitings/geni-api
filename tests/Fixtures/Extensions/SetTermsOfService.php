<?php

declare(strict_types=1);

namespace Tests\Fixtures\Extensions;

use Geni\Inference\Document\OpenApiDocument;
use Geni\Laravel\Contracts\DocumentTransformer;

class SetTermsOfService implements DocumentTransformer
{
    public function handle(OpenApiDocument $document, string $apiName): void
    {
        $document->info->termsOfService = 'https://example.com/terms';
    }
}
