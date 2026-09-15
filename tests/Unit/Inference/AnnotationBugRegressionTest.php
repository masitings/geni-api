<?php

declare(strict_types=1);

use Geni\Inference\DocumentAssembler;

/**
 * Regression tests for two bugs found while dogfooding real annotations:
 *
 * 1. Class-level @tags (and other class docblock tags) were silently
 *    dropped, DocumentAssembler hardcoded $docClass = [] instead of
 *    parsing the controller's own doc comment.
 * 2. @response with a short class name (e.g. "TeamResource" via a `use`
 *    import) failed to resolve to a $ref, because resolution only tried
 *    class_exists() on the raw string rather than checking the file's
 *    use-import map first, matching Scramble's behavior.
 */
test('class-level @tags PHPDoc is read and applied to operations', function () {
    $dir = sys_get_temp_dir().'/geni-annotation-regress-'.uniqid();
    mkdir($dir, 0755, true);

    try {
        file_put_contents($dir.'/WidgetController.php', <<<'PHP'
<?php

namespace Geni\AnnotationRegressFixture;

/**
 * @tags Widgets
 */
class WidgetController
{
    /**
     * List widgets.
     */
    public function index(): array
    {
        return [];
    }
}
PHP);
        require_once $dir.'/WidgetController.php';

        $routes = [[
            'uri' => '/api/widgets',
            'methods' => ['GET'],
            'action' => [
                'type' => 'controller',
                'class' => 'Geni\AnnotationRegressFixture\WidgetController',
                'method' => 'index',
            ],
            'file' => $dir.'/WidgetController.php',
            'middleware' => [],
            'name' => 'widgets.index',
        ]];

        $document = (new DocumentAssembler)->assemble(
            routes: $routes,
            infoOptions: ['title' => 'Tags Regression', 'version' => '1.0.0'],
        );

        $json = json_decode(json_encode($document), true);

        expect($json['paths']['/api/widgets']['get']['tags'])->toBe(['Widgets']);
    } finally {
        @unlink($dir.'/WidgetController.php');
        @rmdir($dir);
    }
});

test('@response with a short class name resolves via the file\'s use imports', function () {
    $dir = sys_get_temp_dir().'/geni-annotation-regress-'.uniqid();
    mkdir($dir, 0755, true);

    try {
        file_put_contents($dir.'/GadgetResource.php', <<<'PHP'
<?php

namespace Geni\AnnotationRegressFixture;

class GadgetResource
{
}
PHP);
        require_once $dir.'/GadgetResource.php';

        file_put_contents($dir.'/GadgetController.php', <<<'PHP'
<?php

namespace Geni\AnnotationRegressFixture;

use Geni\AnnotationRegressFixture\GadgetResource;

class GadgetController
{
    /**
     * @response GadgetResource
     */
    public function show(): GadgetResource
    {
        return new GadgetResource();
    }
}
PHP);
        require_once $dir.'/GadgetController.php';

        $routes = [[
            'uri' => '/api/gadgets/{gadget}',
            'methods' => ['GET'],
            'action' => [
                'type' => 'controller',
                'class' => 'Geni\AnnotationRegressFixture\GadgetController',
                'method' => 'show',
            ],
            'file' => $dir.'/GadgetController.php',
            'middleware' => [],
            'name' => 'gadgets.show',
        ]];

        $document = (new DocumentAssembler)->assemble(
            routes: $routes,
            infoOptions: ['title' => 'Response Ref Regression', 'version' => '1.0.0'],
        );

        $json = json_decode(json_encode($document), true);
        $schema = $json['paths']['/api/gadgets/{gadget}']['get']['responses']['200']['content']['application/json']['schema'];

        expect($schema)->toBe(['$ref' => '#/components/schemas/GadgetResource']);
    } finally {
        @unlink($dir.'/GadgetController.php');
        @unlink($dir.'/GadgetResource.php');
        @rmdir($dir);
    }
});
