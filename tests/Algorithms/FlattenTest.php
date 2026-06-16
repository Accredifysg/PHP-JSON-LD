<?php

declare(strict_types=1);

use Accredify\JsonLd\JsonLdOptions;
use Accredify\JsonLd\JsonLdProcessor;
use Accredify\JsonLd\Tests\Context\Support\StubDocumentLoader;

/*
|--------------------------------------------------------------------------
| Unit smoke tests for the Flattening algorithm
|--------------------------------------------------------------------------
| Full conformance lives in tests/W3c/Algorithms/FlattenTest.php (the W3C
| suite). These pin the flatten-specific shapes — @id ordering, named-graph
| folding, free-floating-node removal, @list preservation, and the optional
| compaction step — so refactors can't silently break them.
*/

/**
 * @param  array<array-key, mixed>  $document
 * @param  array<array-key, mixed>|string|null  $context
 * @return array<mixed>
 */
function flattenDoc(array $document, array|string|null $context = null, ?JsonLdOptions $options = null): array
{
    return (new JsonLdProcessor(new StubDocumentLoader))
        ->flatten($document, $context, $options)
        ->toArray();
}

it('flattens a simple node into the default graph', function () {
    $result = flattenDoc([
        '@context' => ['name' => 'http://example.com/name'],
        '@id' => 'http://example.com/s',
        'name' => 'Alice',
    ]);

    expect($result)->toEqual([
        [
            '@id' => 'http://example.com/s',
            'http://example.com/name' => [['@value' => 'Alice']],
        ],
    ]);
});

it('drops free-floating nodes carrying only @id', function () {
    expect(flattenDoc(['@id' => 'http://example.com/s']))->toEqual([]);
});

it('orders the flattened nodes by @id', function () {
    $result = flattenDoc([
        '@context' => [
            'knows' => ['@id' => 'http://example.com/knows', '@type' => '@id'],
            'name' => 'http://example.com/name',
        ],
        '@id' => 'http://example.com/b',
        'name' => 'B',
        'knows' => ['@id' => 'http://example.com/a', 'name' => 'A'],
    ]);

    $ids = [];
    foreach ($result as $node) {
        $ids[] = is_array($node) ? ($node['@id'] ?? null) : null;
    }
    expect($ids)->toBe(['http://example.com/a', 'http://example.com/b']);
});

it('folds a named graph into a @graph entry on its graph-name node', function () {
    $result = flattenDoc([
        '@context' => ['name' => 'http://example.com/name'],
        '@id' => 'http://example.com/g',
        '@graph' => [
            ['@id' => 'http://example.com/s', 'name' => 'Alice'],
        ],
    ]);

    expect($result)->toEqual([
        [
            '@id' => 'http://example.com/g',
            '@graph' => [
                [
                    '@id' => 'http://example.com/s',
                    'http://example.com/name' => [['@value' => 'Alice']],
                ],
            ],
        ],
    ]);
});

it('preserves @list values verbatim', function () {
    $result = flattenDoc([
        '@context' => ['nums' => ['@id' => 'http://example.com/nums', '@container' => '@list']],
        '@id' => 'http://example.com/s',
        'nums' => [1, 2, 3],
    ]);

    expect($result)->toEqual([
        [
            '@id' => 'http://example.com/s',
            'http://example.com/nums' => [
                ['@list' => [
                    ['@value' => 1],
                    ['@value' => 2],
                    ['@value' => 3],
                ]],
            ],
        ],
    ]);
});

it('compacts and @graph-wraps the output when a context is supplied', function () {
    $result = flattenDoc(
        [
            '@id' => 'http://example.com/s',
            'http://example.com/name' => 'Alice',
        ],
        ['name' => 'http://example.com/name'],
        new JsonLdOptions(compactArrays: false),
    );

    expect($result)->toEqual([
        '@context' => ['name' => 'http://example.com/name'],
        '@graph' => [
            ['@id' => 'http://example.com/s', 'name' => ['Alice']],
        ],
    ]);
});
