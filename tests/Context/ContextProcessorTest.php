<?php

declare(strict_types=1);

use Accredify\JsonLd\Context\ContextProcessor;
use Accredify\JsonLd\Context\TermDefinitions;
use Accredify\JsonLd\Exceptions\JsonLdException;
use Accredify\JsonLd\Tests\Context\Support\StubDocumentLoader;

describe('ContextProcessor::__construct', function () {
    it('throws when @context is missing', function () {
        $loader = new StubDocumentLoader;

        expect(fn () => new ContextProcessor(['id' => 'urn:1'], $loader))
            ->toThrow(JsonLdException::class, 'Invalid JSON-LD: Missing @context');
    });

    it('processes a string @context by delegating to the document loader', function () {
        $loader = new StubDocumentLoader;
        $loader->add('https://example.com/ctx.jsonld', [
            '@context' => ['name' => 'https://schema.org/name'],
        ]);

        $processor = new ContextProcessor(
            ['@context' => 'https://example.com/ctx.jsonld', 'id' => 'urn:1'],
            $loader,
        );

        expect($loader->requestedUrls)->toBe(['https://example.com/ctx.jsonld']);
        expect($processor->getTermDefinitions()->getTermDefinition('name'))
            ->toBe(['@id' => 'https://schema.org/name']);
    });

    it('processes an array @context (list of URLs)', function () {
        $loader = new StubDocumentLoader;
        $loader->add('https://example.com/a', ['@context' => ['a' => 'https://example.com/A']]);
        $loader->add('https://example.com/b', ['@context' => ['b' => 'https://example.com/B']]);

        $processor = new ContextProcessor(
            ['@context' => ['https://example.com/a', 'https://example.com/b'], 'id' => 'urn:1'],
            $loader,
        );

        expect($loader->requestedUrls)->toBe(['https://example.com/a', 'https://example.com/b']);

        $defs = $processor->getTermDefinitions();
        expect($defs->getTermDefinition('a'))->toBe(['@id' => 'https://example.com/A']);
        expect($defs->getTermDefinition('b'))->toBe(['@id' => 'https://example.com/B']);
    });

    it('processes an inline associative-array @context without hitting the loader', function () {
        $loader = new StubDocumentLoader;

        $processor = new ContextProcessor(
            ['@context' => ['name' => 'https://schema.org/name'], 'id' => 'urn:1'],
            $loader,
        );

        expect($loader->requestedUrls)->toBe([]);
        expect($processor->getTermDefinitions()->getTermDefinition('name'))
            ->toBe(['@id' => 'https://schema.org/name']);
    });

    it('accepts a remote document whose body is a flat term map (no @context wrapper)', function () {
        $loader = new StubDocumentLoader;
        // Some servers return the raw map rather than wrapping in @context.
        $loader->add('https://example.com/ctx', ['name' => 'https://schema.org/name']);

        $processor = new ContextProcessor(
            ['@context' => 'https://example.com/ctx', 'id' => 'urn:1'],
            $loader,
        );

        expect($processor->getTermDefinitions()->getTermDefinition('name'))
            ->toBe(['@id' => 'https://schema.org/name']);
    });

    it('descends into nested @context in a remote document', function () {
        $loader = new StubDocumentLoader;
        $loader->add('https://example.com/outer', [
            '@context' => [
                'outer' => 'https://example.com/Outer',
                '@context' => 'https://example.com/inner',
            ],
        ]);
        $loader->add('https://example.com/inner', [
            '@context' => ['inner' => 'https://example.com/Inner'],
        ]);

        $processor = new ContextProcessor(
            ['@context' => 'https://example.com/outer'],
            $loader,
        );

        expect($loader->requestedUrls)->toBe(['https://example.com/outer', 'https://example.com/inner']);
        expect($processor->getTermDefinitions()->getTermDefinition('inner'))
            ->toBe(['@id' => 'https://example.com/Inner']);
    });

    it('throws on circular references', function () {
        $loader = new StubDocumentLoader;
        $loader->add('https://example.com/a', [
            '@context' => ['@context' => 'https://example.com/b'],
        ]);
        $loader->add('https://example.com/b', [
            '@context' => ['@context' => 'https://example.com/a'], // back to a
        ]);

        expect(fn () => new ContextProcessor(
            ['@context' => 'https://example.com/a'],
            $loader,
        ))->toThrow(JsonLdException::class, 'Circular reference detected: https://example.com/a');
    });

    it('throws when a remote context URL is not a valid URL', function () {
        $loader = new StubDocumentLoader;

        expect(fn () => new ContextProcessor(
            ['@context' => 'not-a-url'],
            $loader,
        ))->toThrow(JsonLdException::class, 'Remote context must be a valid URL');
    });

    it('throws when the document loader fails', function () {
        $loader = new StubDocumentLoader; // no stub registered

        expect(fn () => new ContextProcessor(
            ['@context' => 'https://example.com/missing.jsonld'],
            $loader,
        ))->toThrow(JsonLdException::class, 'Failed to load context from https://example.com/missing.jsonld');
    });

    it('wraps a non-string non-array @context as an error', function () {
        $loader = new StubDocumentLoader;

        expect(fn () => new ContextProcessor(
            ['@context' => 123],
            $loader,
        ))->toThrow(JsonLdException::class, 'Context must be string or array');
    });
});

describe('ContextProcessor keyword validation', function () {
    /**
     * @param  array<string, mixed>  $context
     */
    function process(array $context): ContextProcessor
    {
        return new ContextProcessor(['@context' => $context], new StubDocumentLoader);
    }

    it('accepts @version 1.1 and rejects 1.0', function () {
        // JSON-LD 1.1 is the only supported processing mode.
        expect(process(['@version' => 1.1])->getTermDefinitions())->toBeInstanceOf(TermDefinitions::class);
        expect(fn () => process(['@version' => 1.0]))
            ->toThrow(JsonLdException::class, 'Invalid @version value');
    });

    it('rejects unknown @version', function () {
        expect(fn () => process(['@version' => 2.0]))
            ->toThrow(JsonLdException::class, 'Invalid @version value');
    });

    it('accepts URL or null @base / @vocab', function () {
        expect(process(['@base' => 'https://example.com/'])->getTermDefinitions())
            ->toBeInstanceOf(TermDefinitions::class);
        expect(process(['@vocab' => 'https://example.com/v#'])->getTermDefinitions())
            ->toBeInstanceOf(TermDefinitions::class);
        expect(process(['@base' => null])->getTermDefinitions())
            ->toBeInstanceOf(TermDefinitions::class);
    });

    it('accepts relative @base (resolved against the document base)', function () {
        // As of v0.7.0, @base accepts relative/compact/empty strings — they
        // are resolved against the active base during context merge.
        expect(process(['@base' => '../relative/'])->getTermDefinitions())
            ->toBeInstanceOf(TermDefinitions::class);
        expect(process(['@base' => ''])->getTermDefinitions())
            ->toBeInstanceOf(TermDefinitions::class);
    });

    it('accepts a relative / compact @vocab (resolved during merge)', function () {
        // @vocab may be a relative reference, compact IRI, blank node, or
        // empty string — all resolved at merge time. Only a non-string is
        // invalid.
        expect(process(['@vocab' => 'not-a-url'])->getTermDefinitions())
            ->toBeInstanceOf(TermDefinitions::class);
    });

    it('rejects a non-string @vocab', function () {
        expect(fn () => process(['@vocab' => 42]))
            ->toThrow(JsonLdException::class, 'Invalid @vocab value');
    });

    it('accepts BCP-47 @language', function () {
        expect(process(['@language' => 'en'])->getTermDefinitions())->toBeInstanceOf(TermDefinitions::class);
        expect(process(['@language' => 'en-US'])->getTermDefinitions())->toBeInstanceOf(TermDefinitions::class);
        expect(process(['@language' => null])->getTermDefinitions())->toBeInstanceOf(TermDefinitions::class);
    });

    it('rejects malformed @language', function () {
        expect(fn () => process(['@language' => '12345']))
            ->toThrow(JsonLdException::class, 'Invalid @language value');
    });
});

describe('ContextProcessor::getTermDefinitions', function () {
    it('returns a TermDefinitions instance', function () {
        $processor = new ContextProcessor(
            ['@context' => ['name' => 'https://schema.org/name']],
            new StubDocumentLoader,
        );

        expect($processor->getTermDefinitions())->toBeInstanceOf(TermDefinitions::class);
    });
});

describe('@protected term enforcement', function () {
    $make = function (array $contextLayers) {
        return new ContextProcessor(['@context' => $contextLayers, 'id' => 'urn:1'], new StubDocumentLoader);
    };

    it('rejects redefining a protected term in a later context layer', function () use ($make) {
        expect(fn () => $make([
            ['@protected' => true, 'name' => 'https://schema.org/name'],
            ['name' => 'https://example.com/other'],
        ]))->toThrow(JsonLdException::class, 'Protected term redefinition');
    });

    it('allows an identical redefinition of a protected term (modulo @protected)', function () use ($make) {
        $processor = $make([
            ['@protected' => true, 'name' => 'https://schema.org/name'],
            ['name' => 'https://schema.org/name'],
        ]);
        expect($processor->getTermDefinitions()->isProtected('name'))->toBeTrue();
    });

    it('allows redefining a non-protected term', function () use ($make) {
        $processor = $make([
            ['name' => 'https://schema.org/name'],
            ['name' => 'https://example.com/other'],
        ]);
        expect($processor->getTermDefinitions()->getTermDefinition('name'))
            ->toBe(['@id' => 'https://example.com/other']);
    });

    it('lets a term opt out of an @protected context with @protected:false', function () use ($make) {
        $processor = $make([
            ['@protected' => true, 'open' => ['@id' => 'https://example.com/open', '@protected' => false]],
            ['open' => 'https://example.com/redefined'],
        ]);
        expect($processor->getTermDefinitions()->getTermDefinition('open'))
            ->toBe(['@id' => 'https://example.com/redefined']);
    });
});

describe('ContextProcessor processing-mode gates', function () {
    $in10 = function (array $context) {
        return fn () => new ContextProcessor(
            ['@context' => $context, 'id' => 'urn:1'],
            new StubDocumentLoader,
            null,
            'json-ld-1.0',
        );
    };

    it('rejects @version: 1.1 under processingMode json-ld-1.0 (processing mode conflict)', function () use ($in10) {
        expect($in10(['@version' => 1.1]))
            ->toThrow(JsonLdException::class, 'processing mode conflict');
    });

    it('rejects @propagate in JSON-LD 1.0', function () use ($in10) {
        expect($in10(['@propagate' => true]))
            ->toThrow(JsonLdException::class, 'invalid context entry');
    });

    it('rejects @import in JSON-LD 1.0', function () use ($in10) {
        expect($in10(['@import' => 'ctx.jsonld']))
            ->toThrow(JsonLdException::class, 'invalid context entry');
    });

    it('rejects a @type keyword redefinition in JSON-LD 1.0', function () use ($in10) {
        expect($in10(['@type' => ['@container' => '@set']]))
            ->toThrow(JsonLdException::class, 'keyword redefinition');
    });

    it('rejects an empty @vocab in JSON-LD 1.0', function () use ($in10) {
        expect($in10(['@base' => 'http://example.com/', '@vocab' => '']))
            ->toThrow(JsonLdException::class, 'invalid vocab mapping');
    });

    it('rejects a relative @vocab in JSON-LD 1.0', function () use ($in10) {
        expect($in10(['@vocab' => '/relative']))
            ->toThrow(JsonLdException::class, 'invalid vocab mapping');
    });

    it('allows @version: 1.1 / @propagate / a vocab under the default 1.1 mode', function () {
        $processor = new ContextProcessor(
            ['@context' => ['@version' => 1.1, '@propagate' => true, '@vocab' => 'http://example.com/'], 'id' => 'urn:1'],
            new StubDocumentLoader,
        );
        expect($processor->getTermDefinitions()->getProcessingMode())->toBe('json-ld-1.1');
    });
});
