<?php

declare(strict_types=1);

use Accredify\JsonLd\Tests\W3c\Harness;
use Accredify\JsonLd\Tests\W3c\NotImplementedException;
use Accredify\JsonLd\Tests\W3c\Support\PhpJsonLdAdapter;
use Accredify\JsonLd\Tests\W3c\TestCase;

/*
|--------------------------------------------------------------------------
| W3C JSON-LD toRdf conformance
|--------------------------------------------------------------------------
| toRdf tests expect an N-Quads document (plain text, not JSON). An RDF
| dataset is unordered, so both sides are normalised to sorted, trimmed
| lines before comparison.
*/

dataset('to-rdf-tests', function () {
    $manifestPath = __DIR__.'/../../w3c/tests/toRdf-manifest.jsonld';
    if (! is_file($manifestPath)) {
        return;
    }

    foreach (Harness::fromDefaultLocation()->manifest('toRdf-manifest.jsonld') as $test) {
        yield $test->id => [$test];
    }
});

/** Normalise an N-Quads document to sorted, trimmed, non-empty lines. */
function normaliseNQuads(string $nquads): string
{
    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $nquads)),
        static fn (string $l): bool => $l !== '',
    ));
    sort($lines, SORT_STRING);

    return implode("\n", $lines);
}

it('serialises to RDF per W3C manifest', function (TestCase $test) {
    $processor = PhpJsonLdAdapter::default();

    $options = $test->options;
    if (! isset($options['base']) && $test->documentUrl !== null) {
        $options['base'] = $test->documentUrl;
    }

    try {
        $actual = $processor->toRdf($test->loadInput(), $options);
    } catch (NotImplementedException) {
        $this->markTestSkipped('toRdf not yet implemented');
    } catch (Throwable $e) {
        // Loader / expansion / conversion error. A pass for negative tests
        // (the spec expected an error); a failure for positive tests.
        if ($test->isNegative) {
            expect(true)->toBeTrue();

            return;
        }
        throw $e;
    }

    if ($test->isPositive && $test->expectPath !== null) {
        $expected = (string) file_get_contents($test->expectPath);
        expect(normaliseNQuads($actual))->toEqual(normaliseNQuads($expected));
    } else {
        $this->fail('Negative tests should throw, but the processor returned a result');
    }
})->with('to-rdf-tests');
