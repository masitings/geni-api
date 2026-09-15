<?php

declare(strict_types=1);

use Geni\Inference\AttributeAnnotationReader;
use Geni\Inference\PhpDocAnnotationParser;
use Geni\Laravel\Attributes\Api;
use Geni\Laravel\Attributes\BodyParameter;
use Geni\Laravel\Attributes\Endpoint;
use Geni\Laravel\Attributes\ExcludeAllRoutesFromDocs;
use Geni\Laravel\Attributes\ExcludeRouteFromDocs;
use Geni\Laravel\Attributes\Group;
use Geni\Laravel\Attributes\HeaderParameter;
use Geni\Laravel\Attributes\IgnoreParam;
use Geni\Laravel\Attributes\IgnoreResponse;
use Geni\Laravel\Attributes\PathParameter;
use Geni\Laravel\Attributes\QueryParameter;
use Geni\Laravel\Attributes\Response as ResponseAttribute;
use Geni\Laravel\Attributes\SchemaName;

#[ExcludeAllRoutesFromDocs]
#[Group(name: 'Accounts', description: 'Account operations', weight: 10)]
#[SchemaName('AccountDto')]
#[Api(only: ['v1', 'internal'])]
class SampleAnnotatedController
{
    #[ExcludeRouteFromDocs]
    public function excludedMethod(): void {}

    #[Endpoint(operationId: 'listAccounts', title: 'List Accounts', description: 'Fetch all accounts')]
    #[QueryParameter(name: 'search', description: 'Filter keyword', required: false, type: 'string', infer: false)]
    #[HeaderParameter(name: 'X-Tenant-Id', required: true, type: 'string')]
    #[PathParameter(name: 'accountId', required: true, type: 'integer')]
    #[BodyParameter(name: 'payload', required: true, type: 'object')]
    #[ResponseAttribute(status: 200, description: 'Success')]
    #[IgnoreParam(name: 'internal_token')]
    #[IgnoreResponse(status: 400)]
    public function getAccount(): void {}
}

test('parses method phpdoc tags into structured metadata', function () {
    $parser = new PhpDocAnnotationParser;

    $doc = '/**
     * @response 200 \App\Http\Resources\PostResource Success response
     * @operationId getPostsList
     * @requestMediaType application/x-www-form-urlencoded
     * @deprecated Old endpoint
     * @unauthenticated
     * @ignoreParam secret_code
     * @tags Blog, Posts
     * @throws \Illuminate\Auth\AuthenticationException
     */';

    $result = $parser->parseMethodDocblock($doc);

    expect($result['response']['status'])->toBe(200);
    expect($result['response']['type'])->toBe('\App\Http\Resources\PostResource');
    expect($result['response']['description'])->toBe('Success response');
    expect($result['operationId'])->toBe('getPostsList');
    expect($result['requestMediaType'])->toBe('application/x-www-form-urlencoded');
    expect($result['deprecated'])->toBeTrue();
    expect($result['unauthenticated'])->toBeTrue();
    expect($result['ignoredParams'])->toBe(['secret_code']);
    expect($result['tags'])->toBe(['Blog', 'Posts']);
    expect($result['throws'])->toBe(['\Illuminate\Auth\AuthenticationException']);
});

test('parses paired @status and @body phpdoc tags', function () {
    $parser = new PhpDocAnnotationParser;

    $doc = '/**
     * @status 201
     * @body \App\Http\Resources\CreatedPostResource
     * @description Post created successfully
     */';

    $result = $parser->parseMethodDocblock($doc);

    expect($result['response']['status'])->toBe(201);
    expect($result['response']['type'])->toBe('\App\Http\Resources\CreatedPostResource');
    expect($result['response']['description'])->toBe('Post created successfully');
});

test('parses class phpdoc @mixin and @tags', function () {
    $parser = new PhpDocAnnotationParser;

    $doc = '/**
     * @mixin \App\Models\Post
     * @tags PublicApi, Articles
     */';

    $result = $parser->parseClassDocblock($doc);

    expect($result['backingModel'])->toBe('\App\Models\Post');
    expect($result['tags'])->toBe(['PublicApi', 'Articles']);
});

test('parses field phpdoc tags', function () {
    $parser = new PhpDocAnnotationParser;

    $doc = '/**
     * @var string
     * @format email
     * @default "test@example.com"
     * @example "user@domain.com"
     * @query
     */';

    $result = $parser->parseFieldDocblock($doc);

    expect($result['type'])->toBe('string');
    expect($result['format'])->toBe('email');
    expect($result['default'])->toBe('test@example.com');
    expect($result['example'])->toBe('user@domain.com');
    expect($result['query'])->toBeTrue();
});

test('reads PHP 8 attributes using AttributeAnnotationReader', function () {
    $reader = new AttributeAnnotationReader;

    $classAttrs = $reader->readClassAttributes(SampleAnnotatedController::class);
    expect($classAttrs['excludeAll'])->toBeTrue();
    expect($classAttrs['group']['name'])->toBe('Accounts');
    expect($classAttrs['group']['weight'])->toBe(10);
    expect($classAttrs['schemaName'])->toBe('AccountDto');
    expect($classAttrs['apiOnly'])->toBe(['v1', 'internal']);

    $methodAttrs = $reader->readMethodAttributes(SampleAnnotatedController::class, 'getAccount');
    expect($methodAttrs['endpoint']['operationId'])->toBe('listAccounts');
    expect($methodAttrs['endpoint']['title'])->toBe('List Accounts');
    expect($methodAttrs['ignoredParams'])->toHaveCount(1);
    expect($methodAttrs['ignoredParams'][0]['name'])->toBe('internal_token');
    expect($methodAttrs['ignoredResponses'])->toBe([400]);

    $params = $methodAttrs['parameters'];
    expect($params)->toHaveCount(4);
    $inTypes = array_column($params, 'in');
    expect($inTypes)->toContain('query', 'header', 'path', 'body');

    $queryParam = array_values(array_filter($params, fn ($p) => $p['in'] === 'query'))[0];
    expect($queryParam['name'])->toBe('search');
    expect($queryParam['infer'])->toBeFalse();
});
