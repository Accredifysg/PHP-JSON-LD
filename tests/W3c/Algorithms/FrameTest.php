<?php

declare(strict_types=1);

use Accredify\JsonLd\Tests\W3c\Harness;
use Accredify\JsonLd\Tests\W3c\KnownBlockers;
use Accredify\JsonLd\Tests\W3c\NotImplementedException;
use Accredify\JsonLd\Tests\W3c\Support\PhpJsonLdAdapter;
use Accredify\JsonLd\Tests\W3c\TestCase;

/*
|--------------------------------------------------------------------------
| W3C JSON-LD 1.1 Framing conformance
|--------------------------------------------------------------------------
| Framing is a separate spec (w3c/json-ld-framing) with its own submodule
| (tests/w3c-framing) and document base. Each entry has input + frame +
| expect. Output is compacted JSON-LD, so `toEqual` (object-key order
| insignificant, array order significant) is the right comparison.
| KnownBlockers::FRAME is the xfail allowlist.
*/

dataset('frame-tests', function () {
    $manifestPath = __DIR__.'/../../w3c-framing/tests/frame-manifest.jsonld';
    if (! is_file($manifestPath)) {
        return;
    }

    foreach (Harness::fromFramingLocation()->manifest('frame-manifest.jsonld') as $test) {
        yield $test->id => [$test];
    }
});

it('frames per W3C manifest', function (TestCase $test) {
    $processor = PhpJsonLdAdapter::forFraming();
    $blocker = KnownBlockers::FRAME[$test->id] ?? null;

    try {
        $actual = $processor->frame($test->loadInput(), $test->loadFrame(), $test->options);
    } catch (NotImplementedException) {
        $this->markTestSkipped('Framing not yet implemented');
    } catch (Throwable $e) {
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

    try {
        expect($actual)->toEqual($test->loadExpected());
    } catch (Throwable $e) {
        if ($blocker !== null) {
            $this->markTestSkipped("Known W3C blocker: {$blocker}");
        }
        throw $e;
    }

    if ($blocker !== null) {
        $this->fail("Listed as a known blocker but now conforms — remove '{$test->id}' from KnownBlockers::FRAME: {$blocker}");
    }
})->with('frame-tests');
