<?php

declare(strict_types=1);

namespace Geni\Laravel\Renderers;

use Geni\Inference\Document\OpenApiDocument;
use Geni\Laravel\Renderer;
use Geni\Laravel\Renderers\Concerns\ResolvesSpecUrl;
use Illuminate\Http\Response;

/**
 * Stoplight Elements, loaded from a pinned CDN build.
 */
final class ElementsRenderer implements Renderer
{
    use ResolvesSpecUrl;

    public const DEFAULT_SCRIPT_URL = 'https://cdn.jsdelivr.net/npm/@stoplight/elements@9.0.25/web-components.min.js';

    public const DEFAULT_STYLESHEET_URL = 'https://cdn.jsdelivr.net/npm/@stoplight/elements@9.0.25/styles.min.css';

    public function render(OpenApiDocument $document, array $config): Response
    {
        $spec = $this->specUrl($config);

        $html = view('geni::elements', [
            'title' => $document->info->title,
            'specUrl' => $spec['url'],
            'scriptUrl' => $config['script_url'] ?? self::DEFAULT_SCRIPT_URL,
            'stylesheetUrl' => $config['stylesheet_url'] ?? self::DEFAULT_STYLESHEET_URL,
            'layout' => $config['layout'] ?? 'sidebar',
            'hideTryIt' => (bool) ($config['hide_try_it'] ?? false),
            'theme' => $config['theme'] ?? 'light',
        ])->render();

        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }
}
