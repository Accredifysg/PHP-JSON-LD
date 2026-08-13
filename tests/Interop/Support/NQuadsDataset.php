<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\Interop\Support;

/**
 * RDF-dataset equality between two N-Quads documents, up to blank-node
 * relabelling. The goldens carry RDFC-1.0 labels (`_:c14n0`) while the
 * processor emits `_:b0`, so raw byte comparison is meaningless; instead
 * both sides are canonically relabelled (fast heuristic) with an exact
 * isomorphism check as fallback for symmetric graphs.
 *
 * Same approach as the W3C toRdf harness (tests/W3c/Algorithms/ToRdfTest.php),
 * packaged as a class so the interop suite (which runs as part of the Unit
 * testsuite) does not depend on the conformance harness being loaded.
 */
final class NQuadsDataset
{
    public static function equivalent(string $actual, string $expected): bool
    {
        return self::normalise($actual) === self::normalise($expected)
            || self::isomorphic(self::lines($actual), self::lines($expected));
    }

    /**
     * Human-readable set difference for failure output: lines only in
     * $actual prefixed `+`, lines only in $expected prefixed `-`, both after
     * canonical relabelling.
     */
    public static function diff(string $actual, string $expected): string
    {
        $a = explode("\n", self::normalise($actual));
        $e = explode("\n", self::normalise($expected));

        $out = [];
        foreach (array_diff($e, $a) as $line) {
            $out[] = '- '.$line;
        }
        foreach (array_diff($a, $e) as $line) {
            $out[] = '+ '.$line;
        }

        return implode("\n", $out);
    }

    /**
     * Rewrites the blank-node labels in one N-Quads line via $map, leaving
     * quoted literals (which may legitimately contain "_:") untouched.
     *
     * @param  callable(string): string  $map
     */
    private static function remapLine(string $line, callable $map): string
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
     * Sorted, deduplicated lines with blank-node labels canonicalised by
     * first appearance in a label-independent ordering. Exact for graphs
     * without blank-node automorphisms; {@see isomorphic} rescues the rest.
     */
    private static function normalise(string $nquads): string
    {
        $lines = self::lines($nquads);

        $masked = [];
        foreach ($lines as $idx => $line) {
            $masked[$idx] = self::remapLine($line, static fn (): string => '_:_');
        }
        asort($masked, SORT_STRING);

        $map = [];
        $next = 0;
        foreach (array_keys($masked) as $idx) {
            self::remapLine($lines[$idx], function (string $label) use (&$map, &$next): string {
                if (! isset($map[$label])) {
                    $map[$label] = '_:b'.$next++;
                }

                return $map[$label];
            });
        }

        $relabelled = array_map(
            static fn (string $line): string => self::remapLine($line, static fn (string $label): string => $map[$label] ?? $label),
            $lines,
        );
        $relabelled = array_values(array_unique($relabelled));
        sort($relabelled, SORT_STRING);

        return implode("\n", $relabelled);
    }

    /**
     * Sound blank-node isomorphism: the datasets are equal iff a bijection
     * between their blank-node labels makes the quad sets identical.
     * Signature-pruned backtracking, capped so a pathological symmetric graph
     * degrades to "not equal" rather than hanging.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function isomorphic(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        $ba = self::blankLabels($a);
        $bb = self::blankLabels($b);
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

        $candidates = [];
        $product = 1;
        foreach ($ba as $g) {
            $sg = self::signature($a, $g);
            $candidates[$g] = array_values(array_filter($bb, static fn (string $e): bool => self::signature($b, $e) === $sg));
            if ($candidates[$g] === []) {
                return false;
            }
            $product *= count($candidates[$g]);
        }
        if ($product > 5040) {
            return false;
        }

        $assign = [];
        $used = [];
        $backtrack = static function (int $k) use (&$backtrack, $ba, $candidates, &$assign, &$used, $a, $target): bool {
            if ($k === count($ba)) {
                $relabelled = array_map(
                    static fn (string $l): string => self::remapLine($l, static fn (string $x): string => $assign[$x] ?? $x),
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
     * The distinct blank-node labels occurring in $lines.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function blankLabels(array $lines): array
    {
        $set = [];
        foreach ($lines as $l) {
            self::remapLine($l, static function (string $bn) use (&$set): string {
                $set[$bn] = true;

                return $bn;
            });
        }

        return array_keys($set);
    }

    /**
     * Signature of a blank node: the sorted multiset of the lines it occurs
     * in, with itself marked and every OTHER blank masked — so a blank can
     * map only onto one with an identical local structure.
     *
     * @param  list<string>  $lines
     */
    private static function signature(array $lines, string $self): string
    {
        $m = array_map(
            static fn (string $l): string => self::remapLine($l, static fn (string $x): string => $x === $self ? '_:SELF' : '_:X'),
            $lines,
        );
        sort($m, SORT_STRING);

        return implode('|', $m);
    }

    /**
     * Trimmed, non-empty, deduplicated lines (a dataset is a set of quads).
     *
     * @return list<string>
     */
    private static function lines(string $nquads): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', explode("\n", $nquads)),
            static fn (string $l): bool => $l !== '',
        )));
    }
}
