<?php

declare(strict_types=1);

use Geni\Inference\Document\OpenApiDocument;
use Geni\Inference\Document\Operation;
use Geni\Laravel\DiscoveredRoute;
use Geni\Laravel\DocumentGenerator;
use Geni\Laravel\Geni;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Extensions\BillingController;
use Tests\Fixtures\Extensions\Ipv4CidrRule;
use Tests\Fixtures\Extensions\NotAnExtension;
use Tests\Fixtures\Extensions\PaymentRequiredResponse;
use Tests\Fixtures\Extensions\SetTermsOfService;
use Tests\Fixtures\Extensions\TagOperationsWithRouteName;
use Tests\TestCase;

class ExtensionsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('geni.apis', [
            'v2' => ['title' => 'API v2', 'api_path' => 'api/billing'],
        ]);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        Route::post('api/billing/charge', [BillingController::class, 'charge'])->name('billing.charge');
    }

    public function test_document_and_operation_closures_run_after_generation(): void
    {
        Geni::configure()
            ->withDocumentTransformers(function (OpenApiDocument $document, string $apiName) {
                $document->info->description = 'Changed for '.$apiName;
            })
            ->withOperationTransformers(function (Operation $operation, DiscoveredRoute $route) {
                $operation->extensions['x-uri'] = $route->uri;
            });

        $document = $this->generate();

        expect($document['info']['description'])->toBe('Changed for default')
            ->and($document['paths']['/api/posts']['get']['x-uri'])->toBe('api/posts')
            ->and($document['paths']['/api/posts']['post']['x-uri'])->toBe('api/posts');
    }

    public function test_after_open_api_generated_is_an_alias(): void
    {
        Geni::configure()->afterOpenApiGenerated(fn (OpenApiDocument $document) => $document->info->title = 'Renamed');

        expect($this->generate()['info']['title'])->toBe('Renamed');
    }

    public function test_default_registrations_also_apply_to_named_apis(): void
    {
        Geni::configure()->withDocumentTransformers(fn (OpenApiDocument $document) => $document->info->description = 'shared');
        Geni::configure('v2')->withDocumentTransformers(fn (OpenApiDocument $document) => $document->info->title .= ' (billing)');

        $v2 = $this->generate('v2');
        $default = $this->generate();

        expect($v2['info']['description'])->toBe('shared')
            ->and($v2['info']['title'])->toBe('API v2 (billing)')
            ->and($default['info']['title'])->toBe('Laravel API');
    }

    public function test_config_extensions_are_registered_by_interface(): void
    {
        config(['geni.extensions' => [TagOperationsWithRouteName::class, SetTermsOfService::class, Ipv4CidrRule::class, PaymentRequiredResponse::class]]);

        $operation = $this->generate()['paths']['/api/billing/charge']['post'];

        expect($operation['x-route-name'])->toBe('billing.charge')
            ->and($this->generate()['info']['termsOfService'])->toBe('https://example.com/terms')
            ->and($operation['requestBody']['content']['application/json']['schema']['properties']['network']['format'])->toBe('ipv4-cidr')
            ->and($operation['responses'])->toHaveKey('402')
            ->and($operation)->not->toHaveKey('x-geni-unresolved');
    }

    public function test_rule_transformers_and_exception_mappers_through_configure(): void
    {
        Geni::configure()
            ->withRuleTransformers([new Ipv4CidrRule])
            ->withExceptionResponses(PaymentRequiredResponse::class);

        $operation = $this->generate()['paths']['/api/billing/charge']['post'];

        expect($operation['requestBody']['content']['application/json']['schema']['properties']['network']['format'])->toBe('ipv4-cidr')
            ->and($operation['responses'])->toHaveKey('402');
    }

    public function test_invalid_extensions_are_rejected_with_a_clear_message(): void
    {
        config(['geni.extensions' => [NotAnExtension::class]]);

        expect(fn () => $this->generate())->toThrow(InvalidArgumentException::class, 'must implement');
    }

    public function test_servers_from_config(): void
    {
        config(['geni.servers' => ['Production' => 'https://api.example.com', 'Local' => '/']]);

        expect($this->generate()['servers'])->toBe([
            ['url' => 'https://api.example.com', 'description' => 'Production'],
            ['url' => 'http://localhost', 'description' => 'Local'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(string $apiName = 'default'): array
    {
        return json_decode((string) json_encode(app(DocumentGenerator::class)->generate($apiName)), true);
    }
}
