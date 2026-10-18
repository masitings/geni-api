<?php

declare(strict_types=1);

namespace Tests\Fixtures\App\Http\Controllers;

use Geni\Laravel\Attributes\Endpoint;

class SummaryProbeController
{
    /**
     * Docblock summary.
     *
     * Docblock description.
     */
    #[Endpoint(title: 'Attribute title', description: 'Attribute description')]
    public function withAttribute(): array
    {
        return [];
    }

    /**
     * Docblock summary.
     *
     * Docblock description.
     *
     * @description From the tag
     */
    public function withDescriptionTag(): array
    {
        return [];
    }

    public function withoutDocblock(): array
    {
        return [];
    }
}
