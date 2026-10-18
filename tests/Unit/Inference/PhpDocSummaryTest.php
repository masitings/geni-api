<?php

declare(strict_types=1);

use Geni\Inference\PhpDocAnnotationParser;

test('first paragraph becomes the summary and the rest the description', function () {
    $result = (new PhpDocAnnotationParser)->parseMethodDocblock('/**
     * List projects, paginated.
     *
     * Returns the projects of the current workspace,
     * 15 per page.
     *
     * Second paragraph with **markdown**.
     *
     * @tags Projects
     */');

    expect($result['summary'])->toBe('List projects, paginated')
        ->and($result['docDescription'])->toBe("Returns the projects of the current workspace,\n15 per page.\n\nSecond paragraph with **markdown**.")
        ->and($result['tags'])->toBe(['Projects']);
});

test('a multi-line first paragraph stays in the summary', function () {
    $result = (new PhpDocAnnotationParser)->parseMethodDocblock("/**\r\n * Show a single project\r\n * across two lines.\r\n */");

    expect($result['summary'])->toBe("Show a single project\nacross two lines")
        ->and($result)->not->toHaveKey('docDescription');
});

test('a docblock with only tags has no summary or description', function () {
    $result = (new PhpDocAnnotationParser)->parseMethodDocblock('/**
     * @operationId listThings
     */');

    expect($result)->not->toHaveKey('summary')
        ->and($result)->not->toHaveKey('docDescription')
        ->and($result['operationId'])->toBe('listThings');
});

test('an explicit @summary tag overrides the first paragraph', function () {
    $result = (new PhpDocAnnotationParser)->parseMethodDocblock('/**
     * Long first paragraph that should not be the title.
     *
     * Details.
     *
     * @summary Create a user.
     */');

    expect($result['summary'])->toBe('Create a user')
        ->and($result['docDescription'])->toBe('Details.');
});
