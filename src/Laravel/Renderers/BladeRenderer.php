<?php

declare(strict_types=1);

namespace Geni\Laravel\Renderers;

use Composer\InstalledVersions;
use Geni\Inference\Document\OpenApiDocument;
use Geni\Laravel\Controllers\DocumentationController;
use Geni\Laravel\Renderer;
use Illuminate\Http\Response;

/**
 * Built-in standalone Blade + Alpine.js + Tailwind docs UI renderer.
 *
 * Runs without React, Vite, or any client-side build step.
 */
final class BladeRenderer implements Renderer
{
    public function render(OpenApiDocument $document, array $config): Response
    {
        $currentApi = $config['current_api'] ?? request()->route('api') ?? request()->query('api', 'default');
        $availableApis = $config['available_apis'] ?? app(DocumentationController::class)->getAvailableApis((string) $currentApi);

        // Determine active JSON URL for current API version
        $jsonUrl = url(config('geni.docs_json_path', 'docs/api.json'));
        foreach ($availableApis as $apiItem) {
            if ($apiItem['key'] === (string) $currentApi) {
                $jsonUrl = $apiItem['json_url'];
                break;
            }
        }

        $packageVersion = InstalledVersions::isInstalled('masitings/geni-api')
            ? InstalledVersions::getPrettyVersion('masitings/geni-api')
            : null;

        $html = view('geni::blade-docs', [
            'title' => $document->info->title,
            'version' => $document->info->version,
            'document' => $document,
            'url' => $jsonUrl,
            'configuration' => $config,
            'packageVersion' => $packageVersion,
            'currentApi' => (string) $currentApi,
            'availableApis' => $availableApis,
        ])->render();

        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }
}
