<?php

declare(strict_types=1);

use Geni\Inference\ActionAst;
use Geni\Inference\Document\Schema;
use Geni\Inference\RuleTransformer;
use Geni\Inference\ValidationRuleExtractor;
use Geni\Inference\ValidationRuleSchemaMapper;
use Geni\SchemaReader\ColumnSchema;
use Geni\SchemaReader\ColumnType;
use Geni\SchemaReader\DatabaseSchema;
use Geni\SchemaReader\TableSchema;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Tests\Fixtures\Inference\PostStatus;

test('maps standard validation rules to json schema', function () {
    $mapper = new ValidationRuleSchemaMapper;

    $fields = [
        'title' => 'required|string|min:3|max:100',
        'age' => 'nullable|integer|between:18,65',
        'tags' => 'required|array',
        'email' => 'required|email',
        'uuid_id' => 'required|uuid',
        'created_at' => 'nullable|date',
        'code' => 'required|size:6',
        'slug' => 'required|regex:/^[a-z0-9-]+$/',
    ];

    $result = $mapper->map($fields);

    expect($result['required'])->toContain('title', 'tags', 'email', 'uuid_id', 'code', 'slug');
    expect($result['hasFile'])->toBeFalse();

    $props = $result['schema']->properties;
    expect($props['title']->type)->toBe('string');
    expect($props['title']->minLength)->toBe(3);
    expect($props['title']->maxLength)->toBe(100);

    expect($props['age']->type)->toContain('integer', 'null');
    expect($props['age']->minimum)->toBe(18);
    expect($props['age']->maximum)->toBe(65);

    expect($props['tags']->type)->toBe('array');
    expect($props['email']->format)->toBe('email');
    expect($props['uuid_id']->format)->toBe('uuid');
    expect($props['created_at']->format)->toBe('date');
    expect($props['code']->minLength)->toBe(6);
    expect($props['slug']->extensions['pattern'])->toBe('^[a-z0-9-]+$');
});

test('maps in and Rule::in to enum', function () {
    $mapper = new ValidationRuleSchemaMapper;

    $fields = [
        'role' => 'required|in:admin,editor,subscriber',
    ];

    $result = $mapper->map($fields);
    $props = $result['schema']->properties;

    expect($props['role']->enum)->toBe(['admin', 'editor', 'subscriber']);
});

test('maps backed enum via AST parsing', function () {
    $mapper = new ValidationRuleSchemaMapper;

    $fields = [
        'status' => 'required|enum:'.PostStatus::class,
    ];

    $result = $mapper->map($fields);
    $props = $result['schema']->properties;

    expect($props['status']->type)->toBe('string');
    expect($props['status']->enum)->toBe(['draft', 'published', 'archived']);
});

test('resolves exists rule column type from database schema', function () {
    $mapper = new ValidationRuleSchemaMapper;

    $dbSchema = new DatabaseSchema;
    $col = new ColumnSchema('id', ColumnType::Integer, 'id');
    $uuidCol = new ColumnSchema('uuid', ColumnType::Uuid, 'uuid');
    $table = new TableSchema('users', null, [
        'id' => $col,
        'uuid' => $uuidCol,
    ]);
    $dbSchema->tables['users'] = $table;

    $fields = [
        'user_id' => 'required|exists:users,id',
        'account_uuid' => 'required|exists:users,uuid',
        'missing_id' => 'required|exists:nonexistent,id',
    ];

    $result = $mapper->map($fields, 'test.php', 10, $dbSchema);
    $props = $result['schema']->properties;

    expect($props['user_id']->type)->toBe('integer');
    expect($props['account_uuid']->type)->toBe('string');
    expect($props['account_uuid']->format)->toBe('uuid');

    // Missing exists falls back to string + records diagnostic (divergence #3)
    expect($props['missing_id']->type)->toBe('string');
    expect($result['diagnostics'])->not->toBeEmpty();
    expect($result['diagnostics'][0]->reason)->toContain('Could not statically resolve column "id" on table "nonexistent"');
});

test('detects file/image rules and flags multipart/form-data', function () {
    $mapper = new ValidationRuleSchemaMapper;

    $fields = [
        'avatar' => 'required|image|max:2048',
        'name' => 'required|string',
    ];

    $result = $mapper->map($fields);

    expect($result['hasFile'])->toBeTrue();
    expect($result['schema']->properties['avatar']->format)->toBe('binary');
});

test('confirmed rule adds sibling confirmation field via FullRuleSetTransformer', function () {
    $mapper = new ValidationRuleSchemaMapper;

    $fields = [
        'password' => 'required|string|min:8|confirmed',
    ];

    $result = $mapper->map($fields);
    $props = $result['schema']->properties;

    expect(isset($props['password_confirmation']))->toBeTrue();
    expect($props['password_confirmation']->type)->toBe('string');
    expect($props['password_confirmation']->minLength)->toBe(8);
    expect($result['required'])->toContain('password', 'password_confirmation');
});

test('supports custom RuleTransformer extension point', function () {
    $customTransformer = new class implements RuleTransformer
    {
        public function matches(string $rule, string $field): bool
        {
            return str_starts_with($rule, 'credit_card');
        }

        public function transform(string $rule, string $field, Schema $schema, string $file, int $line): array
        {
            $schema->type = 'string';
            $schema->format = 'credit-card';

            return ['schema' => $schema, 'required' => true];
        }
    };

    $mapper = new ValidationRuleSchemaMapper(null, [$customTransformer]);

    $fields = [
        'card_number' => 'credit_card',
    ];

    $result = $mapper->map($fields);
    expect($result['schema']->properties['card_number']->format)->toBe('credit-card');
    expect($result['required'])->toContain('card_number');
});

test('extracts rules from Validator::make() call', function () {
    $code = '<?php
    namespace App\Http\Controllers;
    use Illuminate\Support\Facades\Validator;
    class AuthController {
        public function login() {
            $v = Validator::make(request()->all(), [
                "username" => "required|string",
                "password" => "required|string",
            ]);
        }
    }';

    $parser = (new ParserFactory)->createForHostVersion();
    $stmts = $parser->parse($code);
    $class = (new NodeFinder)->findFirstInstanceOf($stmts, Class_::class);
    $method = (new NodeFinder)->findFirstInstanceOf($class->stmts, ClassMethod::class);

    $ast = new ActionAst($method, 'AuthController.php', 5, ['Validator' => 'Illuminate\Support\Facades\Validator'], 'App\Http\Controllers');

    $extractor = new ValidationRuleExtractor;
    $extracted = $extractor->extractFromActionAst($ast);

    expect(array_keys($extracted['fields']))->toBe(['username', 'password']);
});

test('extracts request accessor methods with defaults', function () {
    $code = '<?php
    namespace App\Http\Controllers;
    class PostController {
        public function index($request) {
            $page = $request->integer("page", 1);
            $limit = $request->integer("per_page", 15);
            $sort = $request->string("sort", "created_at");
            $desc = $request->boolean("desc", false);
        }
    }';

    $parser = (new ParserFactory)->createForHostVersion();
    $stmts = $parser->parse($code);
    $class = (new NodeFinder)->findFirstInstanceOf($stmts, Class_::class);
    $method = (new NodeFinder)->findFirstInstanceOf($class->stmts, ClassMethod::class);

    $ast = new ActionAst($method, 'PostController.php', 5, [], 'App\Http\Controllers');

    $extractor = new ValidationRuleExtractor;
    $extracted = $extractor->extractFromActionAst($ast);

    expect($extracted['accessors'])->toHaveKey('page');
    expect($extracted['accessors']['page']['type'])->toBe('integer');
    expect($extracted['accessors']['page']['default'])->toBe(1);

    expect($extracted['accessors']['per_page']['default'])->toBe(15);
    expect($extracted['accessors']['sort']['type'])->toBe('string');
    expect($extracted['accessors']['sort']['default'])->toBe('created_at');
    expect($extracted['accessors']['desc']['type'])->toBe('boolean');
    expect($extracted['accessors']['desc']['default'])->toBe(false);
});
