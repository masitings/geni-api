<?php

declare(strict_types=1);

use Geni\Inference\AnnotationMerger;
use Geni\Inference\Document\Schema;

test('merges parameter with infer: true keeping inferred facets', function () {
    $merger = new AnnotationMerger;

    $inferred = new Schema;
    $inferred->type = 'integer';
    $inferred->format = 'int64';

    $annotation = [
        'description' => 'User identifier',
        'example' => 42,
        'infer' => true,
    ];

    $result = $merger->mergeParameter($inferred, $annotation);

    expect($result['schema']->type)->toBe('integer');
    expect($result['schema']->format)->toBe('int64');
    expect($result['schema']->description)->toBe('User identifier');
    expect($result['schema']->extensions['example'])->toBe(42);
});

test('discards inferred schema when infer: false is specified', function () {
    $merger = new AnnotationMerger;

    $inferred = new Schema;
    $inferred->type = 'string';
    $inferred->format = 'uuid';

    $annotation = [
        'type' => 'integer',
        'description' => 'Custom non-inferred ID',
        'infer' => false,
        'required' => true,
    ];

    $result = $merger->mergeParameter($inferred, $annotation);

    expect($result['schema']->type)->toBe('integer');
    expect($result['schema']->format)->toBeNull();
    expect($result['schema']->description)->toBe('Custom non-inferred ID');
    expect($result['required'])->toBeTrue();
});
