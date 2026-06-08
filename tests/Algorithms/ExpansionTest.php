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
