<?php

declare(strict_types=1);

use Accredify\JsonLd\Tests\W3c\Harness;
use Accredify\JsonLd\Tests\W3c\NotImplementedException;
use Accredify\JsonLd\Tests\W3c\Support\PhpJsonLdAdapter;
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
    $processor = PhpJsonLdAdapter::default();

    // Effective base: the manifest's option.base if present, else the
    // document URL. Injected into options for the adapter to consume.
    $options = $test->options;
    if (! isset($options['base']) && $test->documentUrl !== null) {
        $options['base'] = $test->documentUrl;
    }

    try {
        $actual = $processor->expand($test->loadInput(), $options);
    } catch (NotImplementedException) {
        $this->markTestSkipped('Expansion not yet implemented');
    } catch (Throwable $e) {
        // Real expander error (loader failure, missing context, internal
        // exception). For positive tests this is a failure; for negative
        // tests it counts as a pass (the spec expected an error).
        if ($test->isNegative) {
            expect(true)->toBeTrue(); // satisfy Pest's "no assertions" check

            return;
        }
        throw $e;
    }

    if ($test->isPositive) {
        expect($actual)->toEqualCanonicalizing($test->loadExpected());
    } else {
        $this->fail('Negative tests should throw, but the processor returned a result');
    }
})->with('expansion-tests');
