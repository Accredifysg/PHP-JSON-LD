<?php

declare(strict_types=1);

use Accredify\JsonLd\Algorithms\Expansion;
use Accredify\JsonLd\Context\ContextProcessor;
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
