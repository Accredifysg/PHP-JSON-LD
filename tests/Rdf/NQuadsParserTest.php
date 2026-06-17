<?php

declare(strict_types=1);

use Accredify\JsonLd\Exceptions\JsonLdException;
use Accredify\JsonLd\Rdf\NQuadsParser;
use Accredify\JsonLd\Rdf\RdfQuad;
use Accredify\JsonLd\Rdf\RdfTerm;

/*
|--------------------------------------------------------------------------
| Unit tests for the N-Quads parser
|--------------------------------------------------------------------------
| The parser is the inverse of RdfTerm/RdfQuad::toNQuads(); the central
| guarantee is that it round-trips with them. Plus targeted grammar and
| error cases.
*/

/**
 * @return list<RdfQuad>
 */
function parseNQuads(string $input): array
{
    return (new NQuadsParser)->parse($input);
}

it('round-trips every term kind through toNQuads()', function () {
    $quads = [
        new RdfQuad(
            RdfTerm::iri('http://example.com/s'),
            RdfTerm::iri('http://example.com/p'),
            RdfTerm::literal('plain'),
        ),
        new RdfQuad(
            RdfTerm::blankNode('_:b0'),
            RdfTerm::iri('http://example.com/p'),
            RdfTerm::literal('typed', RdfTerm::XSD_INTEGER),
        ),
        new RdfQuad(
            RdfTerm::iri('http://example.com/s'),
            RdfTerm::iri('http://example.com/p'),
            RdfTerm::literal('hi', null, 'en'),
            RdfTerm::iri('http://example.com/g'),
        ),
        new RdfQuad(
            RdfTerm::iri('http://example.com/s'),
            RdfTerm::iri('http://example.com/p'),
            RdfTerm::blankNode('_:b1'),
            RdfTerm::blankNode('_:g1'),
        ),
    ];

    $serialized = implode("\n", array_map(fn (RdfQuad $q): string => $q->toNQuads(), $quads))."\n";
    $parsed = parseNQuads($serialized);

    expect(array_map(fn (RdfQuad $q): string => $q->toNQuads(), $parsed))
        ->toBe(array_map(fn (RdfQuad $q): string => $q->toNQuads(), $quads));
});

it('round-trips escaped characters in literals', function () {
    $original = new RdfQuad(
        RdfTerm::iri('http://example.com/s'),
        RdfTerm::iri('http://example.com/p'),
        RdfTerm::literal("a\t\"b\"\n\\c"),
    );

    $parsed = parseNQuads($original->toNQuads()."\n");

    expect($parsed)->toHaveCount(1);
    expect($parsed[0]->object->value)->toBe("a\t\"b\"\n\\c");
});

it('decodes UCHAR escapes', function () {
    $parsed = parseNQuads('<http://example.com/s> <http://example.com/p> "café \U0001F600" .'."\n");

    expect($parsed[0]->object->value)->toBe("caf\u{00E9} \u{1F600}");
});

it('parses plain, typed, and language-tagged literals', function () {
    $parsed = parseNQuads(implode("\n", [
        '<http://e/s> <http://e/p> "plain" .',
        '<http://e/s> <http://e/p> "5"^^<http://www.w3.org/2001/XMLSchema#integer> .',
        '<http://e/s> <http://e/p> "hello"@en-US .',
    ])."\n");

    expect($parsed[0]->object->datatype)->toBe(RdfTerm::XSD_STRING);
    expect($parsed[0]->object->language)->toBeNull();
    expect($parsed[1]->object->datatype)->toBe(RdfTerm::XSD_INTEGER);
    expect($parsed[2]->object->language)->toBe('en-US');
    expect($parsed[2]->object->datatype)->toBe(RdfTerm::RDF_LANG_STRING);
});

it('parses the optional graph term and defaults to null', function () {
    $parsed = parseNQuads(implode("\n", [
        '<http://e/s> <http://e/p> <http://e/o> .',
        '<http://e/s> <http://e/p> <http://e/o> <http://e/g> .',
    ])."\n");

    expect($parsed[0]->graph)->toBeNull();
    expect($parsed[1]->graph?->value)->toBe('http://e/g');
});

it('skips comments and blank lines', function () {
    $parsed = parseNQuads(implode("\n", [
        '# a comment',
        '',
        '   ',
        '<http://e/s> <http://e/p> <http://e/o> .',
        '# trailing comment',
    ])."\n");

    expect($parsed)->toHaveCount(1);
});

it('accepts \r\n line endings', function () {
    $parsed = parseNQuads("<http://e/s> <http://e/p> <http://e/o> .\r\n");

    expect($parsed)->toHaveCount(1);
    expect($parsed[0]->subject->value)->toBe('http://e/s');
});

it('throws on a literal in subject position', function () {
    parseNQuads('"nope" <http://e/p> <http://e/o> .'."\n");
})->throws(JsonLdException::class);

it('throws on a missing terminating dot', function () {
    parseNQuads('<http://e/s> <http://e/p> <http://e/o>'."\n");
})->throws(JsonLdException::class);

it('throws on an unterminated literal', function () {
    parseNQuads('<http://e/s> <http://e/p> "oops .'."\n");
})->throws(JsonLdException::class);

it('throws on trailing content after the terminating dot', function () {
    parseNQuads('<http://e/s> <http://e/p> <http://e/o> . garbage'."\n");
})->throws(JsonLdException::class);
