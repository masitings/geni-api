<?php

declare(strict_types=1);

use Geni\Inference\DocumentAssembler;

/**
 * Regression: #[BodyParameter] attributes were parsed (AttributeAnnotationReader)
 * but never actually merged into the request body's per-field schema anywhere --
 * mergeNonPathParameterAttributes() explicitly skipped 'body', and
 * mergeParameterAttributes() only ever ran for path params. The attribute had
 * no effect at all on request body documentation.
 */
test('#[BodyParameter] description and example merge into the request body schema', function () {
    $dir = sys_get_temp_dir().'/geni-bodyparam-regress-'.uniqid();
    mkdir($dir, 0755, true);

    try {
        file_put_contents($dir.'/GadgetRequest.php', <<<'PHP'
<?php

namespace Geni\BodyParamRegressFixture;

class GadgetRequest
{
    public function rules(): array
    {
        return ['name' => 'required|string|max:255'];
    }
}
PHP);
        require_once $dir.'/GadgetRequest.php';

        file_put_contents($dir.'/GadgetController.php', <<<'PHP'
<?php

namespace Geni\BodyParamRegressFixture;

use Geni\Laravel\Attributes\BodyParameter;
use Illuminate\Http\Request;

class GadgetController
{
    #[BodyParameter(name: 'name', description: 'Display name of the gadget.', example: 'Widget')]
    public function store(Request $request): array
    {
        $request->validate(['name' => 'required|string|max:255']);

        return [];
    }
}
PHP);
        require_once $dir.'/GadgetController.php';

        $routes = [[
            'uri' => '/api/gadgets',
            'methods' => ['POST'],
            'action' => [
                'type' => 'controller',
                'class' => 'Geni\BodyParamRegressFixture\GadgetController',
                'method' => 'store',
            ],
            'file' => $dir.'/GadgetController.php',
            'middleware' => [],
            'name' => 'gadgets.store',
        ]];

        $document = (new DocumentAssembler)->assemble(
            routes: $routes,
            infoOptions: ['title' => 'BodyParameter Regression', 'version' => '1.0.0'],
        );

        $json = json_decode(json_encode($document), true);
        $schema = $json['paths']['/api/gadgets']['post']['requestBody']['content']['application/json']['schema'];

        expect($schema['properties']['name']['description'])->toBe('Display name of the gadget.');
        expect($schema['properties']['name']['example'])->toBe('Widget');
        // Inference from the literal validate() rules still applies underneath the attribute.
        expect($schema['properties']['name']['type'])->toBe('string');
        expect($schema['properties']['name']['maxLength'])->toBe(255);
    } finally {
        @unlink($dir.'/GadgetController.php');
        @unlink($dir.'/GadgetRequest.php');
        @rmdir($dir);
    }
});
