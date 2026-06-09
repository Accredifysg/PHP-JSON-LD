<?php

declare(strict_types=1);

use Accredify\JsonLd\Tests\W3c\Harness;
use Accredify\JsonLd\Tests\W3c\NotImplementedException;
use Accredify\JsonLd\Tests\W3c\Support\PhpJsonLdAdapter;
use Accredify\JsonLd\Tests\W3c\TestCase;

/*
|--------------------------------------------------------------------------
| W3C JSON-LD Compaction conformance
|--------------------------------------------------------------------------
| Same shape as ExpansionTest. Compaction tests have an additional
| `context` file that must be loaded and passed to the algorithm.
*/

dataset('compaction-tests', function () {
    $manifestPath = __DIR__.'/../../w3c/tests/compact-manifest.jsonld';
    if (! is_file($manifestPath)) {
        return;
    }

    foreach (Harness::fromDefaultLocation()->manifest('compact-manifest.jsonld') as $test) {
        yield $test->id => [$test];
    }
});

it('compacts per W3C manifest', function (TestCase $test) {
    $processor = PhpJsonLdAdapter::default();

    try {
        $actual = $processor->compact(
            $test->loadInput(),
            $test->contextPath !== null ? $test->loadContext() : [],
            $test->options,
        );
    } catch (NotImplementedException) {
        $this->markTestSkipped('Compaction not yet implemented');
    } catch (Throwable $e) {
        if ($test->isNegative) {
            expect(true)->toBeTrue();

            return;
        }
        throw $e;
    }

    if ($test->isPositive) {
        // Object-key order is INSIGNIFICANT but array order is SIGNIFICANT in
        // JSON-LD compaction output, so `toEqual` (assertEquals) is the correct
        // comparison; `toEqualCanonicalizing` would sort arrays (hiding ordering
        // bugs) and compare keys strictly (failing key-order-only differences).
        expect($actual)->toEqual($test->loadExpected());
    } else {
        $this->fail('Negative tests should throw, but the processor returned a result');
    }
})->with('compaction-tests');
