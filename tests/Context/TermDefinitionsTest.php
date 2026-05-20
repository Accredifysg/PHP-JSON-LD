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

    it('rejects terms containing a colon', function () {
        $defs = new TermDefinitions;
        expect(fn () => $defs->addTermDefinition('foo:bar', 'https://example.com/'))
            ->toThrow(JsonLdException::class, "Invalid term 'foo:bar': cannot contain ':' or '/'");
    });

    it('rejects terms containing a slash', function () {
        $defs = new TermDefinitions;
        expect(fn () => $defs->addTermDefinition('foo/bar', 'https://example.com/'))
            ->toThrow(JsonLdException::class, "Invalid term 'foo/bar': cannot contain ':' or '/'");
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

    it('rejects non-array nested @context', function () {
        $defs = new TermDefinitions;
        expect(fn () => $defs->addTermDefinition('x', ['@id' => 'x', '@context' => 'https://example.com/']))
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

    it('descends into nested @context maps to find a term', function () {
        $defs = new TermDefinitions([
            'VerifiableCredential' => [
                '@id' => 'https://www.w3.org/2018/credentials#VerifiableCredential',
                '@context' => [
                    '@protected' => true,
                    'inner' => ['@id' => 'https://example.com/inner'],
                ],
            ],
        ]);

        expect($defs->getTermDefinition('inner'))->toBe(['@id' => 'https://example.com/inner']);
    });
});
