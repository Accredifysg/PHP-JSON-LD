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
    it('expands a simple typed term value', function () {
        $expander = makeExpansion([
            '@context' => [
                'name' => 'https://schema.org/name',
            ],
            'name' => 'Alice',
        ]);

        $expanded = $expander->expand([
            'name' => 'Alice',
        ]);

        // The exact shape is locked by VC's expander; this is the smoke test.
        expect($expanded)->toHaveKey('https://schema.org/name');
        expect($expanded['https://schema.org/name'])->toBe([['@value' => 'Alice']]);
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

        expect($expanded)->not->toHaveKey('@context');
    });

    it('expands @type values', function () {
        $expander = makeExpansion([
            '@context' => [
                'name' => 'https://schema.org/name',
                'Person' => 'https://schema.org/Person',
            ],
            'name' => 'X',
        ]);

        $expanded = $expander->expand([
            'type' => 'Person',
            'name' => 'X',
        ]);

        expect($expanded['@type'])->toBe(['https://schema.org/Person']);
    });

    it('expands @id values via the id term alias', function () {
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

        expect($expanded['@id'])->toBe('urn:thing:1');
    });

    it('returns an empty array for a fully-empty input', function () {
        $expander = makeExpansion([
            '@context' => ['name' => 'https://schema.org/name'],
            'name' => 'X',
        ]);

        expect($expander->expand([]))->toBe([]);
    });
});
