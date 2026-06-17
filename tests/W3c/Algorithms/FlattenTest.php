<?php

declare(strict_types=1);

use Accredify\JsonLd\Tests\W3c\Harness;
use Accredify\JsonLd\Tests\W3c\KnownBlockers;
use Accredify\JsonLd\Tests\W3c\NotImplementedException;
use Accredify\JsonLd\Tests\W3c\Support\PhpJsonLdAdapter;
use Accredify\JsonLd\Tests\W3c\TestCase;

/*
|--------------------------------------------------------------------------
| W3C JSON-LD Flattening conformance
|--------------------------------------------------------------------------
| Same shape as CompactionTest — a flatten test may carry an optional
| `context` file (compacting the flattened output). KnownBlockers::FLATTEN
| is an xfail allowlist: a listed test that still fails is skipped, but one
| that starts passing fails the suite (so the list cannot rot).
*/

dataset('flatten-tests', function () {
    $manifestPath = __DIR__.'/../../w3c/tests/flatten-manifest.jsonld';
    if (! is_file($manifestPath)) {
        return;
    }

    foreach (Harness::fromDefaultLocation()->manifest('flatten-manifest.jsonld') as $test) {
        yield $test->id => [$test];
    }
});

it('flattens per W3C manifest', function (TestCase $test) {
    $processor = PhpJsonLdAdapter::default();
    $blocker = KnownBlockers::FLATTEN[$test->id] ?? null;

    // Flattening relativises @id against the document base; default it to the
    // test document URL when the manifest entry doesn't set base explicitly.
    $options = $test->options;
    if (! isset($options['base']) && $test->documentUrl !== null) {
        $options['base'] = $test->documentUrl;
    }

    try {
        $actual = $processor->flatten(
            $test->loadInput(),
            $test->contextPath !== null ? $test->loadContext() : [],
            $options,
        );
    } catch (NotImplementedException) {
        $this->markTestSkipped('Flattening not yet implemented');
    } catch (Throwable $e) {
        // The processor raised: expected for a negative test; for a positive
        // test it's a failure unless this id is a known blocker.
        if ($test->isNegative) {
            expect(true)->toBeTrue();

            return;
        }
        if ($blocker !== null) {
            $this->markTestSkipped("Known W3C blocker: {$blocker}");
        }
        throw $e;
    }

    if ($test->isNegative) {
        $this->fail('Negative test should have thrown, but the processor returned a result');
    }

    // Object-key order is INSIGNIFICANT but array order is SIGNIFICANT in
    // flattening output, so `toEqual` (assertEquals) is the correct comparison.
    try {
        expect($actual)->toEqual($test->loadExpected());
    } catch (Throwable $e) {
        if ($blocker !== null) {
            $this->markTestSkipped("Known W3C blocker: {$blocker}");
        }
        throw $e;
    }

    if ($blocker !== null) {
        $this->fail("Listed as a known blocker but now conforms — remove '{$test->id}' from KnownBlockers::FLATTEN: {$blocker}");
    }
})->with('flatten-tests');
