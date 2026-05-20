<?php

declare(strict_types=1);

use Accredify\JsonLd\Tests\W3c\Harness;
use Accredify\JsonLd\Tests\W3c\NotImplementedException;
use Accredify\JsonLd\Tests\W3c\NullProcessor;
use Accredify\JsonLd\Tests\W3c\TestCase;

/*
|--------------------------------------------------------------------------
| W3C JSON-LD toRdf conformance
|--------------------------------------------------------------------------
| toRdf tests expect an N-Quads string. The fixture file is plain text,
| not JSON, so we read it directly rather than via TestCase::loadExpected().
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

it('serialises to RDF per W3C manifest', function (TestCase $test) {
    $processor = new NullProcessor;

    try {
        $actual = $processor->toRdf($test->loadInput(), $test->options);
    } catch (NotImplementedException) {
        $this->markTestSkipped('toRdf not yet implemented');
    }

    if ($test->isPositive && $test->expectPath !== null) {
        $expected = (string) file_get_contents($test->expectPath);
        expect(trim($actual))->toEqual(trim($expected));
    } else {
        $this->fail('Negative tests should throw, but the processor returned a result');
    }
})->with('to-rdf-tests');
