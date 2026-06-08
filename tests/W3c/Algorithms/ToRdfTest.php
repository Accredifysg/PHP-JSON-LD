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

/**
 * Rewrites the blank-node labels in one N-Quads line via $map, leaving
 * quoted literals (which may legitimately contain "_:") untouched. $map
 * receives each blank-node label (e.g. "_:b0") and returns its replacement.
 */
function remapNQuadsLine(string $line, callable $map): string
{
    $out = '';
    $i = 0;
    $n = strlen($line);
    $inLiteral = false;
    while ($i < $n) {
        $c = $line[$i];
        if ($inLiteral) {
            $out .= $c;
            if ($c === '\\' && $i + 1 < $n) {
                $out .= $line[$i + 1];
                $i += 2;

                continue;
            }
            if ($c === '"') {
                $inLiteral = false;
            }
            $i++;

            continue;
        }
        if ($c === '"') {
            $inLiteral = true;
            $out .= $c;
            $i++;

            continue;
        }
        if ($c === '_' && $i + 1 < $n && $line[$i + 1] === ':') {
            $j = $i + 2;
            while ($j < $n && preg_match('/[A-Za-z0-9._\-]/', $line[$j]) === 1) {
                $j++;
            }
            $out .= $map(substr($line, $i, $j - $i));
            $i = $j;

            continue;
        }
        $out .= $c;
        $i++;
    }

    return $out;
}

/**
 * Normalise an N-Quads document to sorted, trimmed, non-empty lines with
 * blank-node labels canonicalised. RDF datasets are equal up to blank-node
 * isomorphism, but the W3C fixtures use canonicalised labels (`_:c14n0`)
 * while the processor emits `_:b0`; relabelling both deterministically (by
 * first appearance in the structurally-sorted quads, masking blank-node
 * labels so ordering is label-independent) lets isomorphic datasets compare
 * equal. Literal content is preserved. This is sound for graphs without
 * blank-node automorphisms (the suite's graphs).
 */
function normaliseNQuads(string $nquads): string
{
    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $nquads)),
        static fn (string $l): bool => $l !== '',
    ));

    // Order independent of the specific blank-node labels.
    $masked = [];
    foreach ($lines as $idx => $line) {
        $masked[$idx] = remapNQuadsLine($line, static fn (): string => '_:_');
    }
    asort($masked, SORT_STRING);

    // Assign canonical labels by first appearance in that stable order.
    $map = [];
    $next = 0;
    foreach (array_keys($masked) as $idx) {
        remapNQuadsLine($lines[$idx], function (string $label) use (&$map, &$next): string {
            if (! isset($map[$label])) {
                $map[$label] = '_:b'.$next++;
            }

            return $map[$label];
        });
    }

    $relabelled = array_map(
        static fn (string $line): string => remapNQuadsLine($line, static fn (string $label): string => $map[$label] ?? $label),
        $lines,
    );
    sort($relabelled, SORT_STRING);

    return implode("\n", $relabelled);
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

    if ($test->isNegative) {
        $this->fail('Negative tests should throw, but the processor returned a result');
    }

    if ($test->expectPath === null) {
        // jld:PositiveSyntaxTest — no expected fixture; passing means the
        // processor produced valid output without raising.
        expect($actual)->toBeString();

        return;
    }

    $expected = (string) file_get_contents($test->expectPath);
    expect(normaliseNQuads($actual))->toEqual(normaliseNQuads($expected));
})->with('to-rdf-tests');
