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

test('response priority 3: declared return type wins when inferred has no shape', function () {
    $resolver = new ResponseTypeResolver;

    $inferred = new Schema; // empty

    $result = $resolver->resolve(null, 'App\Http\Resources\PostResource', $inferred);

    expect($result->type)->toBe('App\Http\Resources\PostResource');
});
