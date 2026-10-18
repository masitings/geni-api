<?php

declare(strict_types=1);

namespace Geni\Laravel\Renderers;

use Geni\Inference\Document\OpenApiDocument;
use Geni\Laravel\Renderer;
use Geni\Laravel\Renderers\Concerns\ResolvesSpecUrl;
use Illuminate\Http\Response;

/**
 * Scalar API Reference (https://github.com/scalar/scalar), loaded from a pinned CDN build.
 * Options in `geni.renderers.scalar.configuration` are passed to Scalar as-is.
 */
final class ScalarRenderer implements Renderer
{
    use ResolvesSpecUrl;

    public const DEFAULT_SCRIPT_URL = 'https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.72.3/dist/browser/standalone.js';

    public function render(OpenApiDocument $document, array $config): Response
    {
        $spec = $this->specUrl($config);

        $html = view('geni::scalar', [
            'title' => $document->info->title,
            'scriptUrl' => $config['script_url'] ?? self::DEFAULT_SCRIPT_URL,
            'configuration' => array_merge(
                ['url' => $spec['url'], 'theme' => 'laravel'],
                (array) ($config['configuration'] ?? []),
            ),
        ])->render();

        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }
}
