<?php

declare(strict_types=1);

use Accredify\JsonLd\Tests\W3c\Harness;
use Accredify\JsonLd\Tests\W3c\KnownBlockers;
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

/**
 * Runs one expand test and THROWS on any non-conformance (a positive test that
 * errors or mismatches, or a negative test that fails to error). Returns
 * normally iff the processor conforms. NotImplementedException propagates so
 * the caller can skip. This single throws-on-failure shape is what lets the
 * caller apply expected-failure (xfail) semantics uniformly.
 */
function assertExpansionConforms(TestCase $test): void
{
    $processor = PhpJsonLdAdapter::default();

    // Effective base: the manifest's option.base if present, else the
    // document URL. Injected into options for the adapter to consume.
    $options = $test->options;
    if (! isset($options['base']) && $test->documentUrl !== null) {
        $options['base'] = $test->documentUrl;
    }

    try {
        $actual = $processor->expand($test->loadInput(), $options);
    } catch (NotImplementedException $e) {
        throw $e;
    } catch (Throwable $e) {
        // Real expander error (loader failure, missing context, internal
        // exception). For positive tests this is non-conformance; for negative
        // tests it is the expected outcome (the spec wanted an error).
        if ($test->isNegative) {
            expect(true)->toBeTrue(); // satisfy Pest's "no assertions" check

            return;
        }
        throw $e;
    }

    if ($test->isNegative) {
        throw new RuntimeException('Negative test should have thrown, but the processor returned a result');
    }

    // JSON-LD expansion output is compared with object-key order INSIGNIFICANT
    // but array order SIGNIFICANT (§5.5 produces arrays in a deterministic
    // order). `toEqual` (assertEquals) has exactly those semantics.
    // `toEqualCanonicalizing` is wrong here: it sorts arrays (masking real
    // ordering bugs) while comparing object keys strictly (failing correct
    // output that differs only in key order — e.g. our ksorted value objects
    // vs the suite's @value-first ordering).
    expect($actual)->toEqual($test->loadExpected());
}

it('expands per W3C manifest', function (TestCase $test) {
    // Known, accepted non-conformances are an explicit xfail allowlist: a
    // listed test that still fails is skipped; a listed test that starts
    // conforming fails loudly so the list cannot rot; any unlisted failure
    // fails as a real regression.
    $blockerReason = KnownBlockers::EXPAND[$test->id] ?? null;

    try {
        assertExpansionConforms($test);
    } catch (NotImplementedException) {
        $this->markTestSkipped('Expansion not yet implemented');
    } catch (Throwable $e) {
        if ($blockerReason !== null) {
            $this->markTestSkipped("known W3C blocker {$test->id}: {$blockerReason}");
        }
        throw $e;
    }

    if ($blockerReason !== null) {
        $this->fail("{$test->id} is on the known-blocker allowlist but now conforms — remove it from KnownBlockers::EXPAND.");
    }
})->with('expansion-tests');
