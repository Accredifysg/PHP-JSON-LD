<?php

declare(strict_types=1);

use Accredify\JsonLd\Tests\W3c\Harness;
use Accredify\JsonLd\Tests\W3c\KnownBlockers;
use Accredify\JsonLd\Tests\W3c\NotImplementedException;
use Accredify\JsonLd\Tests\W3c\Support\PhpJsonLdAdapter;
use Accredify\JsonLd\Tests\W3c\TestCase;

/*
|--------------------------------------------------------------------------
| W3C JSON-LD fromRdf (Serialize RDF as JSON-LD) conformance
|--------------------------------------------------------------------------
| The input fixtures are N-Quads (.nq) text — loaded raw, not JSON. The
| output is expanded JSON-LD whose top-level node array, property-value
| arrays, and @type arrays are unordered sets; only @list arrays are
| order-significant. normaliseExpanded() canonicalises both sides on that
| basis so toEqual compares them faithfully. Blank-node labels are carried
| through from the input, so they line up with the fixtures as-is.
*/

dataset('from-rdf-tests', function () {
    $manifestPath = __DIR__.'/../../w3c/tests/fromRdf-manifest.jsonld';
    if (! is_file($manifestPath)) {
        return;
    }

    foreach (Harness::fromDefaultLocation()->manifest('fromRdf-manifest.jsonld') as $test) {
        yield $test->id => [$test];
    }
});

/**
 * Canonicalise expanded JSON-LD for order-insensitive comparison: sort object
 * keys, and sort every array EXCEPT one that is the value of an `@list` key
 * (whose order is significant).
 */
function normaliseExpanded(mixed $value, bool $ordered = false): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        $items = array_map(fn (mixed $item): mixed => normaliseExpanded($item, false), $value);
        if (! $ordered) {
            usort($items, fn (mixed $a, mixed $b): int => strcmp((string) json_encode($a), (string) json_encode($b)));
        }

        return $items;
    }

    ksort($value);
    $out = [];
    foreach ($value as $key => $member) {
        $out[$key] = normaliseExpanded($member, $key === '@list');
    }

    return $out;
}

it('deserialises from RDF per W3C manifest', function (TestCase $test) {
    $processor = PhpJsonLdAdapter::default();
    $blocker = KnownBlockers::FROM_RDF[$test->id] ?? null;

    try {
        $actual = $processor->fromRdf($test->loadInputRaw(), $test->options);
    } catch (NotImplementedException) {
        $this->markTestSkipped('fromRdf not yet implemented');
    } catch (Throwable $e) {
        // A raise is expected for a negative test; for a positive test it's a
        // failure unless this id is a known blocker.
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
        expect(normaliseExpanded($actual))->toEqual(normaliseExpanded($test->loadExpected()));
    } catch (Throwable $e) {
        if ($blocker !== null) {
            $this->markTestSkipped("Known W3C blocker: {$blocker}");
        }
        throw $e;
    }

    if ($blocker !== null) {
        $this->fail("Listed as a known blocker but now conforms — remove '{$test->id}' from KnownBlockers::FROM_RDF: {$blocker}");
    }
})->with('from-rdf-tests');
