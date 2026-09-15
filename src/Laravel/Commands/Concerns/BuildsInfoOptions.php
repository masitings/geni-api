<?php

declare(strict_types=1);

namespace Geni\Laravel\Commands\Concerns;

/**
 * Shared infoOptions array builder for every place that assembles a document
 * (the docs UI/JSON controller and every geni:* command), so the OpenAPI
 * `info` object (title, description, version, terms of service, contact,
 * license) is populated consistently from config('geni.*') everywhere,
 * rather than each call site hardcoding its own partial version.
 */
trait BuildsInfoOptions
{
    /**
     * @return array<string, mixed>
     */
    protected function buildInfoOptions(?string $apiName = null): array
    {
        $apiConfig = ($apiName !== null && $apiName !== 'default')
            ? (config("geni.apis.{$apiName}") ?: [])
            : [];

        $title = $apiConfig['title'] ?? config('geni.title') ?: config('app.name', 'Laravel API');
        $version = $apiConfig['version'] ?? config('geni.version') ?: '1.0.0';
        $description = $apiConfig['description'] ?? config('geni.description');
        $termsOfService = $apiConfig['terms_of_service'] ?? config('geni.terms_of_service');
        $contact = ! empty($apiConfig['contact'])
            ? array_filter($apiConfig['contact'])
            : (array_filter(config('geni.contact', [])) ?: null);
        $license = ! empty($apiConfig['license']['name'])
            ? $apiConfig['license']
            : (! empty(config('geni.license.name')) ? config('geni.license') : null);

        return array_filter([
            'title' => $title,
            'version' => $version,
            'description' => $description,
            'terms_of_service' => $termsOfService,
            'contact' => $contact,
            'license' => $license,
        ], fn ($value) => $value !== null);
    }
}
