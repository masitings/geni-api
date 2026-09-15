<?php

declare(strict_types=1);

use Geni\Inference\Document\OpenApiDocument;
use Geni\Laravel\Controllers\DocumentationController;
use Geni\Laravel\Renderers\BladeRenderer;

test('BladeRenderer renders 200 response with text/html and CDN assets', function () {
    $renderer = new BladeRenderer;
    $doc = new OpenApiDocument;
    $doc->info->title = 'Test Blade API';

    $response = $renderer->render($doc, []);

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toContain('text/html');

    $content = $response->getContent();
    expect($content)->toContain('cdn.tailwindcss.com');
    expect($content)->toContain('alpinejs');
    expect($content)->toContain('Test Blade API');
});

test('DocumentationController resolves blade as default renderer', function () {
    config(['geni.renderer' => 'blade']);

    $controller = new DocumentationController;

    expect($controller->resolveRenderer())->toBeInstanceOf(BladeRenderer::class);
});

test('DocumentationController falls back to BladeRenderer for null or default', function () {
    config(['geni.renderer' => 'default']);

    $controller = new DocumentationController;

    expect($controller->resolveRenderer())->toBeInstanceOf(BladeRenderer::class);
});
