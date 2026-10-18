<?php

declare(strict_types=1);

use Geni\Laravel\DocumentGenerator;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\App\Http\Controllers\SummaryProbeController;
use Tests\TestCase;

class OperationSummaryTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        Route::get('api/summaries/attribute', [SummaryProbeController::class, 'withAttribute'])->name('summaries.attribute');
        Route::get('api/summaries/tag', [SummaryProbeController::class, 'withDescriptionTag'])->name('summaries.tag');
        Route::get('api/summaries/none', [SummaryProbeController::class, 'withoutDocblock'])->name('summaries.none');
    }

    public function test_phpdoc_summary_and_description_are_used_for_the_operation(): void
    {
        $operation = $this->operation('/api/posts', 'get');

        expect($operation['summary'])->toBe('GET /api/posts - list all posts');
    }

    public function test_endpoint_attribute_overrides_phpdoc_text(): void
    {
        $operation = $this->operation('/api/summaries/attribute', 'get');

        expect($operation['summary'])->toBe('Attribute title')
            ->and($operation['description'])->toBe('Attribute description');
    }

    public function test_description_tag_overrides_phpdoc_paragraphs(): void
    {
        $operation = $this->operation('/api/summaries/tag', 'get');

        expect($operation['summary'])->toBe('Docblock summary')
            ->and($operation['description'])->toBe('From the tag');
    }

    public function test_route_name_is_still_the_fallback_without_a_docblock(): void
    {
        expect($this->operation('/api/summaries/none', 'get')['summary'])->toBe('summaries.none');
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(string $path, string $method): array
    {
        $document = json_decode((string) json_encode(app(DocumentGenerator::class)->generate()), true);

        return $document['paths'][$path][$method];
    }
}
