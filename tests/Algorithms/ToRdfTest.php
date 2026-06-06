<?php

declare(strict_types=1);

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
        ->toRdf($document, $base)
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

it('drops a relative-IRI predicate (no RDF representation)', function () {
    $nq = toNQuads([
        '@context' => ['known' => 'http://example.com/known'],
        '@id' => 'http://example.com/s',
        'relativeProp' => 'dropped',
        'known' => 'kept',
    ]);

    expect($nq)->toBe('<http://example.com/s> <http://example.com/known> "kept" .'."\n");
});
