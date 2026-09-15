<?php

declare(strict_types=1);

use Geni\Inference\DocumentAssembler;

/**
 * #[BodyParameter] attributes declared on a Form Request's own rules()
 * method (rather than on the controller action) must apply the same way --
 * this keeps per-field documentation next to the validation rules it
 * describes instead of scattered across the controller.
 */
test('#[BodyParameter] on a Form Request rules() method merges into the request body schema', function () {
    $dir = sys_get_temp_dir().'/geni-formrequest-attr-'.uniqid();
    mkdir($dir, 0755, true);

    try {
        file_put_contents($dir.'/StoreWidgetRequest.php', <<<'PHP'
<?php

namespace Geni\FormRequestAttrFixture;

use Geni\Laravel\Attributes\BodyParameter;

class StoreWidgetRequest
{
    #[BodyParameter(name: 'name', description: 'Display name of the widget.', example: 'Sprocket')]
    public function rules(): array
    {
        return ['name' => 'required|string|max:255'];
    }
}
PHP);
        require_once $dir.'/StoreWidgetRequest.php';

        file_put_contents($dir.'/WidgetController.php', <<<'PHP'
<?php

namespace Geni\FormRequestAttrFixture;

class WidgetController
{
    public function store(StoreWidgetRequest $request): array
    {
        return [];
    }
}
PHP);
        require_once $dir.'/WidgetController.php';

        $routes = [[
            'uri' => '/api/widgets',
            'methods' => ['POST'],
            'action' => [
                'type' => 'controller',
                'class' => 'Geni\FormRequestAttrFixture\WidgetController',
                'method' => 'store',
            ],
            'file' => $dir.'/WidgetController.php',
            'middleware' => [],
            'name' => 'widgets.store',
        ]];

        $document = (new DocumentAssembler)->assemble(
            routes: $routes,
            infoOptions: ['title' => 'Form Request Attribute Test', 'version' => '1.0.0'],
        );

        $json = json_decode(json_encode($document), true);
        $schema = $json['paths']['/api/widgets']['post']['requestBody']['content']['application/json']['schema'];

        expect($schema['properties']['name']['description'])->toBe('Display name of the widget.');
        expect($schema['properties']['name']['example'])->toBe('Sprocket');
        expect($schema['properties']['name']['type'])->toBe('string');
    } finally {
        @unlink($dir.'/WidgetController.php');
        @unlink($dir.'/StoreWidgetRequest.php');
        @rmdir($dir);
    }
});
