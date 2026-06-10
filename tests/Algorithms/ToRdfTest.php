<?php

declare(strict_types=1);

use Accredify\JsonLd\JsonLdOptions;
use Accredify\JsonLd\JsonLdProcessor;
use Accredify\JsonLd\Tests\Context\Support\StubDocumentLoader;

/*
|--------------------------------------------------------------------------
| Unit smoke tests for the toRdf algorithm
|--------------------------------------------------------------------------
| Full conformance lives in tests/W3c/Algorithms/ToRdfTest.php (the W3C
| suite). These pin the core conversion shapes so refactors can't silently
| break them.
*/

/**
 * @param  array<array-key, mixed>  $document
 */
function toNQuads(array $document, ?string $base = null): string
{
    return (new JsonLdProcessor(new StubDocumentLoader))
        ->toRdf($document, $base !== null ? new JsonLdOptions(base: $base) : null)
        ->toNQuads();
}

it('emits a plain literal triple with an IRI subject', function () {
    $nq = toNQuads([
        '@id' => 'http://example.com/s',
        'http://example.com/name' => 'Alice',
    ]);

    expect($nq)->toBe('<http://example.com/s> <http://example.com/name> "Alice" .'."\n");
});

it('types xsd datatypes from native JSON values', function () {
    $nq = toNQuads([
        '@context' => ['e' => 'http://example.com/'],
        '@id' => 'http://example.com/s',
        'e:int' => 12,
        'e:bool' => true,
        'e:double' => 5.3,
    ]);

    expect($nq)->toContain('"12"^^<http://www.w3.org/2001/XMLSchema#integer>');
    expect($nq)->toContain('"true"^^<http://www.w3.org/2001/XMLSchema#boolean>');
    expect($nq)->toContain('"5.3E0"^^<http://www.w3.org/2001/XMLSchema#double>');
});

it('keeps an explicit datatype and a language tag', function () {
    $nq = toNQuads([
        '@id' => 'http://example.com/s',
        'http://example.com/d' => ['@value' => '1957-02-27', '@type' => 'http://www.w3.org/2001/XMLSchema#date'],
        'http://example.com/l' => ['@value' => 'bonjour', '@language' => 'fr'],
    ]);

    expect($nq)->toContain('"1957-02-27"^^<http://www.w3.org/2001/XMLSchema#date>');
    expect($nq)->toContain('"bonjour"@fr');
});

it('mints a blank node subject when @id is absent', function () {
    $nq = toNQuads([
        '@type' => 'http://example.com/Person',
    ]);

    expect($nq)->toBe('_:b0 <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://example.com/Person> .'."\n");
});

it('converts an @list into an RDF collection', function () {
    $nq = toNQuads([
        '@context' => ['knows' => ['@id' => 'http://example.com/knows', '@container' => '@list']],
        '@id' => 'http://example.com/s',
        'knows' => ['a', 'b'],
    ]);

    $lines = array_filter(explode("\n", $nq));
    // head triple + (first, rest) × 2 cells = 5 triples
    expect($lines)->toHaveCount(5);
    expect($nq)->toContain('<http://www.w3.org/1999/02/22-rdf-syntax-ns#first> "a"');
    expect($nq)->toContain('<http://www.w3.org/1999/02/22-rdf-syntax-ns#first> "b"');
    expect($nq)->toContain('<http://www.w3.org/1999/02/22-rdf-syntax-ns#rest> <http://www.w3.org/1999/02/22-rdf-syntax-ns#nil>');
});

it('places named-graph statements in the fourth quad position', function () {
    $nq = toNQuads([
        '@id' => 'http://example.com/g',
        '@graph' => [
            ['@id' => 'http://example.com/s', 'http://example.com/p' => ['@id' => 'http://example.com/o']],
        ],
    ]);

    expect($nq)->toBe(
        '<http://example.com/s> <http://example.com/p> <http://example.com/o> <http://example.com/g> .'."\n"
    );
});

it('drops a statement whose IRI is not well-formed (contains a space)', function () {
    // A subject/predicate/object IRI containing characters illegal in an
    // IRIREF (e.g. a space) yields no RDF term, so the statement is dropped.
    $nq = toNQuads([
        '@id' => 'http://example.com/a b',
        'http://example.com/foo' => 'bar',
    ]);

    expect($nq)->toBe('');
});

it('drops a relative-IRI predicate (no RDF representation)', function () {
    $nq = toNQuads([
        '@context' => ['known' => 'http://example.com/known'],
        '@id' => 'http://example.com/s',
        'relativeProp' => 'dropped',
        'known' => 'kept',
    ]);

    expect($nq)->toBe('<http://example.com/s> <http://example.com/known> "kept" .'."\n");
});

it('serialises a directional string as an i18n-datatype literal', function () {
    $nq = (new JsonLdProcessor(new StubDocumentLoader))
        ->toRdf(
            ['@id' => 'http://example/s', 'http://example/p' => ['@value' => 'x', '@direction' => 'rtl', '@language' => 'en']],
            new JsonLdOptions(rdfDirection: 'i18n-datatype'),
        )
        ->toNQuads();
    expect($nq)->toContain('"x"^^<https://www.w3.org/ns/i18n#en_rtl>');
});

it('serialises a directional string as a compound literal', function () {
    $nq = (new JsonLdProcessor(new StubDocumentLoader))
        ->toRdf(
            ['@id' => 'http://example/s', 'http://example/p' => ['@value' => 'x', '@direction' => 'rtl', '@language' => 'en']],
            new JsonLdOptions(rdfDirection: 'compound-literal'),
        )
        ->toNQuads();
    expect($nq)->toContain('<http://www.w3.org/1999/02/22-rdf-syntax-ns#value> "x" .');
    expect($nq)->toContain('<http://www.w3.org/1999/02/22-rdf-syntax-ns#direction> "rtl" .');
    expect($nq)->toContain('<http://www.w3.org/1999/02/22-rdf-syntax-ns#language> "en" .');
});

it('serialises a number >= 1e21 as an xsd:double, not an integer (#trt01)', function () {
    $nq = toNQuads([
        '@id' => 'http://example/s',
        'http://example/n' => ['@value' => 1.0e21],
    ]);
    expect($nq)->toContain('"1.0E21"^^<http://www.w3.org/2001/XMLSchema#double>')
        ->and($nq)->not->toContain('XMLSchema#integer');
});

it('keeps native true and 1 as distinct coerced values without loose dedup (#te061)', function () {
    // PHP's `1 == true` must NOT collapse these into one node-map value.
    $nq = toNQuads([
        '@id' => 'http://example/s',
        'http://example/p' => [
            ['@value' => 1, '@type' => 'http://example/d'],
            ['@value' => true, '@type' => 'http://example/d'],
        ],
    ]);
    expect($nq)->toContain('"1"^^<http://example/d>')
        ->and($nq)->toContain('"true"^^<http://example/d>');
});

it('drops a blank-node predicate by default but keeps it under produceGeneralizedRdf (#t0118/#te075)', function () {
    $doc = ['@id' => 'http://example/s', '_:b' => [['@value' => 'v']]];
    $loader = new StubDocumentLoader;

    // Default: the blank-node-predicate statement is dropped (valid RDF only).
    $plain = (new JsonLdProcessor($loader))->toRdf($doc)->toNQuads();
    expect($plain)->not->toContain('_:b');

    // produceGeneralizedRdf: the blank-node predicate is emitted (generalized
    // RDF). The issuer relabels the predicate blank node deterministically.
    $generalized = (new JsonLdProcessor($loader))
        ->toRdf($doc, new JsonLdOptions(produceGeneralizedRdf: true))
        ->toNQuads();
    expect($generalized)->toContain('<http://example/s> _:b0 "v" .');
});
