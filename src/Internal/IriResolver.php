<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Internal;

/**
 * Resolves a relative IRI reference against a base IRI, per RFC 3986 §5.
 *
 * Used by the Expansion algorithm for document-relative IRI expansion
 * (`@id`, `@type`, `@base`). This is the standard "Transform References"
 * (§5.2.2) + "Remove Dot Segments" (§5.2.4) + "Merge Paths" (§5.3)
 * machinery, operating on the generic URI grammar — it is IRI-agnostic
 * (treats the input as opaque characters), which is sufficient for the
 * JSON-LD test suite.
 *
 * @internal
 */
final class IriResolver
{
    /**
     * Resolve $reference against $base. If $base is null/empty, or the
     * reference is already an absolute IRI, the reference is returned
     * (dot-segment-normalised when it has its own scheme).
     */
    /**
     * Establish a base IRI from an `@base` value. An absolute IRI is stored
     * VERBATIM — RFC 3986 §5.1 parses the base as-is, without dot-segment
     * removal, so that later document-relative resolution against it matches
     * the RFC reference-resolution vectors (e.g. a query-only reference keeps
     * a `./` in the base path). A relative value is resolved against the
     * current base.
     */
    public static function establishBase(?string $currentBase, string $value): string
    {
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $value) === 1) {
            return $value;
        }

        return self::resolve($currentBase, $value);
    }

    public static function resolve(?string $base, string $reference): string
    {
        $ref = self::parse($reference);

        // Reference has a scheme → it's absolute; just normalise its path.
        if ($ref['scheme'] !== null) {
            return self::recompose(
                $ref['scheme'],
                $ref['authority'],
                self::removeDotSegments($ref['path']),
                $ref['query'],
                $ref['fragment'],
            );
        }

        if ($base === null || $base === '') {
            // No base to resolve against — return the reference as given,
            // but still strip dot segments from an absolute path.
            return $reference;
        }

        $b = self::parse($base);
        if ($b['scheme'] === null) {
            // Base isn't absolute; can't meaningfully resolve. Return ref.
            return $reference;
        }

        // RFC 3986 §5.2.2 Transform References (R.scheme is undefined here).
        if ($ref['authority'] !== null) {
            $authority = $ref['authority'];
            $path = self::removeDotSegments($ref['path']);
            $query = $ref['query'];
        } else {
            if ($ref['path'] === '') {
                $path = $b['path'];
                $query = $ref['query'] ?? $b['query'];
            } else {
                if (str_starts_with($ref['path'], '/')) {
                    $path = self::removeDotSegments($ref['path']);
                } else {
                    $path = self::removeDotSegments(self::mergePaths($b, $ref['path']));
                }
                $query = $ref['query'];
            }
            $authority = $b['authority'];
        }

        return self::recompose($b['scheme'], $authority, $path, $query, $ref['fragment']);
    }

    /**
     * Produce a relative reference for $iri against $base (the inverse of
     * {@see resolve}), as used by compaction to express `@id` document-relative
     * (§5.6). Returns $iri unchanged when it cannot be relativised (no base, or
     * a differing scheme/authority). A leading segment that could be misread as
     * a keyword (`@…`) or a scheme/compact-IRI (`x:…`) is prefixed with `./`.
     */
    public static function relativize(?string $base, string $iri): string
    {
        if ($base === null || $base === '') {
            return $iri;
        }
        $b = self::parse($base);
        $i = self::parse($iri);
        // A different scheme or authority cannot be expressed relatively.
        if ($i['scheme'] === null || $i['scheme'] !== $b['scheme'] || $i['authority'] !== $b['authority']) {
            return $iri;
        }

        $relPath = self::relativePath($b['path'], $i['path']);
        // An IRI identical to the base (same path, and no query/fragment of
        // its own) is expressed as the base's last path segment — e.g.
        // ".../api/things/1" against itself relativises to "1" (#t0076); a
        // base path ending in "/" still yields "./" via the empty-string
        // fallback below.
        if ($relPath === '' && $b['path'] === $i['path'] && $i['query'] === null && $i['fragment'] === null) {
            $pos = strrpos($i['path'], '/');
            $relPath = $pos === false ? $i['path'] : substr($i['path'], $pos + 1);
        }
        if ($relPath !== '') {
            $firstSeg = explode('/', $relPath, 2)[0];
            if (str_starts_with($relPath, '@') || str_contains($firstSeg, ':')) {
                $relPath = './'.$relPath;
            }
        }

        $rel = $relPath;
        if ($i['query'] !== null) {
            $rel .= '?'.$i['query'];
        }
        if ($i['fragment'] !== null) {
            $rel .= '#'.$i['fragment'];
        }

        return $rel === '' ? './' : $rel;
    }

    /**
     * Relative path from $basePath to $iriPath (RFC 3986 §5.3 reverse).
     * Returns '' when the paths are identical (a same-document reference).
     */
    private static function relativePath(string $basePath, string $iriPath): string
    {
        if ($basePath === $iriPath) {
            return '';
        }
        $baseSegs = explode('/', $basePath);
        array_pop($baseSegs); // drop the base's "file" segment
        $iriSegs = explode('/', $iriPath);

        $common = 0;
        $max = min(count($baseSegs), count($iriSegs));
        while ($common < $max && $baseSegs[$common] === $iriSegs[$common]) {
            $common++;
        }

        $up = count($baseSegs) - $common;
        $down = array_slice($iriSegs, $common);

        return str_repeat('../', $up).implode('/', $down);
    }

    /**
     * Split a URI reference into its five components, preserving the
     * empty-vs-absent distinction for query and fragment (`x?` has an empty
     * query; `x` has none — the difference matters for §5.2.2).
     *
     * @return array{scheme: ?string, authority: ?string, path: string, query: ?string, fragment: ?string}
     */
    private static function parse(string $uri): array
    {
        $fragment = null;
        $hashPos = strpos($uri, '#');
        if ($hashPos !== false) {
            $fragment = substr($uri, $hashPos + 1);
            $uri = substr($uri, 0, $hashPos);
        }

        $query = null;
        $qPos = strpos($uri, '?');
        if ($qPos !== false) {
            $query = substr($uri, $qPos + 1);
            $uri = substr($uri, 0, $qPos);
        }

        $scheme = null;
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $uri, $m) === 1) {
            $scheme = $m[1];
            $uri = substr($uri, strlen($m[0]));
        }

        $authority = null;
        if (str_starts_with($uri, '//')) {
            $rest = substr($uri, 2);
            $slash = strpos($rest, '/');
            if ($slash === false) {
                $authority = $rest;
                $uri = '';
            } else {
                $authority = substr($rest, 0, $slash);
                $uri = substr($rest, $slash);
            }
        }

        return [
            'scheme' => $scheme,
            'authority' => $authority,
            'path' => $uri,
            'query' => $query,
            'fragment' => $fragment,
        ];
    }

    /**
     * RFC 3986 §5.3 — merge a relative path onto the base.
     *
     * @param  array{scheme: ?string, authority: ?string, path: string, query: ?string, fragment: ?string}  $base
     */
    private static function mergePaths(array $base, string $refPath): string
    {
        if ($base['authority'] !== null && $base['path'] === '') {
            return '/'.$refPath;
        }

        $pos = strrpos($base['path'], '/');
        if ($pos === false) {
            return $refPath;
        }

        return substr($base['path'], 0, $pos + 1).$refPath;
    }

    /**
     * RFC 3986 §5.2.4 — remove "." and ".." segments from a path.
     */
    private static function removeDotSegments(string $path): string
    {
        if ($path === '' || ! str_contains($path, '.')) {
            return $path;
        }

        $output = '';
        $input = $path;

        while ($input !== '') {
            // A: leading ../ or ./
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
            } elseif (str_starts_with($input, './')) {
                $input = substr($input, 2);
            }
            // B: leading /./ or /. (as complete segment)
            elseif (str_starts_with($input, '/./')) {
                $input = '/'.substr($input, 3);
            } elseif ($input === '/.') {
                $input = '/';
            }
            // C: leading /../ or /.. (as complete segment)
            elseif (str_starts_with($input, '/../')) {
                $input = '/'.substr($input, 4);
                $output = self::removeLastSegment($output);
            } elseif ($input === '/..') {
                $input = '/';
                $output = self::removeLastSegment($output);
            }
            // D: input is exactly "." or ".."
            elseif ($input === '.' || $input === '..') {
                $input = '';
            }
            // E: move first path segment to output
            else {
                $slash = strpos($input, '/', 1);
                if ($slash === false) {
                    $output .= $input;
                    $input = '';
                } else {
                    $output .= substr($input, 0, $slash);
                    $input = substr($input, $slash);
                }
            }
        }

        return $output;
    }

    private static function removeLastSegment(string $output): string
    {
        $pos = strrpos($output, '/');

        return $pos === false ? '' : substr($output, 0, $pos);
    }

    /**
     * RFC 3986 §5.3 — recompose components into a URI string.
     */
    private static function recompose(?string $scheme, ?string $authority, string $path, ?string $query, ?string $fragment): string
    {
        $result = '';
        if ($scheme !== null) {
            $result .= $scheme.':';
        }
        if ($authority !== null) {
            $result .= '//'.$authority;
        }
        $result .= $path;
        if ($query !== null) {
            $result .= '?'.$query;
        }
        if ($fragment !== null) {
            $result .= '#'.$fragment;
        }

        return $result;
    }
}
