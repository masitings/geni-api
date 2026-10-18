<?php

declare(strict_types=1);

use Geni\Laravel\DocumentGenerator;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Resources\Controllers\MiscController;
use Tests\Fixtures\Resources\Controllers\UserController;
use Tests\TestCase;

/**
 * Expectations mirror what Scramble generates for the same fixture app
 * (tests/Fixtures/Resources), so documents migrated from Scramble keep their shape.
 */
class ResourceInferenceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $document;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('geni.migration_paths', [__DIR__.'/../../Fixtures/Resources/database_migrations']);
    }

    protected function defineRoutes($router): void
    {
        Route::prefix('api')->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::get('users-all', [UserController::class, 'all']);
            Route::get('users/{user}', [UserController::class, 'show']);
            Route::get('users/{user}/raw', [UserController::class, 'raw']);
            Route::get('users/{user}/meta', [UserController::class, 'withMeta']);
            Route::get('teams/{team}', [MiscController::class, 'team']);
            Route::get('posts/{post}/unwrapped', [MiscController::class, 'unwrapped']);
            Route::get('posts', [MiscController::class, 'posts']);
            Route::get('posts/find/{id}', [MiscController::class, 'find']);
            Route::get('posts-all', [MiscController::class, 'list']);
            Route::delete('posts/{post}', [MiscController::class, 'destroy']);
            Route::post('posts', [MiscController::class, 'store']);
            Route::post('posts/saved', [MiscController::class, 'storeWithSave']);
            Route::post('posts/accepted', [MiscController::class, 'accepted']);
            Route::get('posts/{post}/custom', [MiscController::class, 'custom']);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->document = json_decode((string) json_encode(app(DocumentGenerator::class)->generate()), true);
    }

    public function test_model_attributes_use_migration_columns_and_casts(): void
    {
        $user = $this->schemaComponent('UserResource')['properties'];

        expect($user['id'])->toBe(['type' => 'integer'])
            ->and($user['email_verified_at'])->toBe(['type' => ['string', 'null'], 'format' => 'date-time'])
            ->and($user['is_admin'])->toBe(['type' => 'boolean'])
            ->and($user['settings'])->toBe(['type' => ['array', 'null']])
            ->and($user['status'])->toBe(['$ref' => '#/components/schemas/UserStatus'])
            ->and($user['team_id'])->toBe(['type' => ['integer', 'null']])
            ->and($this->schemaComponent('UserStatus'))->toBe(['type' => 'string', 'enum' => ['active', 'suspended']]);
    }

    public function test_expressions_literals_and_nested_arrays(): void
    {
        $user = $this->schemaComponent('UserResource')['properties'];

        expect($user['created_at'])->toBe(['type' => ['string', 'null']])
            ->and($user['is_verified'])->toBe(['type' => 'boolean'])
            ->and($user['label'])->toBe(['type' => 'string', 'const' => 'user'])
            ->and($user['score'])->toBe(['type' => 'number', 'const' => 1.5])
            ->and($user['rank'])->toBe(['type' => 'integer', 'const' => 3])
            ->and($user['flag'])->toBe(['type' => 'boolean'])
            ->and($user['meta'])->toBe([
                'type' => 'object',
                'properties' => [
                    'source' => ['type' => 'string', 'const' => 'api'],
                    'version' => ['type' => 'integer', 'const' => 2],
                ],
                'required' => ['source', 'version'],
            ])
            ->and($user['tags'])->toBe(['type' => 'array', 'items' => ['type' => 'string']]);
    }

    public function test_relations_and_nested_resources(): void
    {
        $user = $this->schemaComponent('UserResource')['properties'];

        expect($user['posts'])->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PostResource']])
            ->and($user['team'])->toBe(['anyOf' => [['$ref' => '#/components/schemas/TeamResource'], ['type' => 'null']]])
            ->and($user['latest_post'])->toBe(['$ref' => '#/components/schemas/PostResource'])
            ->and($user['raw_posts'])->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Post']])
            ->and($this->schemaComponent('Post')['properties']['rating'])->toBe(['type' => ['number', 'null']]);
    }

    public function test_conditional_keys_are_optional(): void
    {
        $user = $this->schemaComponent('UserResource');

        expect($user['properties']['secret'])->toBe(['type' => 'string', 'const' => 'hidden'])
            ->and($user['properties']['posts_count'])->toBe(['type' => 'integer'])
            ->and($user['properties']['nickname'])->toBe(['type' => 'string'])
            ->and($user['properties'])->toHaveKeys(['extra_one', 'extra_two', 'always'])
            ->and($user['required'])->toBe([
                'id', 'name', 'email_verified_at', 'is_admin', 'settings', 'status', 'team_id', 'created_at',
                'is_verified', 'label', 'score', 'rank', 'flag', 'meta', 'tags', 'always',
            ]);
    }

    public function test_hidden_model_attributes_are_not_serialized(): void
    {
        expect($this->schemaComponent('User')['properties'])->not->toHaveKey('email')
            ->and($this->schemaComponent('User')['properties'])->toHaveKey('name');
    }

    public function test_single_resource_is_wrapped_in_data(): void
    {
        $response = $this->document['paths']['/api/users/{user}']['get']['responses']['200'];

        expect($response['description'])->toBe('`UserResource`')
            ->and($response['content']['application/json']['schema'])->toBe([
                'type' => 'object',
                'properties' => ['data' => ['$ref' => '#/components/schemas/UserResource']],
                'required' => ['data'],
            ]);
    }

    public function test_additional_data_is_merged_next_to_data(): void
    {
        $schema = $this->document['paths']['/api/users/{user}/meta']['get']['responses']['200']['content']['application/json']['schema'];

        expect($schema['properties']['meta'])->toBe([
            'type' => 'object',
            'properties' => ['version' => ['type' => 'string', 'const' => '1']],
            'required' => ['version'],
        ])->and($schema['required'])->toBe(['data', 'meta']);
    }

    public function test_collections_and_paginated_collections(): void
    {
        $all = $this->document['paths']['/api/users-all']['get']['responses']['200'];
        $paginated = $this->document['paths']['/api/users']['get']['responses']['200'];
        $paginatedSchema = $paginated['content']['application/json']['schema'];

        expect($all['description'])->toBe('Array of `UserResource`')
            ->and($all['content']['application/json']['schema']['properties']['data']['items'])->toBe(['$ref' => '#/components/schemas/UserResource'])
            ->and($paginated['description'])->toBe('Paginated set of `UserResource`')
            ->and($paginatedSchema['required'])->toBe(['data', 'links', 'meta'])
            ->and(array_keys($paginatedSchema['properties']['links']['properties']))->toBe(['first', 'last', 'prev', 'next'])
            ->and(array_keys($paginatedSchema['properties']['meta']['properties']))->toBe(['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total']);
    }

    public function test_returning_a_bound_model_documents_the_serialized_model(): void
    {
        $response = $this->document['paths']['/api/users/{user}/raw']['get']['responses']['200'];

        expect($response['description'])->toBe('`User`')
            ->and($response['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/User']);
    }

    public function test_parent_to_array_custom_wrap_and_with(): void
    {
        $schema = $this->document['paths']['/api/teams/{team}']['get']['responses']['200']['content']['application/json']['schema'];

        expect($schema['required'])->toBe(['team', 'version'])
            ->and($schema['properties']['version'])->toBe(['type' => 'string', 'const' => '2.0'])
            ->and(array_keys($this->schemaComponent('TeamWithParentResource')['properties']))->toBe(['id', 'name', 'created_at', 'updated_at']);
    }

    public function test_wrap_null_returns_the_resource_itself(): void
    {
        expect($this->document['paths']['/api/posts/{post}/unwrapped']['get']['responses']['200']['content']['application/json']['schema'])
            ->toBe(['$ref' => '#/components/schemas/UnwrappedPostResource']);
    }

    public function test_resource_collection_subclass_uses_collects(): void
    {
        $schema = $this->document['paths']['/api/posts']['get']['responses']['200']['content']['application/json']['schema'];

        expect($schema['properties']['data']['items'])->toBe(['$ref' => '#/components/schemas/PostResource'])
            ->and($schema['required'])->toBe(['data', 'links', 'meta']);
    }

    public function test_model_queries_and_no_content(): void
    {
        $paths = $this->document['paths'];

        expect($paths['/api/posts/find/{id}']['get']['responses']['200']['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/Post'])
            ->and($paths['/api/posts-all']['get']['responses']['200']['content']['application/json']['schema'])->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Post']])
            ->and($paths['/api/posts/{post}']['delete']['responses'])->toHaveKey('204')
            ->and($paths['/api/posts/{post}']['delete']['responses'])->not->toHaveKey('200');
    }

    public function test_status_codes_for_created_models_and_explicit_codes(): void
    {
        $paths = $this->document['paths'];

        expect($paths['/api/posts']['post']['responses'])->toHaveKey('201')->not->toHaveKey('200')
            ->and($paths['/api/posts']['post']['responses']['201']['content']['application/json']['schema']['properties']['data'])->toBe(['$ref' => '#/components/schemas/PostResource'])
            ->and($paths['/api/posts/saved']['post']['responses'])->toHaveKey('201')
            ->and($paths['/api/posts/accepted']['post']['responses'])->toHaveKey('202')
            ->and($paths['/api/posts/{post}/custom']['get']['responses'])->toHaveKey('203')
            ->and($paths['/api/users/{user}']['get']['responses'])->toHaveKey('200');
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaComponent(string $name): array
    {
        return $this->document['components']['schemas'][$name];
    }
}
