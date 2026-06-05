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
});
