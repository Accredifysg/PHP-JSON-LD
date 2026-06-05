<?php

declare(strict_types=1);

use Accredify\JsonLd\JsonLdProcessor;
use Accredify\JsonLd\Tests\Context\Support\StubDocumentLoader;

/*
|--------------------------------------------------------------------------
| Unit tests for Compaction (first-pass)
|--------------------------------------------------------------------------
| Spec conformance is exercised by the W3C harness (composer test:w3c).
| These cover the wiring + common shapes so refactors don't break basics.
*/

/**
 * @param  array<array-key, mixed>  $expanded
 * @param  array<string, mixed>  $context
 * @return array<string, mixed>
 */
function compactWith(array $expanded, array $context): array
{
    return (new JsonLdProcessor(new StubDocumentLoader))
        ->compact($expanded, $context)
        ->toArray();
}

describe('JsonLdProcessor::compact', function () {
    it('compacts IRIs back to terms and prepends the context', function () {
        $expanded = [[
            '@id' => 'http://example.com/id1',
            '@type' => ['http://example.com/Type1'],
            'http://example.com/name' => [['@value' => 'Alice']],
        ]];
        $context = ['name' => 'http://example.com/name', 'Type1' => 'http://example.com/Type1'];

        $result = compactWith($expanded, $context);

        expect($result['@context'])->toBe($context);
        expect($result['@id'])->toBe('http://example.com/id1');
        expect($result['@type'])->toBe('Type1');
        expect($result['name'])->toBe('Alice');
    });

    it('drops a coerced @type during value compaction', function () {
        $expanded = [[
            'http://example.org/term1' => [[
                '@value' => 'v1',
                '@type' => 'http://example.org/datatype',
            ]],
        ]];
        $context = [
            'ex' => 'http://example.org/',
            'term1' => ['@id' => 'ex:term1', '@type' => 'ex:datatype'],
        ];

        $result = compactWith($expanded, $context);

        // The term coerces to the value's @type, so it collapses to a scalar.
        expect($result['term1'])->toBe('v1');
    });

    it('compacts a @type: @id node reference to a bare IRI', function () {
        $expanded = [[
            'http://example.org/term2' => [['@id' => 'http://example.org/id2']],
        ]];
        $context = [
            'ex' => 'http://example.org/',
            'term2' => ['@id' => 'ex:term2', '@type' => '@id'],
        ];

        $result = compactWith($expanded, $context);

        expect($result['term2'])->toBe('ex:id2');
    });

    it('keeps a @list under a @list-container term as a bare array', function () {
        $expanded = [[
            'http://example.com/list' => [['@list' => [['@value' => 'a'], ['@value' => 'b']]]],
        ]];
        $context = ['list' => ['@id' => 'http://example.com/list', '@container' => '@list']];

        $result = compactWith($expanded, $context);

        expect($result['list'])->toBe(['a', 'b']);
    });
});
