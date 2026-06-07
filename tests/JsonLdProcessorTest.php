<?php

declare(strict_types=1);

use Accredify\JsonLd\Documents\ExpandedDocument;
use Accredify\JsonLd\JsonLdProcessor;
use Accredify\JsonLd\Tests\Context\Support\StubDocumentLoader;

describe('JsonLdProcessor::expand', function () {
    it('returns an ExpandedDocument', function () {
        $loader = new StubDocumentLoader;
        $processor = new JsonLdProcessor($loader);

        $result = $processor->expand([
            '@context' => ['name' => 'https://schema.org/name'],
            'name' => 'Alice',
        ]);

        // Expansion produces an array of node objects per JSON-LD 1.1.
        expect($result)->toBeInstanceOf(ExpandedDocument::class);
        $array = $result->toArray();
        $first = $array[0] ?? null;
        expect($first)->toBeArray();
        /** @var array<string, mixed> $first */
        expect($first)->toHaveKey('https://schema.org/name');
    });

    it('strips @context from the expanded output', function () {
        $loader = new StubDocumentLoader;
        $processor = new JsonLdProcessor($loader);

        $result = $processor->expand([
            '@context' => ['name' => 'https://schema.org/name'],
            'name' => 'Bob',
        ]);

        expect($result->toArray())->not->toHaveKey('@context');
    });

    it('tolerates a missing @context (expands against an empty active context)', function () {
        // A document without @context is valid: it expands against an empty
        // active context. Here "id" is unmapped (no term, no @vocab) so it
        // drops, leaving a free-floating node that is itself dropped → [].
        $loader = new StubDocumentLoader;
        $processor = new JsonLdProcessor($loader);

        expect($processor->expand(['id' => 'urn:1'])->toArray())->toBe([]);
    });

    it('passes the injected DocumentLoader through to ContextProcessor', function () {
        $loader = new StubDocumentLoader;
        $loader->add('https://example.com/ctx', [
            '@context' => ['name' => 'https://schema.org/name'],
        ]);

        $processor = new JsonLdProcessor($loader);
        $processor->expand([
            '@context' => 'https://example.com/ctx',
            'name' => 'X',
        ]);

        expect($loader->requestedUrls)->toBe(['https://example.com/ctx']);
    });
});
