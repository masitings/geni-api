<?php

declare(strict_types=1);

use Geni\Inference\ActionAst;
use Geni\Inference\Document\Components;
use Geni\Inference\ErrorResponseInferer;
use Geni\Inference\ResourceResponseInferer;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

test('infers response from response()->json literal array', function () {
    $code = '<?php
    namespace App\Http\Controllers;
    class PingController {
        public function ping() {
            return response()->json([
                "status" => "ok",
                "code" => 200,
                "alive" => true,
            ]);
        }
    }';

    $parser = (new ParserFactory)->createForHostVersion();
    $stmts = $parser->parse($code);
    $class = (new NodeFinder)->findFirstInstanceOf($stmts, Class_::class);
    $method = (new NodeFinder)->findFirstInstanceOf($class->stmts, ClassMethod::class);

    $ast = new ActionAst($method, 'PingController.php', 4, [], 'App\Http\Controllers');

    $inferer = new ResourceResponseInferer;
    $result = $inferer->inferFromActionAst($ast);

    expect($result['schema']->type)->toBe('object');
    $props = $result['schema']->properties;
    expect($props['status']->type)->toBe('string');
    expect($props['code']->type)->toBe('integer');
    expect($props['alive']->type)->toBe('boolean');
});

test('infers response for plain PHP DTO object', function () {
    $code = '<?php
    namespace App\Http\Controllers;
    use Tests\Fixtures\Inference\SimpleDto;
    class DtoController {
        public function getDto(): SimpleDto {
            return new SimpleDto();
        }
    }';

    $parser = (new ParserFactory)->createForHostVersion();
    $stmts = $parser->parse($code);
    $class = (new NodeFinder)->findFirstInstanceOf($stmts, Class_::class);
    $method = (new NodeFinder)->findFirstInstanceOf($class->stmts, ClassMethod::class);

    $ast = new ActionAst($method, 'DtoController.php', 4, ['SimpleDto' => 'Tests\Fixtures\Inference\SimpleDto'], 'App\Http\Controllers');

    $inferer = new ResourceResponseInferer;
    $components = new Components;
    $result = $inferer->inferFromActionAst($ast, null, [], $components);

    expect($result['schema']->ref)->toBe('#/components/schemas/SimpleDto');
    expect($components->schemas)->toHaveKey('SimpleDto');

    $dtoSchema = $components->schemas['SimpleDto'];
    $props = is_array($dtoSchema) ? $dtoSchema['properties'] : $dtoSchema->properties;
    expect($props)->toHaveKey('name');
    expect($props['name']['type'] ?? $props['name']->type)->toBe('string');
    expect($props['age']['type'] ?? $props['age']->type)->toBe('integer');
    expect($props['is_active']['type'] ?? $props['is_active']->type)->toBe('boolean');
});

test('infers response for backed enum return', function () {
    $code = '<?php
    namespace App\Http\Controllers;
    use Tests\Fixtures\Inference\PostStatus;
    class StatusController {
        public function getStatus() {
            return PostStatus::Published;
        }
    }';

    $parser = (new ParserFactory)->createForHostVersion();
    $stmts = $parser->parse($code);
    $class = (new NodeFinder)->findFirstInstanceOf($stmts, Class_::class);
    $method = (new NodeFinder)->findFirstInstanceOf($class->stmts, ClassMethod::class);

    $ast = new ActionAst($method, 'StatusController.php', 4, ['PostStatus' => 'Tests\Fixtures\Inference\PostStatus'], 'App\Http\Controllers');

    $inferer = new ResourceResponseInferer;
    $components = new Components;
    $result = $inferer->inferFromActionAst($ast, null, [], $components);

    expect($result['schema']->ref)->toBe('#/components/schemas/PostStatus');
    expect($components->schemas)->toHaveKey('PostStatus');
    $enumSchema = $components->schemas['PostStatus'];
    $enumCases = is_array($enumSchema) ? $enumSchema['enum'] : $enumSchema->enum;
    expect($enumCases)->toBe(['draft', 'published', 'archived']);
});

test('infers resource collection with pagination envelope', function () {
    $code = '<?php
    namespace App\Http\Controllers;
    use Tests\Fixtures\App\Http\Resources\PostResource;
    use Tests\Fixtures\App\Models\Post;
    class PostListController {
        public function index() {
            return PostResource::collection(Post::paginate());
        }
    }';

    $parser = (new ParserFactory)->createForHostVersion();
    $stmts = $parser->parse($code);
    $class = (new NodeFinder)->findFirstInstanceOf($stmts, Class_::class);
    $method = (new NodeFinder)->findFirstInstanceOf($class->stmts, ClassMethod::class);

    $ast = new ActionAst($method, 'PostListController.php', 4, [
        'PostResource' => 'Tests\Fixtures\App\Http\Resources\PostResource',
        'Post' => 'Tests\Fixtures\App\Models\Post',
    ], 'App\Http\Controllers');

    $inferer = new ResourceResponseInferer;
    $components = new Components;
    $result = $inferer->inferFromActionAst($ast, null, [], $components);

    expect($result['schema']->type)->toBe('object');
    expect($result['schema']->properties)->toHaveKeys(['data', 'current_page', 'last_page', 'total', 'links']);
    expect($result['schema']->properties['data']->type)->toBe('array');
});

test('infers error responses (422, 403, 404, abort, @throws)', function () {
    $code = '<?php
    namespace App\Http\Controllers;
    class SecureController {
        /**
         * @throws \Illuminate\Auth\AuthenticationException
         * @throws \Illuminate\Auth\Access\AuthorizationException
         * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
         */
        public function update() {
            $this->authorize("update");
            abort_if(true, 400);
            abort(409, "Conflict");
        }
    }';

    $parser = (new ParserFactory)->createForHostVersion();
    $stmts = $parser->parse($code);
    $class = (new NodeFinder)->findFirstInstanceOf($stmts, Class_::class);
    $method = (new NodeFinder)->findFirstInstanceOf($class->stmts, ClassMethod::class);

    $ast = new ActionAst($method, 'SecureController.php', 4, [], 'App\Http\Controllers');

    $errorInferer = new ErrorResponseInferer;
    $errors = $errorInferer->infer($ast, hasValidation: true, hasModelBinding: true);

    expect($errors)->toHaveKeys(['400', '401', '403', '404', '409', '422']);
    expect($errors['422']['description'])->toBe('Validation error');
    expect($errors['404']['description'])->toBe('Resource not found');
    expect($errors['403']['description'])->toBe('Forbidden / unauthorized action');
    expect($errors['409']['description'])->toContain('409');
});
