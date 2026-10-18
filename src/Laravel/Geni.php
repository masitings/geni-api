<?php

declare(strict_types=1);

namespace Geni\Laravel;

/**
 * Entry point for customizing generated documents, usually from a service
 * provider's `boot()` method:
 *
 *     Geni::configure()
 *         ->withDocumentTransformers(fn (OpenApiDocument $document) => $document->info->description = '...')
 *         ->withOperationTransformers(AddRateLimitHeaders::class);
 *
 * Registrations for `default` also apply to every API in `geni.apis`.
 */
final class Geni
{
    public static function configure(string $apiName = 'default'): GeniConfiguration
    {
        return app(GeniConfigurationRegistry::class)->for($apiName);
    }
}
