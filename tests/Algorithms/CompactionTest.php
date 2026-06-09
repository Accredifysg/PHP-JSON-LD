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

    it('recurses into @graph, compacting inner nodes (keeping @graph an array)', function () {
        // §5.6 / #t0039/#t0016: a node-level @graph value stays an array even
        // for a single member (unlike @included, which unwraps).
        $expanded = [['@graph' => [['http://example/name' => [['@value' => 'Alice']]]]]];
        $result = compactWith($expanded, ['@vocab' => 'http://example/']);
        expect($result['@graph'])->toBe([['name' => 'Alice']]);
    });

    it('recurses into @included, compacting inner nodes', function () {
        $expanded = [['@included' => [['http://example/name' => [['@value' => 'Bob']]]]]];
        $result = compactWith($expanded, ['@vocab' => 'http://example/']);
        expect($result['@included'])->toBe(['name' => 'Bob']);
    });

    it('wraps multiple top-level nodes in a @graph map', function () {
        $expanded = [['@id' => 'http://example/a'], ['@id' => 'http://example/b']];
        $result = compactWith($expanded, []);
        expect($result)->toHaveKey('@graph');
        expect($result['@graph'])->toHaveCount(2);
    });

    it('groups a @nest-defined property under the (aliased) nest term', function () {
        $expanded = [['http://example/prop' => [['@value' => 'v']]]];
        $context = ['@vocab' => 'http://example/', 'prop' => ['@nest' => '@nest']];
        $result = compactWith($expanded, $context);
        expect($result['@nest'])->toBe(['prop' => 'v']);
    });

    it('uses a @none alias for an unkeyed entry in an @index map', function () {
        $expanded = [['http://example/idx' => [['@value' => 'x']]]];
        $context = ['@vocab' => 'http://example/', 'none' => '@none', 'idx' => ['@container' => '@index']];
        $result = compactWith($expanded, $context);
        expect($result['idx'])->toBe(['none' => 'x']);
    });

    it('does not compact values for a @type: @none term', function () {
        $expanded = [['http://example/p' => [['@value' => 'x', '@type' => 'http://example/T']]]];
        $context = ['@vocab' => 'http://example/', 'p' => ['@type' => '@none']];
        $result = compactWith($expanded, $context);
        expect($result['p'])->toBe(['@value' => 'x', '@type' => 'T']);
    });

    it('compacts a [@graph, @id] container into an id-keyed map', function () {
        $expanded = [['http://example.org/input' => [
            ['@id' => 'http://example.org/gid', '@graph' => [['http://example.org/value' => [['@value' => 'x']]]]],
        ]]];
        $context = ['@vocab' => 'http://example.org/', 'input' => ['@container' => ['@graph', '@id']]];
        $result = compactWith($expanded, $context);
        expect($result['input'])->toBe(['http://example.org/gid' => ['value' => 'x']]);
    });

    it('compacts a [@graph, @index] container into an index-keyed map (or @none)', function () {
        $expanded = [['http://example.org/input' => [
            ['@index' => 'g1', '@graph' => [['http://example.org/value' => [['@value' => 'x']]]]],
            ['@graph' => [['http://example.org/value' => [['@value' => 'y']]]]],
        ]]];
        $context = ['@vocab' => 'http://example.org/', 'none' => '@none', 'input' => ['@container' => ['@graph', '@index']]];
        $result = compactWith($expanded, $context);
        expect($result['input'])->toBe(['g1' => ['value' => 'x'], 'none' => ['value' => 'y']]);
    });

    it('wraps a simple @graph with multiple nodes in @included', function () {
        $expanded = [['http://example.org/input' => [
            ['@graph' => [['http://example.org/value' => [['@value' => 'x']]], ['http://example.org/value' => [['@value' => 'y']]]]],
        ]]];
        $context = ['@vocab' => 'http://example.org/', 'input' => ['@container' => '@graph']];
        $json = json_encode(compactWith($expanded, $context), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"@included"');
        expect($json)->toContain('"value":"x"');
        expect($json)->toContain('"value":"y"');
    });

    it('compacts the inner nodes of a @reverse map', function () {
        $expanded = [[
            '@id' => 'http://example.com/a',
            '@reverse' => ['http://example.com/knows' => [
                ['@id' => 'http://example.com/b', 'http://example.com/name' => [['@value' => 'Bee']]],
            ]],
        ]];
        $json = json_encode(compactWith($expanded, ['name' => 'http://example.com/name']), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"@reverse"');
        expect($json)->toContain('"name":"Bee"');
    });

    it('hoists a reverse-coerced term out of the @reverse map', function () {
        $expanded = [[
            '@id' => 'http://example.com/a',
            '@reverse' => ['http://example.com/knows' => [['@id' => 'http://example.com/b']]],
        ]];
        $context = ['knownBy' => ['@reverse' => 'http://example.com/knows']];
        $result = compactWith($expanded, $context);
        expect($result)->toHaveKey('knownBy');
        expect($result)->not->toHaveKey('@reverse');
        expect($result['knownBy'])->toBe(['@id' => 'http://example.com/b']);
    });

    it('selects a @type: @vocab term for a node ref that round-trips to a vocab term', function () {
        $expanded = [['http://example.org/term' => [['@id' => 'http://example.org/enum']]]];
        $context = [
            'term' => ['@id' => 'http://example.org/term', '@type' => '@vocab'],
            'doNotSelect' => ['@id' => 'http://example.org/term'],
            'enum' => ['@id' => 'http://example.org/enum'],
        ];
        $result = compactWith($expanded, $context);
        expect($result)->toHaveKey('term');
        expect($result['term'])->toBe('enum');
    });

    it('splits a property across @type:@vocab and @type:@id terms per value', function () {
        $expanded = [['http://example.com/vocab#foo' => [
            ['@id' => 'http://example.com/vocab#Bar'],
            ['@id' => 'http://example.com/vocab#Baz'],
        ]]];
        $context = [
            'Bar' => 'http://example.com/vocab#Bar',
            'fooI' => ['@id' => 'http://example.com/vocab#foo', '@type' => '@id'],
            'fooV' => ['@id' => 'http://example.com/vocab#foo', '@type' => '@vocab'],
        ];
        $result = compactWith($expanded, $context);
        expect($result['fooV'])->toBe('Bar');                          // Bar round-trips → @vocab
        expect($result['fooI'])->toBe('http://example.com/vocab#Baz'); // Baz does not → @id (full IRI)
    });

    it('selects a @type-coerced @list term for a uniformly-typed list', function () {
        $expanded = [['http://example.com/term' => [['@list' => [
            ['@value' => 'a', '@type' => 'http://example.com/t1'],
            ['@value' => 'b', '@type' => 'http://example.com/t1'],
        ]]]]];
        $context = [
            'type1' => 'http://example.com/t1',
            'plain' => ['@id' => 'http://example.com/term', '@container' => '@list'],
            'typed' => ['@id' => 'http://example.com/term', '@container' => '@list', '@type' => 'type1'],
        ];
        $result = compactWith($expanded, $context);
        expect($result['typed'])->toBe(['a', 'b']);
    });

    it('applies a property-scoped @context while compacting the value', function () {
        $expanded = [['http://example/foo' => [['http://example.org/bar' => [['@value' => 'baz']]]]]];
        $context = ['@vocab' => 'http://example/', 'foo' => ['@context' => ['bar' => 'http://example.org/bar']]];
        $result = compactWith($expanded, $context);
        expect($result['foo'])->toBe(['bar' => 'baz']);
    });

    it('honours a property-scoped term override (bar coerced to @id inside foo)', function () {
        $expanded = [['http://example/foo' => [['http://example/bar' => [['@id' => 'http://example/baz']]]]]];
        $context = ['@vocab' => 'http://example/', 'foo' => ['@context' => ['bar' => ['@type' => '@id']]]];
        $result = compactWith($expanded, $context);
        expect($result['foo'])->toBe(['bar' => 'http://example/baz']);
    });

    it('applies a type-scoped @context coercion to the typed node', function () {
        $expanded = [['@type' => ['http://example/Foo'], 'http://example/ref' => [['@id' => 'http://example/x']]]];
        $context = ['@vocab' => 'http://example/', 'Foo' => ['@context' => ['ref' => ['@type' => '@id']]]];
        $result = compactWith($expanded, $context);
        // Inside Foo, ref coerces to @id, so the node reference collapses to a bare IRI.
        expect($result['ref'])->toBe('http://example/x');
    });

    it('does not propagate a type-scoped @context into nested node objects', function () {
        // #tc009: a type-scoped @context affects the typed node, not nested nodes.
        $expanded = [[
            '@type' => ['http://example/Foo'],
            'http://example/bar' => [['http://example/baz' => [['@id' => 'http://example/buzz']]]],
        ]];
        $context = ['@vocab' => 'http://example/', 'Foo' => ['@context' => ['baz' => ['@type' => '@vocab']]]];
        $json = json_encode(compactWith($expanded, $context), JSON_UNESCAPED_SLASHES);
        // baz inside the nested node is NOT @vocab-coerced (so the ref stays {@id}).
        expect($json)->toContain('"@id":"http://example/buzz"');
    });

    it('relativises @id values against a property-scoped @base (#tc024)', function () {
        // bar coerces values to @id and carries a property-scoped @base, so the
        // absolute @id values relativise against it during compaction.
        $expanded = [[
            'http://example/bar' => [
                ['@id' => 'http://example/a'],
                ['@id' => 'http://example/b'],
            ],
        ]];
        $context = [
            '@vocab' => 'http://example/',
            'bar' => ['@id' => 'http://example/bar', '@type' => '@id', '@context' => ['@base' => 'http://example/']],
        ];
        $result = compactWith($expanded, $context);
        expect($result['bar'])->toBe(['a', 'b']);
    });

    it('does not @vocab-strip an IRI to a term name mapping elsewhere (#t0043)', function () {
        // "http://example.com/name" must NOT become "name" — the term "name"
        // maps to foaf/name, a different IRI — so the full IRI is used.
        $expanded = [['@id' => 'http://example.com/node', 'http://example.com/name' => [['@value' => 'Markus']]]];
        $context = ['@vocab' => 'http://example.com/', 'name' => 'http://xmlns.com/foaf/0.1/name'];
        $result = compactWith($expanded, $context);
        expect($result)->toHaveKey('http://example.com/name')
            ->and($result)->not->toHaveKey('name');
    });

    it('matches a @vocab-relative term @type and drops the coerced @type (#t0021)', function () {
        // "date" coerces to @vocab + "types/dateTime", which equals the value's
        // @type, so value compaction drops @type and yields the bare string.
        $expanded = [['http://example/date' => [['@value' => '2011', '@type' => 'http://example/types/dateTime']]]];
        $context = ['@vocab' => 'http://example/', 'date' => ['@type' => 'types/dateTime']];
        $result = compactWith($expanded, $context);
        expect($result['date'])->toBe('2011');
    });

    it('does not select a @type:@id term for a plain-string value (#t0006)', function () {
        // "ref" coerces to @id; a plain string is not a node reference, so the
        // term would be destructive — fall through to the full IRI.
        $expanded = [['@id' => 'http://example/node', 'http://example/ref' => [['@value' => 'not-an-iri']]]];
        $context = ['ref' => ['@id' => 'http://example/ref', '@type' => '@id']];
        $result = compactWith($expanded, $context);
        expect($result)->toHaveKey('http://example/ref')
            ->and($result)->not->toHaveKey('ref');
    });

    it('keys a property-valued @index map by the index property value (#tpi01)', function () {
        // author{@container:@index,@index:prop}: the map key is the value of the
        // "prop" property, which is then removed from the node.
        $expanded = [[
            'http://example/author' => [
                ['@id' => 'http://example/p1', 'http://example/prop' => [['@value' => 'regular']]],
                ['@id' => 'http://example/p2', 'http://example/prop' => [['@value' => 'guest']]],
            ],
        ]];
        $context = ['@vocab' => 'http://example/', 'author' => ['@type' => '@id', '@container' => '@index', '@index' => 'prop']];
        $result = compactWith($expanded, $context);
        expect($result['author'])->toBe([
            'regular' => ['@id' => 'http://example/p1'],
            'guest' => ['@id' => 'http://example/p2'],
        ]);
    });

    it('compacts a sole-@id node in a @type map to a bare string (#tm020)', function () {
        $expanded = [['http://example/foo' => [['@id' => 'http://example/baz', '@type' => ['http://example/bar']]]]];
        $context = ['@vocab' => 'http://example/', '@base' => 'http://example/', 'foo' => ['@container' => '@type']];
        $result = compactWith($expanded, $context);
        expect($result['foo'])->toBe(['bar' => 'baz']);
    });

    it('keeps a nested @list nested instead of flattening it (#tli01)', function () {
        $expanded = [['http://example/foo' => [['@list' => [['@list' => []]]]]]];
        $context = ['foo' => ['@id' => 'http://example/foo', '@container' => '@list']];
        $result = compactWith($expanded, $context);
        expect($result['foo'])->toBe([[]]);
    });
});
