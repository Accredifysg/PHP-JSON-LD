<?php

declare(strict_types=1);

use Accredify\JsonLd\Exceptions\JsonLdException;
use Accredify\JsonLd\JsonLdOptions;
use Accredify\JsonLd\JsonLdProcessor;
use Accredify\JsonLd\Tests\Context\Support\StubDocumentLoader;

/*
|--------------------------------------------------------------------------
| Unit smoke tests for the fromRdf algorithm
|--------------------------------------------------------------------------
| Full conformance lives in tests/W3c/Algorithms/FromRdfTest.php (the W3C
| suite). These pin the core RDF→JSON-LD shapes — node refs, value objects,
| native-type coercion, rdf:type folding, list/empty-list conversion, named
| graphs, @json — and the toRdf→fromRdf round trip.
*/

$RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
$XSD = 'http://www.w3.org/2001/XMLSchema#';

/**
 * @return list<array<string, mixed>>
 */
function fromNQuads(string $nquads, ?JsonLdOptions $options = null): array
{
    /** @var list<array<string, mixed>> $nodes */
    $nodes = (new JsonLdProcessor(new StubDocumentLoader))
        ->fromRdf($nquads, $options)
        ->toArray();

    return $nodes;
}

it('deserialises types, literals, and node references', function () use ($RDF, $XSD) {
    $result = fromNQuads(implode("\n", [
        "<http://e/s> <{$RDF}type> <http://e/Type> .",
        '<http://e/s> <http://e/name> "Alice" .',
        "<http://e/s> <http://e/age> \"30\"^^<{$XSD}integer> .",
        '<http://e/s> <http://e/knows> <http://e/o> .',
        '<http://e/s> <http://e/note> "hi"@en .',
    ])."\n");

    expect($result)->toEqual([[
        '@id' => 'http://e/s',
        '@type' => ['http://e/Type'],
        'http://e/name' => [['@value' => 'Alice']],
        'http://e/age' => [['@value' => '30', '@type' => "{$XSD}integer"]],
        'http://e/knows' => [['@id' => 'http://e/o']],
        'http://e/note' => [['@value' => 'hi', '@language' => 'en']],
    ]]);
});

it('coerces native types when useNativeTypes is set (decimal stays a string)', function () use ($XSD) {
    $result = fromNQuads(implode("\n", [
        "<http://e/s> <http://e/p> \"true\"^^<{$XSD}boolean> .",
        "<http://e/s> <http://e/p> \"42\"^^<{$XSD}integer> .",
        "<http://e/s> <http://e/p> \"1.5\"^^<{$XSD}double> .",
        "<http://e/s> <http://e/p> \"1.1\"^^<{$XSD}decimal> .",
    ])."\n", new JsonLdOptions(useNativeTypes: true));

    expect($result[0]['http://e/p'])->toEqual([
        ['@value' => true],
        ['@value' => 42],
        ['@value' => 1.5],
        ['@value' => '1.1', '@type' => "{$XSD}decimal"],
    ]);
});

it('keeps rdf:type as a property when useRdfType is set', function () use ($RDF) {
    $result = fromNQuads(
        "<http://e/s> <{$RDF}type> <http://e/Type> .\n",
        new JsonLdOptions(useRdfType: true),
    );

    expect($result)->toEqual([[
        '@id' => 'http://e/s',
        "{$RDF}type" => [['@id' => 'http://e/Type']],
    ]]);
});

it('rebuilds a blank-node linked list into @list', function () use ($RDF) {
    $result = fromNQuads(implode("\n", [
        '<http://e/s> <http://e/list> _:a .',
        "_:a <{$RDF}first> \"a\" .",
        "_:a <{$RDF}rest> _:b .",
        "_:b <{$RDF}first> \"b\" .",
        "_:b <{$RDF}rest> <{$RDF}nil> .",
    ])."\n");

    expect($result)->toEqual([[
        '@id' => 'http://e/s',
        'http://e/list' => [['@list' => [['@value' => 'a'], ['@value' => 'b']]]],
    ]]);
});

it('converts a bare rdf:nil reference to an empty @list', function () use ($RDF) {
    $result = fromNQuads("<http://e/s> <http://e/empty> <{$RDF}nil> .\n");

    expect($result)->toEqual([[
        '@id' => 'http://e/s',
        'http://e/empty' => [['@list' => []]],
    ]]);
});

it('does not collapse an IRI-named list head', function () use ($RDF) {
    // The head is an IRI, so it stays a node; its blank-node tail collapses.
    $result = fromNQuads(implode("\n", [
        '<http://e/s> <http://e/p> <http://e/list> .',
        "<http://e/list> <{$RDF}first> \"a\" .",
        "<http://e/list> <{$RDF}rest> _:b .",
        "_:b <{$RDF}first> \"b\" .",
        "_:b <{$RDF}rest> <{$RDF}nil> .",
    ])."\n");

    expect($result)->toEqual([
        ['@id' => 'http://e/s', 'http://e/p' => [['@id' => 'http://e/list']]],
        [
            '@id' => 'http://e/list',
            "{$RDF}first" => [['@value' => 'a']],
            "{$RDF}rest" => [['@list' => [['@value' => 'b']]]],
        ],
    ]);
});

it('nests a named graph under @graph on its graph-name node', function () {
    $result = fromNQuads('<http://e/s> <http://e/p> "v" <http://e/g> .'."\n");

    expect($result)->toEqual([[
        '@id' => 'http://e/g',
        '@graph' => [[
            '@id' => 'http://e/s',
            'http://e/p' => [['@value' => 'v']],
        ]],
    ]]);
});

it('parses an rdf:JSON literal', function () use ($RDF) {
    $result = fromNQuads("<http://e/s> <http://e/p> \"true\"^^<{$RDF}JSON> .\n");

    expect($result)->toEqual([[
        '@id' => 'http://e/s',
        'http://e/p' => [['@value' => true, '@type' => '@json']],
    ]]);
});

it('throws on an invalid rdf:JSON literal', function () use ($RDF) {
    fromNQuads("<http://e/s> <http://e/p> \"bareword\"^^<{$RDF}JSON> .\n");
})->throws(JsonLdException::class);

it('round-trips a document through toRdf and back', function () {
    $processor = new JsonLdProcessor(new StubDocumentLoader);
    $doc = [
        '@id' => 'http://e/s',
        'http://e/name' => 'Alice',
        'http://e/knows' => ['@id' => 'http://e/o'],
    ];

    $nquads = $processor->toRdf($doc)->toNQuads();
    $back = $processor->fromRdf($nquads)->toArray();

    expect($back)->toEqual([[
        '@id' => 'http://e/s',
        'http://e/name' => [['@value' => 'Alice']],
        'http://e/knows' => [['@id' => 'http://e/o']],
    ]]);
});
