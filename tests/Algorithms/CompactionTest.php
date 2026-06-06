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

    it('compacts a @language container into a language map (arrayifying collisions)', function () {
        $expanded = [[
            'http://example.com/vocab/label' => [
                ['@value' => 'The Queen', '@language' => 'en'],
                ['@value' => 'Die Königin', '@language' => 'de'],
                ['@value' => 'Ihre Majestät', '@language' => 'de'],
            ],
        ]];
        $context = ['label' => ['@id' => 'http://example.com/vocab/label', '@container' => '@language']];

        $result = compactWith($expanded, $context);

        expect($result['label'])->toBe(['en' => 'The Queen', 'de' => ['Die Königin', 'Ihre Majestät']]);
    });

    it('compacts an @index container into an index map (stripping @index)', function () {
        $expanded = [[
            'http://example.com/vocab/author' => [
                ['@id' => 'http://example.org/person/1', '@index' => 'regular'],
                ['@id' => 'http://example.org/guest/cd', '@index' => 'guest'],
            ],
        ]];
        $context = ['author' => ['@id' => 'http://example.com/vocab/author', '@container' => '@index']];

        $result = compactWith($expanded, $context);

        expect($result['author'])->toBe([
            'regular' => ['@id' => 'http://example.org/person/1'],
            'guest' => ['@id' => 'http://example.org/guest/cd'],
        ]);
    });

    it('compacts an @id container into an id map (stripping @id)', function () {
        $expanded = [[
            'http://example/idmap' => [
                ['http://example/label' => [['@value' => 'foo node']], '@id' => 'http://example.org/foo'],
            ],
        ]];
        $context = ['@vocab' => 'http://example/', 'idmap' => ['@container' => '@id']];

        $result = compactWith($expanded, $context);

        expect($result['idmap'])->toBe(['http://example.org/foo' => ['label' => 'foo node']]);
    });

    it('compacts a @type container into a type map (stripping the first @type)', function () {
        $expanded = [[
            'http://example/typemap' => [
                ['http://example/label' => [['@value' => 'foo typed']], '@type' => ['http://example.org/foo']],
            ],
        ]];
        $context = ['@vocab' => 'http://example/', 'typemap' => ['@container' => '@type']];

        $result = compactWith($expanded, $context);

        expect($result['typemap'])->toBe(['http://example.org/foo' => ['label' => 'foo typed']]);
    });
});
