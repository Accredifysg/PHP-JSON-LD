<?php

declare(strict_types=1);

use Accredify\JsonLd\Tests\W3c\Harness;
use Accredify\JsonLd\Tests\W3c\KnownBlockers;
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
    // An RDF dataset is a SET of quads, so duplicate quads (e.g. the same value
    // reached through two @index keys, whose @index is dropped in RDF — #te036)
    // are semantically irrelevant: compare datasets up to set membership.
    $relabelled = array_values(array_unique($relabelled));
    sort($relabelled, SORT_STRING);

    return implode("\n", $relabelled);
}

/**
 * Sound RDF-dataset isomorphism check between two N-Quads documents: the
 * datasets are equal iff there is a bijection between their blank-node labels
 * that makes the quad MULTISETS identical (ground terms must match exactly).
 *
 * {@see normaliseNQuads} is a fast order-based heuristic that is correct only
 * for graphs without blank-node symmetry; for symmetric graphs (e.g. two
 * implicit named graphs reached through identical predicates, as the `@graph`-
 * container fixtures produce) it can label two isomorphic datasets
 * differently. This routine is the exact fallback: signature-pruned
 * backtracking over the blank-node bijection, verifying the relabelled quad
 * multiset against the target. The final multiset check is what guarantees
 * soundness — it can never report a non-isomorphic pair as equal — and the
 * search is capped so a pathological symmetric graph degrades to "not equal"
 * (the heuristic's verdict) rather than hanging. Only ever used to RESCUE a
 * heuristic mismatch, so it cannot regress a passing comparison.
 *
 * @param  list<string>  $a
 * @param  list<string>  $b
 */
function nQuadsIsomorphic(array $a, array $b): bool
{
    if (count($a) !== count($b)) {
        return false;
    }

    $blanks = static function (array $lines): array {
        $set = [];
        foreach ($lines as $l) {
            remapNQuadsLine($l, static function (string $bn) use (&$set): string {
                $set[$bn] = true;

                return $bn;
            });
        }

        return array_keys($set);
    };
    $ba = $blanks($a);
    $bb = $blanks($b);
    if (count($ba) !== count($bb)) {
        return false;
    }

    $target = $b;
    sort($target, SORT_STRING);
    if ($ba === []) {
        $sa = $a;
        sort($sa, SORT_STRING);

        return $sa === $target;
    }

    // Signature of a blank node: the sorted multiset of the lines it occurs
    // in, with itself marked and every OTHER blank masked — so a blank can map
    // only onto one with an identical local structure.
    $sig = static function (array $lines, string $self): string {
        $m = array_map(
            static fn (string $l): string => remapNQuadsLine($l, static fn (string $x): string => $x === $self ? '_:SELF' : '_:X'),
            $lines,
        );
        sort($m, SORT_STRING);

        return implode('|', $m);
    };

    $candidates = [];
    $product = 1;
    foreach ($ba as $g) {
        $sg = $sig($a, $g);
        $candidates[$g] = array_values(array_filter($bb, static fn (string $e): bool => $sig($b, $e) === $sg));
        if ($candidates[$g] === []) {
            return false;
        }
        $product *= count($candidates[$g]);
    }
    if ($product > 5040) {
        return false; // refuse a pathological search; the heuristic already decided
    }

    $assign = [];
    $used = [];
    $backtrack = static function (int $k) use (&$backtrack, $ba, $candidates, &$assign, &$used, $a, $target): bool {
        if ($k === count($ba)) {
            $relabelled = array_map(
                static fn (string $l): string => remapNQuadsLine($l, static fn (string $x): string => $assign[$x] ?? $x),
                $a,
            );
            sort($relabelled, SORT_STRING);

            return $relabelled === $target;
        }
        $g = $ba[$k];
        foreach ($candidates[$g] as $e) {
            if (isset($used[$e])) {
                continue;
            }
            $assign[$g] = $e;
            $used[$e] = true;
            if ($backtrack($k + 1)) {
                return true;
            }
            unset($used[$e], $assign[$g]);
        }

        return false;
    };

    return $backtrack(0);
}

/**
 * @return list<string>
 */
function nQuadsLines(string $nquads): array
{
    // Deduplicate: a dataset is a set, so exact-duplicate quads do not affect
    // isomorphism (the count-based prune in nQuadsIsomorphic must see set
    // cardinality, not line multiplicity).
    return array_values(array_unique(array_filter(
        array_map('trim', explode("\n", $nquads)),
        static fn (string $l): bool => $l !== '',
    )));
}

/**
 * Runs one toRdf test and THROWS on any non-conformance (a positive test that
 * errors or whose N-Quads differ, or a negative test that fails to error).
 * Returns normally iff the processor conforms; NotImplementedException
 * propagates so the caller can skip. The throws-on-failure shape lets the
 * caller apply expected-failure (xfail) semantics uniformly.
 */
function assertToRdfConforms(TestCase $test): void
{
    $processor = PhpJsonLdAdapter::default();

    $options = $test->options;
    if (! isset($options['base']) && $test->documentUrl !== null) {
        $options['base'] = $test->documentUrl;
    }

    try {
        $actual = $processor->toRdf($test->loadInput(), $options);
    } catch (NotImplementedException $e) {
        throw $e;
    } catch (Throwable $e) {
        // Loader / expansion / conversion error. The expected outcome for
        // negative tests; non-conformance for positive tests.
        if ($test->isNegative) {
            expect(true)->toBeTrue();

            return;
        }
        throw $e;
    }

    if ($test->isNegative) {
        throw new RuntimeException('Negative test should have thrown, but the processor returned a result');
    }

    if ($test->expectPath === null) {
        // jld:PositiveSyntaxTest — no expected fixture; conforming means the
        // processor produced valid output without raising.
        expect($actual)->toBeString();

        return;
    }

    $expected = (string) file_get_contents($test->expectPath);

    // Fast path: the order-based canonicalisation. Fallback: a sound
    // blank-node isomorphism check that rescues structurally-equal datasets
    // the heuristic mislabels (e.g. the symmetric `@graph`-container graphs).
    $matches = normaliseNQuads($actual) === normaliseNQuads($expected)
        || nQuadsIsomorphic(nQuadsLines($actual), nQuadsLines($expected));

    expect($matches)->toBeTrue();
}

it('serialises to RDF per W3C manifest', function (TestCase $test) {
    // Expected-failure allowlist — see ExpansionTest / KnownBlockers.
    $blockerReason = KnownBlockers::TO_RDF[$test->id] ?? null;

    try {
        assertToRdfConforms($test);
    } catch (NotImplementedException) {
        $this->markTestSkipped('toRdf not yet implemented');
    } catch (Throwable $e) {
        if ($blockerReason !== null) {
            $this->markTestSkipped("known W3C blocker {$test->id}: {$blockerReason}");
        }
        throw $e;
    }

    if ($blockerReason !== null) {
        $this->fail("{$test->id} is on the known-blocker allowlist but now conforms — remove it from KnownBlockers::TO_RDF.");
    }
})->with('to-rdf-tests');
