<?php

declare(strict_types=1);

use Geni\Inference\Document\Schema;
use Geni\Inference\ResponseTypeResolver;

test('response priority 1: explicit annotation wins over declared and inferred', function () {
    $resolver = new ResponseTypeResolver;

    $annotated = new Schema;
    $annotated->ref = '#/components/schemas/ExplicitResource';

    $inferred = new Schema;
    $inferred->type = 'object';

    $result = $resolver->resolve($annotated, 'App\Http\Resources\DeclaredResource', $inferred);

    expect($result->ref)->toBe('#/components/schemas/ExplicitResource');
});

test('response priority 2: inferred type wins when more specific than broad declared return type', function () {
    $resolver = new ResponseTypeResolver;

    $inferred = new Schema;
    $inferred->ref = '#/components/schemas/ConcretePostResource';

    $result = $resolver->resolve(null, 'Illuminate\Http\JsonResponse', $inferred);

    expect($result->ref)->toBe('#/components/schemas/ConcretePostResource');
});

test('response priority 3: a declared class name is never emitted as an OpenAPI type', function () {
    $resolver = new ResponseTypeResolver;

    $result = $resolver->resolve(null, 'Illuminate\Http\Response', new Schema);

    expect($result->type)->toBeNull()
        ->and($result->ref)->toBeNull();
});

test('response priority 3: declared scalar return types map to OpenAPI types', function () {
    $resolver = new ResponseTypeResolver;

    expect($resolver->resolve(null, 'string', new Schema)->type)->toBe('string')
        ->and($resolver->resolve(null, 'int', new Schema)->type)->toBe('integer')
        ->and($resolver->resolve(null, 'bool', new Schema)->type)->toBe('boolean');
});
