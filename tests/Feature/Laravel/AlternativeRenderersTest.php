<?php

declare(strict_types=1);

use Geni\Laravel\Renderers\ElementsRenderer;
use Geni\Laravel\Renderers\ScalarRenderer;
use Tests\TestCase;

class AlternativeRenderersTest extends TestCase
{
    public function test_scalar_renderer_loads_the_pinned_build_with_the_spec_url(): void
    {
        config(['geni.renderer' => 'scalar', 'geni.renderers.scalar.configuration' => ['theme' => 'purple', 'hideModels' => true]]);

        $html = $this->get('/docs/api')->assertOk()->getContent();

        expect($html)->toContain(ScalarRenderer::DEFAULT_SCRIPT_URL)
            ->toContain('Scalar.createApiReference')
            ->toContain('http:\\\\\\/\\\\\\/localhost\\\\\\/docs\\\\\\/api.json')
            ->toContain('\\u0022theme\\u0022:\\u0022purple\\u0022')
            ->toContain('\\u0022hideModels\\u0022:true');
    }

    public function test_elements_renderer_loads_the_pinned_build(): void
    {
        config(['geni.renderer' => 'elements', 'geni.renderers.elements.layout' => 'stacked', 'geni.renderers.elements.hide_try_it' => true]);

        $html = $this->get('/docs/api')->assertOk()->getContent();

        expect($html)->toContain(ElementsRenderer::DEFAULT_SCRIPT_URL)
            ->toContain(ElementsRenderer::DEFAULT_STYLESHEET_URL)
            ->toContain('<elements-api')
            ->toContain('layout="stacked"')
            ->toContain('hideTryIt="true"');
    }

    public function test_each_api_can_pick_its_own_renderer(): void
    {
        config(['geni.apis' => ['v2' => ['api_path' => 'api', 'renderer' => 'scalar']]]);

        expect($this->get('/docs/api?api=v2')->assertOk()->getContent())->toContain('Scalar.createApiReference')
            ->and($this->get('/docs/api')->assertOk()->getContent())->toContain('geniDocs');
    }
}
