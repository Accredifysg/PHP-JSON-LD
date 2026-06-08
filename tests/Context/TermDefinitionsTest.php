<?php

declare(strict_types=1);

use Accredify\JsonLd\Context\TermDefinitions;
use Accredify\JsonLd\Exceptions\JsonLdException;

describe('TermDefinitions::addTermDefinition', function () {
    it('stores an array definition as-is', function () {
        $defs = new TermDefinitions;
        $defs->addTermDefinition('name', ['@id' => 'https://schema.org/name']);

        expect($defs->termDefinitions)->toBe([
            'name' => ['@id' => 'https://schema.org/name'],
        ]);
    });

    it('wraps a string definition into an @id mapping', function () {
        $defs = new TermDefinitions;
        $defs->addTermDefinition('id', '@id');

        expect($defs->termDefinitions)->toBe([
            'id' => ['@id' => '@id'],
        ]);
    });

    it('accepts compact-IRI term keys (e.g. ex:date)', function () {
        // As of v0.9.0, terms MAY contain ':' / '/' — a context can define a
        // compact-IRI term to attach coercion (the IRI-expansion algorithm
        // resolves them). Only keywords are rejected.
        $defs = new TermDefinitions;
        $defs->addTermDefinition('ex:date', ['@id' => 'http://example.org/date', '@type' => '@id']);
        $defs->addTermDefinition('rdfs:subClassOf', 'http://www.w3.org/2000/01/rdf-schema#subClassOf');

        expect($defs->getTermDefinition('ex:date'))->toBe(['@id' => 'http://example.org/date', '@type' => '@id']);
        expect($defs->getTermDefinition('rdfs:subClassOf'))->toBe(['@id' => 'http://www.w3.org/2000/01/rdf-schema#subClassOf']);
    });

    it('rejects keywords as terms', function () {
        $defs = new TermDefinitions;
        expect(fn () => $defs->addTermDefinition('@id', 'https://example.com/'))
            ->toThrow(JsonLdException::class, "Invalid term '@id': cannot be a keyword");
    });

    it('rejects non-string @id', function () {
        $defs = new TermDefinitions;
        expect(fn () => $defs->addTermDefinition('name', ['@id' => 123]))
            ->toThrow(JsonLdException::class, "Invalid @id in term 'name'");
    });

    it('rejects non-string @type', function () {
        $defs = new TermDefinitions;
        expect(fn () => $defs->addTermDefinition('issued', ['@id' => 'x', '@type' => true]))
            ->toThrow(JsonLdException::class, "Invalid @type in term 'issued'");
    });

    it('rejects unknown @container values', function () {
        $defs = new TermDefinitions;
        expect(fn () => $defs->addTermDefinition('items', ['@id' => 'x', '@container' => '@bogus']))
            ->toThrow(JsonLdException::class, "Invalid @container in term 'items'");
    });

    it('accepts known @container values', function () {
        $defs = new TermDefinitions;
        $defs->addTermDefinition('items', ['@id' => 'x', '@container' => '@list']);

        expect($defs->termDefinitions['items'])->toBe(['@id' => 'x', '@container' => '@list']);
    });

    it('rejects non-bool @protected', function () {
        $defs = new TermDefinitions;
        expect(fn () => $defs->addTermDefinition('x', ['@id' => 'x', '@protected' => 'yes']))
            ->toThrow(JsonLdException::class, "Invalid @protected in term 'x'");
    });

    it('accepts a string (remote) nested @context', function () {
        // A scoped @context may be a remote context IRI, resolved during
        // expansion via the document loader.
        $defs = new TermDefinitions;
        $defs->addTermDefinition('x', ['@id' => 'x', '@context' => 'https://example.com/']);
        expect($defs->getTermDefinition('x'))->toHaveKey('@context');
    });

    it('rejects a nested @context that is neither a string, map, nor null', function () {
        $defs = new TermDefinitions;
        expect(fn () => $defs->addTermDefinition('x', ['@id' => 'x', '@context' => 42]))
            ->toThrow(JsonLdException::class, "Invalid @context in term 'x'");
    });

    it('rejects non-bool @protected inside a nested context', function () {
        $defs = new TermDefinitions;
        expect(fn () => $defs->addTermDefinition('x', [
            '@id' => 'x',
            '@context' => ['@protected' => 'yes'],
        ]))->toThrow(JsonLdException::class, "Invalid @protected in nested context for term 'x'");
    });
});

describe('TermDefinitions::getTermDefinition', function () {
    it('returns null for a null term', function () {
        expect((new TermDefinitions)->getTermDefinition(null))->toBeNull();
    });

    it('returns null for an unknown term', function () {
        $defs = new TermDefinitions(['name' => ['@id' => 'https://schema.org/name']]);
        expect($defs->getTermDefinition('unknown'))->toBeNull();
    });

    it('returns an array definition stored at the top level', function () {
        $defs = new TermDefinitions(['name' => ['@id' => 'https://schema.org/name']]);
        expect($defs->getTermDefinition('name'))->toBe(['@id' => 'https://schema.org/name']);
    });

    it('inflates a top-level string definition into ["@id" => …]', function () {
        $defs = new TermDefinitions(['id' => '@id']);
        expect($defs->getTermDefinition('id'))->toBe(['@id' => '@id']);
    });

    it('does NOT descend into nested @context maps to find a term', function () {
        // Spec-correct behaviour as of v0.4.0: nested @context entries inside
        // a term definition are NOT findable via top-level lookup. They become
        // available only when the Expansion algorithm activates that term's
        // scope (type-scoped or property-scoped context activation).
        $defs = new TermDefinitions([
            'VerifiableCredential' => [
                '@id' => 'https://www.w3.org/2018/credentials#VerifiableCredential',
                '@context' => [
                    '@protected' => true,
                    'inner' => ['@id' => 'https://example.com/inner'],
                ],
            ],
        ]);

        expect($defs->getTermDefinition('inner'))->toBeNull();
        // The outer term IS findable.
        expect($defs->getTermDefinition('VerifiableCredential'))
            ->toBe([
                '@id' => 'https://www.w3.org/2018/credentials#VerifiableCredential',
                '@context' => [
                    '@protected' => true,
                    'inner' => ['@id' => 'https://example.com/inner'],
                ],
            ]);
    });
});

describe('TermDefinitions IRI-mapping validation', function () {
    it('rejects a blank-node @type in a term definition', function () {
        expect(fn () => (new TermDefinitions)->addTermDefinition('t', ['@id' => 'http://example/t', '@type' => '_:b']))
            ->toThrow(JsonLdException::class, 'Invalid type mapping');
    });

    it('rejects a relative @type when there is no @vocab', function () {
        expect(fn () => (new TermDefinitions)->addTermDefinition('t', ['@id' => 'http://example/t', '@type' => 'relative']))
            ->toThrow(JsonLdException::class, 'Invalid type mapping');
    });

    it('rejects a bare term with no @id and no @vocab', function () {
        expect(fn () => (new TermDefinitions)->addTermDefinition('term', ['@container' => '@set']))
            ->toThrow(JsonLdException::class, 'Invalid IRI mapping');
    });

    it('rejects a term aliasing @context', function () {
        expect(fn () => (new TermDefinitions)->addTermDefinition('term', ['@id' => '@context']))
            ->toThrow(JsonLdException::class, 'Invalid keyword alias');
    });

    it('allows a bare term once an @vocab is set', function () {
        $defs = new TermDefinitions;
        $defs->setVocab('http://example.com/');
        $defs->addTermDefinition('term', ['@container' => '@set']);
        expect($defs->getTermDefinition('term'))->toHaveKey('@container');
    });
});

describe('TermDefinitions processing-mode gates', function () {
    it('rejects an array @container in JSON-LD 1.0', function () {
        $defs = new TermDefinitions;
        $defs->setProcessingMode('json-ld-1.0');
        expect(fn () => $defs->addTermDefinition('term', ['@id' => 'http://example/t', '@container' => ['@set']]))
            ->toThrow(JsonLdException::class, 'requires JSON-LD 1.1');
    });

    it('rejects an @id/@type/@graph @container in JSON-LD 1.0', function () {
        $defs = new TermDefinitions;
        $defs->setProcessingMode('json-ld-1.0');
        expect(fn () => $defs->addTermDefinition('term', ['@id' => 'http://example/t', '@container' => '@id']))
            ->toThrow(JsonLdException::class, 'Invalid @container');
    });

    it('still accepts a single 1.0 @container (@list/@set/@index/@language)', function () {
        $defs = new TermDefinitions;
        $defs->setProcessingMode('json-ld-1.0');
        $defs->addTermDefinition('term', ['@id' => 'http://example/t', '@container' => '@set']);
        expect($defs->getTermDefinition('term'))->toHaveKey('@container');
    });

    it('rejects a property-valued @index in JSON-LD 1.0', function () {
        $defs = new TermDefinitions;
        $defs->setProcessingMode('json-ld-1.0');
        $defs->setVocab('http://example.com/'); // so the bare term resolves
        expect(fn () => $defs->addTermDefinition('container', ['@container' => '@index', '@index' => 'prop']))
            ->toThrow(JsonLdException::class, 'property-valued @index requires JSON-LD 1.1');
    });

    it('rejects an IRI-shaped term mapping to a keyword @id in JSON-LD 1.1', function () {
        // §4.2.2: a term that is itself an IRI must expand to its @id mapping;
        // a keyword @id (e.g. @type) can never equal an IRI term. #ter43.
        $defs = new TermDefinitions; // defaults to json-ld-1.1
        expect(fn () => $defs->addTermDefinition('http://www.w3.org/1999/02/22-rdf-syntax-ns#type', ['@id' => '@type', '@type' => '@id']))
            ->toThrow(JsonLdException::class, 'Invalid IRI mapping');
    });

    it('allows the same IRI-shaped/@type term in JSON-LD 1.0', function () {
        // #t0026: the consistency check does not apply in 1.0.
        $defs = new TermDefinitions;
        $defs->setProcessingMode('json-ld-1.0');
        $defs->addTermDefinition('http://www.w3.org/1999/02/22-rdf-syntax-ns#type', ['@id' => '@type', '@type' => '@id']);
        expect($defs->getTermDefinition('http://www.w3.org/1999/02/22-rdf-syntax-ns#type'))->toHaveKey('@id');
    });

    it('still allows a simple term aliasing @type in JSON-LD 1.1', function () {
        // #tc0073: term "type" has no colon/slash, so the consistency check
        // does not fire even in 1.1.
        $defs = new TermDefinitions;
        $defs->addTermDefinition('type', ['@id' => '@type', '@type' => '@id']);
        expect($defs->getTermDefinition('type'))->toBe(['@id' => '@type', '@type' => '@id']);
    });

    it('rejects @prefix / @nest / scoped @context in JSON-LD 1.0', function () {
        foreach (['@prefix' => true, '@nest' => '@nest', '@context' => []] as $kw => $val) {
            $defs = new TermDefinitions;
            $defs->setProcessingMode('json-ld-1.0');
            expect(fn () => $defs->addTermDefinition('foo', ['@id' => 'http://example/foo', $kw => $val]))
                ->toThrow(JsonLdException::class, 'not available in JSON-LD 1.0');
        }
    });

    it('rejects @prefix on a compact-IRI term', function () {
        // #tep09: @prefix may only be set on a simple term.
        $defs = new TermDefinitions;
        $defs->addTermDefinition('foo', 'http://example/foo/');
        expect(fn () => $defs->addTermDefinition('foo:bar', ['@id' => 'http://example/foo/bar', '@prefix' => true]))
            ->toThrow(JsonLdException::class, '@prefix is not allowed on a compact-IRI term');
    });
});

describe('TermDefinitions @type coercion resolution', function () {
    it('accepts a bare @type that resolves via a previously-defined term', function () {
        // #t0015 / #t0024: @type may be a defined term (here t2 → an IRI),
        // not only a keyword / absolute IRI / @vocab-resolved value.
        $defs = new TermDefinitions;
        $defs->addTermDefinition('t2', 'http://example.com/t2');
        $defs->addTermDefinition('term2', ['@id' => 'http://example.com/term', '@type' => 't2']);
        expect($defs->getTermDefinition('term2'))->toBe(['@id' => 'http://example.com/term', '@type' => 't2']);
    });

    it('still rejects a bare @type with no @vocab and no matching term', function () {
        $defs = new TermDefinitions;
        expect(fn () => $defs->addTermDefinition('term', ['@id' => 'http://example.com/term', '@type' => 'undefinedType']))
            ->toThrow(JsonLdException::class, 'Invalid type mapping');
    });
});

describe('TermDefinitions structural validation gates', function () {
    it('rejects a non-string @language mapping (#ter22)', function () {
        expect(fn () => (new TermDefinitions)->addTermDefinition('term', ['@id' => 'http://example/term', '@language' => true]))
            ->toThrow(JsonLdException::class, 'Invalid language mapping');
    });

    it('rejects @container combining @list with another container (#tes02)', function () {
        expect(fn () => (new TermDefinitions)->addTermDefinition('term', ['@id' => 'http://example/term', '@container' => ['@list', '@set']]))
            ->toThrow(JsonLdException::class, '@list may not be combined');
    });

    it('rejects @type: @none in JSON-LD 1.0 (#ttn01)', function () {
        $defs = new TermDefinitions;
        $defs->setProcessingMode('json-ld-1.0');
        expect(fn () => $defs->addTermDefinition('notype', ['@id' => 'http://example/notype', '@type' => '@none']))
            ->toThrow(JsonLdException::class, '@type @none requires JSON-LD 1.1');
    });
});
