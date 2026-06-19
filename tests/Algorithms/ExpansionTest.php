<?php

declare(strict_types=1);

use Accredify\JsonLd\Algorithms\Expansion;
use Accredify\JsonLd\Context\ContextProcessor;
use Accredify\JsonLd\Exceptions\JsonLdException;
use Accredify\JsonLd\JsonLdProcessor;
use Accredify\JsonLd\Tests\Context\Support\StubDocumentLoader;

/*
|--------------------------------------------------------------------------
| Smoke tests for Expansion
|--------------------------------------------------------------------------
| Full characterization tests (snapshotting current quirks against VC's
| sample fixtures) land in PR 2.8. These tests cover the happy path so
| that Phase 4 refactors don't accidentally break basic wiring.
*/

/**
 * @param  array<string, mixed>  $contextDoc
 * @param  array<string, array<mixed>>  $loaderMap
 */
function makeExpansion(array $contextDoc, array $loaderMap = []): Expansion
{
    $loader = new StubDocumentLoader;
    foreach ($loaderMap as $url => $document) {
        $loader->add($url, $document);
    }
    $processor = new ContextProcessor($contextDoc, $loader);

    return new Expansion($processor->getTermDefinitions());
}

describe('Expansion::expand', function () {
    it('wraps a single-object result in an outer array', function () {
        $expander = makeExpansion([
            '@context' => [
                'name' => 'https://schema.org/name',
            ],
            'name' => 'Alice',
        ]);

        $expanded = $expander->expand(['name' => 'Alice']);

        // Per JSON-LD 1.1, expansion always produces a list of node objects.
        expect($expanded)->toBeArray();
        $first = $expanded[0] ?? null;
        expect($first)->toBeArray();
        /** @var array<string, mixed> $first */
        expect($first)->toHaveKey('https://schema.org/name');
        expect($first['https://schema.org/name'])->toBe([['@value' => 'Alice']]);
    });

    it('strips @context from the output', function () {
        $expander = makeExpansion([
            '@context' => [
                'name' => 'https://schema.org/name',
            ],
            'name' => 'Bob',
        ]);

        $expanded = $expander->expand([
            '@context' => ['name' => 'https://schema.org/name'],
            'name' => 'Bob',
        ]);

        $first = $expanded[0] ?? null;
        expect($first)->toBeArray();
        /** @var array<string, mixed> $first */
        expect($first)->not->toHaveKey('@context');
    });

    it('expands @type values when the context aliases type → @type', function () {
        $expander = makeExpansion([
            '@context' => [
                'type' => '@type',
                'name' => 'https://schema.org/name',
                'Person' => 'https://schema.org/Person',
            ],
            'name' => 'X',
        ]);

        $expanded = $expander->expand([
            'type' => 'Person',
            'name' => 'X',
        ]);

        $first = $expanded[0] ?? null;
        expect($first)->toBeArray();
        /** @var array<string, mixed> $first */
        expect($first['@type'])->toBe(['https://schema.org/Person']);
    });

    it('expands @id when the context aliases id → @id', function () {
        $expander = makeExpansion([
            '@context' => [
                'id' => '@id',
                'name' => 'https://schema.org/name',
            ],
            'name' => 'X',
        ]);

        $expanded = $expander->expand([
            'id' => 'urn:thing:1',
            'name' => 'X',
        ]);

        $first = $expanded[0] ?? null;
        expect($first)->toBeArray();
        /** @var array<string, mixed> $first */
        expect($first['@id'])->toBe('urn:thing:1');
    });

    it('returns an empty array for a fully-empty input', function () {
        $expander = makeExpansion([
            '@context' => ['name' => 'https://schema.org/name'],
            'name' => 'X',
        ]);

        expect($expander->expand([]))->toBe([]);
    });

    it('applies context default @language and @direction to plain strings', function () {
        $expander = makeExpansion([
            '@context' => [
                '@language' => 'en',
                '@direction' => 'rtl',
                'name' => 'https://schema.org/name',
            ],
            'name' => 'X',
        ]);

        $first = $expander->expand(['name' => 'Alice'])[0] ?? null;
        expect($first)->toBeArray();
        /** @var array<string, mixed> $first */
        expect($first['https://schema.org/name'])
            ->toBe([['@direction' => 'rtl', '@language' => 'en', '@value' => 'Alice']]);
    });

    it('lets a term @language (incl. null) override the default', function () {
        $expander = makeExpansion([
            '@context' => [
                '@language' => 'en',
                'de' => ['@id' => 'https://example.com/de', '@language' => 'de'],
                'none' => ['@id' => 'https://example.com/none', '@language' => null],
            ],
            'de' => 'x',
        ]);

        $first = $expander->expand(['de' => 'hallo', 'none' => 'plain'])[0] ?? null;
        expect($first)->toBeArray();
        /** @var array<string, mixed> $first */
        // term @language wins over the default
        expect($first['https://example.com/de'])->toBe([['@language' => 'de', '@value' => 'hallo']]);
        // term @language: null suppresses the default entirely
        expect($first['https://example.com/none'])->toBe([['@value' => 'plain']]);
    });

    it('does not tag non-string values with @language/@direction', function () {
        $expander = makeExpansion([
            '@context' => ['@language' => 'en', 'n' => 'https://example.com/n'],
            'n' => 1,
        ]);

        $first = $expander->expand(['n' => 42])[0] ?? null;
        expect($first)->toBeArray();
        /** @var array<string, mixed> $first */
        expect($first['https://example.com/n'])->toBe([['@value' => 42]]);
    });

    it('drops unmapped relative terms that do not expand to an absolute IRI', function () {
        // §5.5 step 13: a property key that, after IRI expansion, is neither a
        // keyword nor contains a colon is an unmapped relative term and MUST be
        // dropped — not emitted with a relative predicate. (Regression guard for
        // the VC drop-in: relative predicates would otherwise leak into toRdf.)
        $expander = makeExpansion([
            '@context' => ['known' => 'http://example.com/known'],
        ]);

        $first = $expander->expand([
            '@id' => 'urn:subject:1',
            'relativeProp' => 'should drop',
            'known' => 'should stay',
        ])[0] ?? null;

        expect($first)->toBeArray();
        /** @var array<string, mixed> $first */
        expect($first)->toBe([
            '@id' => 'urn:subject:1',
            'http://example.com/known' => [['@value' => 'should stay']],
        ]);
        expect($first)->not->toHaveKey('relativeProp');
    });
});

describe('scoped context propagation', function () {
    $expand = function (array $doc): array {
        return (new JsonLdProcessor(new StubDocumentLoader))->expand($doc)->toArray();
    };

    it('does not propagate a type-scoped context into nested node objects', function () use ($expand) {
        // `inner` is defined only inside Outer's type-scoped @context, so it
        // resolves under Outer but NOT inside the nested node (which falls
        // back to @vocab).
        $json = json_encode($expand([
            '@context' => [
                '@version' => 1.1,
                '@vocab' => 'http://example.com/',
                'Outer' => ['@id' => 'http://example.com/Outer', '@context' => ['inner' => 'http://example.com/scoped-inner']],
            ],
            '@type' => 'Outer',
            'inner' => ['inner' => 'x'],
        ]), JSON_UNESCAPED_SLASHES);

        expect($json)->toContain('http://example.com/scoped-inner');
        expect($json)->toContain('http://example.com/inner');
    });

    it('propagates a property-scoped context into nested node objects', function () use ($expand) {
        // q is defined by p's property-scoped context and must apply deep inside p.
        $json = json_encode($expand([
            '@context' => [
                '@version' => 1.1,
                '@vocab' => 'http://example.com/',
                'p' => ['@id' => 'http://example.com/p', '@context' => ['q' => 'http://example.com/scoped-q']],
            ],
            'p' => ['p' => ['q' => 'deep']],
        ]), JSON_UNESCAPED_SLASHES);

        expect($json)->toContain('http://example.com/scoped-q');
    });

    it('rejects redefining a protected term in an embedded node context', function () use ($expand) {
        expect(fn () => $expand([
            '@context' => ['@version' => 1.1, '@protected' => true, '@vocab' => 'http://example.com/', 'name' => 'http://example.com/name'],
            'thing' => ['@context' => ['name' => 'http://example.com/other'], 'name' => 'x'],
        ]))->toThrow(JsonLdException::class, 'Protected term redefinition');
    });

    it('rejects redefining a protected @type keyword differently across layers (#tpr32)', function () use ($expand) {
        // Layer 1 protects @type as {@container:@set}; layer 2 redefines it
        // without @container — a differing redefinition of a protected keyword.
        expect(fn () => $expand([
            '@context' => [
                [
                    '@version' => 1.1,
                    'id' => ['@id' => '@id', '@protected' => true],
                    'type' => ['@id' => '@type', '@container' => '@set', '@protected' => true],
                    '@type' => ['@container' => '@set', '@protected' => true],
                ],
                ['@version' => 1.1, '@type' => ['@protected' => true]],
            ],
            'id' => 'http://example.com/1',
            'type' => ['http://example.org/ns/Foo'],
        ]))->toThrow(JsonLdException::class, 'Protected term redefinition');
    });
});

describe('@graph and map container expansion', function () {
    $expand = function (array $doc): array {
        return (new JsonLdProcessor(new StubDocumentLoader))->expand($doc)->toArray();
    };

    it('wraps each element of a plain @graph container in its own graph object', function () use ($expand) {
        // Two objects → two SEPARATE {@graph:[…]} objects (not one shared graph).
        $json = json_encode($expand([
            '@context' => ['@version' => 1.1, '@vocab' => 'http://example.org/', 'input' => ['@container' => '@graph']],
            'input' => [['value' => 'x'], ['value' => 'y']],
        ]), JSON_UNESCAPED_SLASHES);
        expect(substr_count((string) $json, '"@graph"'))->toBe(2);
    });

    it('combines [@graph, @index]: each entry carries @index alongside @graph', function () use ($expand) {
        $json = json_encode($expand([
            '@context' => ['@version' => 1.1, '@vocab' => 'http://example.org/', 'input' => ['@container' => ['@graph', '@index']]],
            'input' => ['g1' => ['value' => 'x']],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"@index":"g1"');
        expect($json)->toContain('"@graph"');
    });

    it('combines [@graph, @id]: each entry carries the expanded @id alongside @graph', function () use ($expand) {
        $json = json_encode($expand([
            '@context' => ['@version' => 1.1, '@vocab' => 'http://example.org/', 'input' => ['@container' => ['@graph', '@id']]],
            'input' => ['http://example.org/g1' => ['value' => 'x']],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"@id":"http://example.org/g1"');
        expect($json)->toContain('"@graph"');
    });

    it('drops the @id for a literal @none key in an @id map', function () use ($expand) {
        $json = json_encode($expand([
            '@context' => ['@version' => 1.1, '@vocab' => 'http://example.org/', 'input' => ['@container' => '@id']],
            'input' => ['@none' => ['value' => 'x']],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->not->toContain('"@id":"@none"');
        expect($json)->toContain('http://example.org/value');
    });

    it('drops the @id for an aliased @none key in an @id map', function () use ($expand) {
        // "none" is a term aliasing @none; the id map must not attach @id:@none.
        $json = json_encode($expand([
            '@context' => ['@version' => 1.1, '@vocab' => 'http://example.org/', 'none' => '@none', 'input' => ['@container' => '@id']],
            'input' => ['none' => ['value' => 'x']],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->not->toContain('@none');
    });
});

describe('expansion validation gates', function () {
    $expand = function (array $doc): array {
        return (new JsonLdProcessor(new StubDocumentLoader))->expand($doc)->toArray();
    };

    it('rejects a @reverse map whose key is a keyword (#ter25)', function () use ($expand) {
        expect(fn () => $expand(['@id' => 'http://example/foo', '@reverse' => ['@id' => 'http://example/bar']]))
            ->toThrow(JsonLdException::class, 'Invalid reverse property map');
    });

    it('rejects a @list object carrying another key, e.g. @id (#ter41)', function () use ($expand) {
        expect(fn () => $expand(['http://example/prop' => ['@list' => ['foo'], '@id' => 'http://example/bar']]))
            ->toThrow(JsonLdException::class, 'Invalid set or list object');
    });

    it('rejects a type-scoped null context that would clear protected terms (#tpr17)', function () use ($expand) {
        expect(fn () => $expand([
            '@context' => [
                '@version' => 1.1,
                '@protected' => true,
                'p' => 'http://example/p',
                'P' => ['@id' => 'http://example/P', '@context' => [null]],
            ],
            '@type' => 'P',
            'p' => 'x',
        ]))->toThrow(JsonLdException::class, 'nullification');
    });

    it('expands @type:@none values to plain value objects without a @type (#ttn02)', function () use ($expand) {
        $json = json_encode($expand([
            '@context' => ['@version' => 1.1, 'notype' => ['@id' => 'http://example/notype', '@type' => '@none']],
            'notype' => 'plain',
        ]), JSON_UNESCAPED_SLASHES);
        // The value is a bare {@value} object — the @none coercion is dropped.
        expect($json)->toContain('"@value":"plain"')
            ->and($json)->not->toContain('@none');
    });

    it('resets @vocab to null in a nested context, dropping unmapped terms (#t0059)', function () use ($expand) {
        $json = json_encode($expand([
            '@context' => ['@vocab' => 'http://example.org/'],
            'outer' => 'kept',
            'embed' => ['@context' => ['@vocab' => null], 'inner' => 'dropped'],
        ]), JSON_UNESCAPED_SLASHES);
        // "outer"/"embed" expand via the outer @vocab; inside embed the @vocab
        // is reset, so the unmapped "inner" term is dropped (not inherited).
        expect($json)->toContain('http://example.org/outer')
            ->and($json)->toContain('http://example.org/embed')
            ->and($json)->not->toContain('inner');
    });

    it('preserves a @json value object whose @value is null (#tjs22)', function () use ($expand) {
        // JSON null is a legitimate @json literal value — it must NOT be
        // dropped like a null value of an ordinary (non-@json) value object.
        $json = json_encode($expand([
            'http://example/p' => ['@value' => null, '@type' => '@json'],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"@value":null')
            ->and($json)->toContain('"@type":"@json"');
    });

    it('expands @type values against the pre-type-scoped context (#tc016)', function () use ($expand) {
        // @type "Type" expands via the OUTER @vocab, not Type's own scoped
        // @vocab — a type cannot rename itself via the context it introduces.
        $json = json_encode($expand([
            '@context' => ['@version' => 1.1, '@vocab' => 'http://example.org/', 'Type' => ['@context' => ['@vocab' => 'http://example.com/']]],
            '@type' => 'Type',
            'foo' => 'com',
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"http://example.org/Type"')   // @type via outer vocab
            ->and($json)->toContain('http://example.com/foo');  // properties via scoped vocab
    });

    it('keeps a type-scoped value:@value mapping active for the typed node values (#tc020)', function () use ($expand) {
        // The nested value {value:"val"} has a key expanding to @value under the
        // type-scoped context, so it stays a value object instead of reverting.
        $json = json_encode($expand([
            '@context' => ['@version' => 1.1, '@vocab' => 'http://example/', 'type' => '@type', 'Type' => ['@context' => ['value' => '@value']]],
            'type' => 'Type',
            'v' => ['value' => 'val'],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"@value":"val"');
    });

    it('applies a type-scoped @base to @id, not propagating into nested nodes (#tc015)', function () use ($expand) {
        $json = json_encode($expand([
            '@context' => ['@version' => 1.1, '@base' => 'http://example/base-base', '@vocab' => 'http://example/', 'Type' => ['@context' => ['@base' => 'http://example/typed-base']]],
            '@id' => '#root',
            'p' => ['@id' => '#typed', '@type' => 'Type', 'nestedNode' => ['@id' => '#nested', 'foo' => 'bar']],
        ]), JSON_UNESCAPED_SLASHES);
        // The typed node's own @id uses the type-scoped base; the nested node
        // object (not a bare @id ref) reverts to the outer document base.
        expect($json)->toContain('http://example/typed-base#typed')
            ->and($json)->toContain('http://example/base-base#nested');
    });

    it('materialises a bare term @id from @vocab at definition time (#tc010)', function () use ($expand) {
        // "B" (defined with only an @context) fixes its IRI to example/B when
        // defined; a later embedded @vocab does not retroactively rewrite it.
        $json = json_encode($expand([
            '@context' => ['@vocab' => 'http://example/', 'B' => ['@context' => ['c' => 'http://example.org/c']]],
            'a' => ['@context' => ['@vocab' => 'http://example.com/'], '@type' => 'B', 'c' => 'C'],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"http://example/B"')
            ->and($json)->not->toContain('http://example.com/B');
    });

    it('applies the active @direction to @language-map values (#tdi04)', function () use ($expand) {
        $json = json_encode($expand([
            '@context' => ['@version' => 1.1, '@direction' => 'ltr', 'vocab' => 'http://example.com/vocab/', 'label' => ['@id' => 'vocab:label', '@container' => '@language']],
            '@id' => 'http://example.com/queen',
            'label' => ['en' => 'The Queen'],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"@direction":"ltr"')
            ->and($json)->toContain('"@language":"en"');
    });

    it('lets a term @direction:null suppress the default direction in a @language map (#tdi06)', function () use ($expand) {
        $json = json_encode($expand([
            '@context' => ['@version' => 1.1, '@direction' => 'ltr', 'vocab' => 'http://example.com/vocab/', 'label' => ['@id' => 'vocab:label', '@container' => '@language', '@direction' => null]],
            '@id' => 'http://example.com/queen',
            'label' => ['en' => 'The Queen'],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->not->toContain('@direction');
    });

    it('emits container-map entries in lexicographic key order, not input order (#tm/#tdi/#t0030)', function () use ($expand) {
        // @language map given en-before-de must expand de-before-en (code-point order).
        $json = (string) json_encode($expand([
            '@context' => ['@version' => 1.1, 'label' => ['@id' => 'http://example/label', '@container' => '@language']],
            'label' => ['en' => 'E', 'de' => 'D'],
        ]), JSON_UNESCAPED_SLASHES);
        $d = strpos($json, '"D"');
        $e = strpos($json, '"E"');
        expect(is_int($d) && is_int($e) && $d < $e)->toBeTrue();
    });

    it('merges @nest values after base properties, preserving [base, nested] order (#tn003)', function () use ($expand) {
        // "p" is contributed by both the base node and the nested object; the
        // base value must precede the nested one.
        $json = (string) json_encode($expand([
            '@context' => ['@version' => 1.1, '@vocab' => 'http://example/', 'nest' => '@nest'],
            'p' => 'base',
            'nest' => ['p' => 'nested'],
        ]), JSON_UNESCAPED_SLASHES);
        $base = strpos($json, '"base"');
        $nested = strpos($json, '"nested"');
        expect(is_int($base) && is_int($nested) && $base < $nested)->toBeTrue();
    });

    it('drops free-floating values, @id-only nodes and lists at the top level (#t0045/#t0046/#t0047)', function () use ($expand) {
        expect($expand(['@value' => 'free']))->toBe([]);
        expect($expand(['@graph' => [['@id' => 'http://example/x'], ['@value' => 'v']]]))->toBe([]);
        // A node WITH properties survives.
        $kept = $expand(['@graph' => [['@id' => 'http://example/n', 'http://example/p' => 'v']]]);
        expect($kept)->toHaveCount(1);
    });

    it('ignores a keyword-shaped term @id, falling back to @vocab (#t0120)', function () use ($expand) {
        $json = json_encode($expand([
            '@context' => ['@version' => 1.1, '@vocab' => 'http://example/', 'ignoreMe' => ['@id' => '@ignoreMe']],
            'ignoreMe' => 'x',
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('http://example/ignoreMe')
            ->and($json)->not->toContain('@ignoreMe');
    });

    it('wraps a scalar value of a @container:@list property in a @list object (#t0004)', function () use ($expand) {
        $json = json_encode($expand([
            '@context' => ['list' => ['@id' => 'http://example/list', '@container' => '@list']],
            'list' => 'one item',
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"@list":[{"@value":"one item"}]');
    });

    it('keeps an @id-map entry\'s own @id rather than the map key (#tm002)', function () use ($expand) {
        $json = (string) json_encode($expand([
            '@context' => ['@vocab' => 'http://example/', 'idmap' => ['@container' => '@id']],
            'idmap' => ['http://example.org/foo' => ['@id' => 'http://example.org/bar', 'label' => 'x']],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"@id":"http://example.org/bar"')
            ->and($json)->not->toContain('"@id":"http://example.org/foo"');
    });

    it('expands a @type-map string entry to a document-relative node reference (#tm017)', function () use ($expand) {
        $json = (string) json_encode($expand([
            '@context' => ['@version' => 1.1, '@vocab' => 'http://example.org/ns/', '@base' => 'http://example.org/base/', 'foo' => ['@container' => '@type']],
            'foo' => ['bar' => 'baz'],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"@id":"http://example.org/base/baz"');
    });

    it('expands a @type-map string entry against @vocab when the term is @type:@vocab (#tm019)', function () use ($expand) {
        $json = (string) json_encode($expand([
            '@context' => ['@version' => 1.1, '@vocab' => 'http://example.org/ns/', '@base' => 'http://example.org/base/', 'foo' => ['@type' => '@vocab', '@container' => '@type']],
            'foo' => ['bar' => 'baz'],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"@id":"http://example.org/ns/baz"');
    });

    it('applies the type key\'s type-scoped @context when expanding a @type-map entry (#tm008)', function () use ($expand) {
        $json = (string) json_encode($expand([
            '@context' => ['@vocab' => 'http://example/', 'typemap' => ['@container' => '@type'], 'Type' => ['@context' => ['a' => 'http://example.org/a']]],
            'typemap' => ['Type' => ['a' => 'v']],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('http://example.org/a');
    });

    it('keeps an @index-map entry\'s own @index rather than the map key (#t0036)', function () use ($expand) {
        $json = (string) json_encode($expand([
            '@context' => ['c' => ['@id' => 'http://example/c', '@container' => '@index']],
            'c' => ['A' => ['@id' => 'http://example/n', '@index' => 'own']],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"@index":"own"')
            ->and($json)->not->toContain('"@index":"A"');
    });

    it('resolves @vocab whose value is itself a defined term (#t0125)', function () use ($expand) {
        $json = (string) json_encode($expand([
            '@context' => [['@version' => 1.1, 'ex' => ['@id' => 'http://example.org/', '@prefix' => true]], ['@vocab' => 'ex']],
            'foo' => 'bar',
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('http://example.org/foo');
    });

    it('does not expand a compact IRI when the prefix term is @prefix:false (#tpr29)', function () use ($expand) {
        $json = (string) json_encode($expand([
            '@context' => ['@version' => 1.1, 'tag' => ['@id' => 'http://example.org/ns/tag/', '@prefix' => false]],
            'tag:champin.net,2019:prop' => 'kept literal',
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('"tag:champin.net,2019:prop"');
    });

    it('ignores a term whose @reverse has the form of a keyword, falling back to @vocab (#tpr39)', function () use ($expand) {
        $json = (string) json_encode($expand([
            '@context' => ['@vocab' => 'http://example.org/', 'ignoreMe' => ['@reverse' => '@ignoreMe']],
            'ignoreMe' => ['text' => 'not reversed'],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('http://example.org/ignoreMe')
            ->and($json)->not->toContain('@reverse');
    });

    it('accumulates @type from a literal @type and a type alias in lexicographic key order (#tpr30)', function () use ($expand) {
        // "@type" (0x40) sorts before "type" (0x74), so its value (Bar) precedes
        // the alias value (Foo) regardless of document order.
        $json = (string) json_encode($expand([
            '@context' => ['@version' => 1.1, 'type' => ['@id' => '@type', '@container' => '@set']],
            'type' => 'http://example.org/ns/Foo',
            '@type' => 'http://example.org/ns/Bar',
        ]), JSON_UNESCAPED_SLASHES);
        $bar = strpos($json, 'Bar');
        $foo = strpos($json, 'Foo');
        expect(is_int($bar) && is_int($foo) && $bar < $foo)->toBeTrue();
    });

    it('appends @nest-alias values in lexicographic alias order, after base values (#tn004)', function () use ($expand) {
        // nest aliases given nest2-before-nest1 must contribute nest1 before
        // nest2 (lexicographic), and both after the base property value.
        $json = (string) json_encode($expand([
            '@context' => ['@vocab' => 'http://example.org/', 'nest1' => '@nest', 'nest2' => '@nest'],
            'p2' => 'v2',
            'nest2' => ['p2' => 'v4'],
            'nest1' => ['p2' => 'v3'],
        ]), JSON_UNESCAPED_SLASHES);
        $v2 = strpos($json, '"v2"');
        $v3 = strpos($json, '"v3"');
        $v4 = strpos($json, '"v4"');
        expect(is_int($v2) && is_int($v3) && is_int($v4) && $v2 < $v3 && $v3 < $v4)->toBeTrue();
    });

    it('attaches a property-valued @index to a @graph-wrapped entry (#tpi11)', function () use ($expand) {
        $json = (string) json_encode($expand([
            '@context' => ['@version' => 1.1, '@vocab' => 'http://example.org/', 'input' => ['@container' => ['@graph', '@index'], '@index' => 'prop']],
            'input' => ['g1' => ['value' => 'x']],
        ]), JSON_UNESCAPED_SLASHES);
        expect($json)->toContain('http://example.org/prop')
            ->and($json)->toContain('"@value":"g1"')
            ->and($json)->not->toContain('"@index":"g1"');
    });

    it('expands a reverse-property term that also has @container:@index (#t0063)', function () use ($expand) {
        $json = (string) json_encode($expand([
            '@context' => ['name' => 'http://xmlns.com/foaf/0.1/name', 'isKnownBy' => ['@reverse' => 'http://xmlns.com/foaf/0.1/knows', '@container' => '@index']],
            '@id' => 'http://example.com/markus',
            'isKnownBy' => ['Dave' => ['@id' => 'http://example.com/dave'], 'Gregg' => ['@id' => 'http://example.com/gregg']],
        ]), JSON_UNESCAPED_SLASHES);
        // The index-map entries become reverse values with @index attached.
        expect($json)->toContain('"@reverse"')
            ->and($json)->toContain('"@index":"Dave"')
            ->and($json)->toContain('"@index":"Gregg"')
            ->and($json)->toContain('http://example.com/dave');
    });
});

describe('@nest and scoped-null expansion (#tin06)', function () {
    $expand = function (array $doc): array {
        return (new JsonLdProcessor(new StubDocumentLoader))->expand($doc)->toArray();
    };

    it('keeps @id a scalar when an id alias appears inside an @nest block', function () use ($expand) {
        $first = $expand([
            '@context' => ['@version' => 1.1, '@vocab' => 'http://ex/', 'id' => '@id', 'nest' => '@nest'],
            'nest' => ['id' => 'http://ex/1', 'foo' => 'bar'],
        ])[0] ?? null;

        expect($first)->toBe([
            '@id' => 'http://ex/1',
            'http://ex/foo' => [['@value' => 'bar']],
        ]);
    });

    it('nullifies an inherited @nest term via a property-scoped term:null', function () use ($expand) {
        // `data` is @nest globally, but the `comments` term nullifies it, so the
        // `data` key drops rather than unwrapping its {id, type} as @id/@type.
        $first = $expand([
            '@context' => [
                '@version' => 1.1, '@vocab' => 'http://ex/', 'id' => '@id', 'type' => '@type',
                'data' => '@nest', 'comments' => ['@context' => ['data' => null]],
            ],
            'comments' => ['links' => 'L', 'data' => ['id' => 'http://ex/5', 'type' => 'C']],
        ])[0] ?? null;

        expect($first)->toBe([
            'http://ex/comments' => [['http://ex/links' => [['@value' => 'L']]]],
        ]);
    });
});
