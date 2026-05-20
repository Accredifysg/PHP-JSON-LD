<?php

declare(strict_types=1);

use Accredify\JsonLd\Tests\W3c\Harness;
use Accredify\JsonLd\Tests\W3c\NotImplementedException;
use Accredify\JsonLd\Tests\W3c\NullProcessor;
use Accredify\JsonLd\Tests\W3c\TestCase;

/*
|--------------------------------------------------------------------------
| W3C JSON-LD Expansion conformance
|--------------------------------------------------------------------------
| Iterates every entry in tests/w3c/tests/expand-manifest.jsonld and runs
| it through the {@see \Accredify\JsonLd\Tests\W3c\Processor} adapter.
|
| Until Phase 4 lands a real Expansion implementation, the adapter throws
| NotImplementedException and every test below is skipped. Phase 4 will
| swap in a real processor; PRs will report the before/after PASS count
| from this suite.
*/

dataset('expansion-tests', function () {
    $manifestPath = __DIR__.'/../../w3c/tests/expand-manifest.jsonld';
    if (! is_file($manifestPath)) {
        // Submodule not initialised — yield nothing so the suite simply
        // reports zero tests rather than failing to bootstrap.
        return;
    }

    foreach (Harness::fromDefaultLocation()->manifest('expand-manifest.jsonld') as $test) {
        yield $test->id => [$test];
    }
});

it('expands per W3C manifest', function (TestCase $test) {
    $processor = new NullProcessor;

    try {
        $actual = $processor->expand($test->loadInput(), $test->options);
    } catch (NotImplementedException) {
        $this->markTestSkipped('Expansion not yet implemented');
    }

    if ($test->isPositive) {
        expect($actual)->toEqualCanonicalizing($test->loadExpected());
    } else {
        $this->fail('Negative tests should throw, but the processor returned a result');
    }
})->with('expansion-tests');
