<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Context\TermDefinitions;
use Accredify\JsonLd\Enums\Keyword;

/**
 * JSON-LD 1.1 Expansion Algorithm.
 *
 * Implements §5.2 (IRI Expansion), §5.4 (Value Expansion), and §5.5
 * (Expansion) of the JSON-LD 1.1 API specification:
 * https://www.w3.org/TR/json-ld11-api/
 *
 * Scope of this PR (4.1 in the revised Phase 4 plan):
 *
 *  - The core Expansion Algorithm: drops free-floating nodes, wraps the
 *    result in an outer array, expands properties via IRI Expansion (vocab
 *    mode), preserves value objects as value-object leaves, handles `@id` /
 *    `@type` / `@list` / `@set`.
 *  - Value Expansion: respects term-definition `@type` coercion to `@id`,
 *    `@vocab`, or arbitrary datatype IRIs.
 *  - IRI Expansion: handles JSON-LD keywords, blank nodes (`_:…`), full
 *    IRIs (`scheme://…`, `did:…`, `urn:…`), compact IRIs (`prefix:suffix`),
 *    `@vocab` fallback. `documentRelative` mode is a stub — proper `@base`
 *    resolution lands when Context Processing acquires a base IRI.
 *
 * Deferred:
 *
 *  - `@language`, `@index`, `@graph`, `@id`, `@type`, `@nest`, `@included`
 *    container handling — PR 4.2.
 *  - `@reverse`, `@json` literals, language-tagged + direction-tagged
 *    strings — PR 4.3.
 *  - Type-scoped + property-scoped contexts that activate per-object —
 *    deferred to a future Context Processing PR. v0.1.1's flattened
 *    approximation is still in effect via TermDefinitions.
 *
 * Released as v0.2.0 — this is a breaking change vs v0.1.x for any
 * downstream that depended on the lift-and-shifted output shape.
 */
class Expansion
{
    public function __construct(private readonly TermDefinitions $termDefinitions) {}

    /**
     * Entry point. Always returns a list of node objects per the spec.
     *
     * @param  array<array-key, mixed>  $document
     * @return list<array<string, mixed>>
     */
    public function expand(array $document): array
    {
        $expanded = $this->expandElement($document, null);

        if ($expanded === null) {
            return [];
        }

        // If the result is a list, flatten it (drop nulls).
        if (array_is_list($expanded)) {
            $out = [];
            foreach ($expanded as $item) {
                if ($item === null) {
                    continue;
                }
                if (is_array($item) && ! array_is_list($item)) {
                    /** @var array<string, mixed> $item */
                    $out[] = $item;
                }
            }

            return $out;
        }

        // Single object → wrap in list.
        /** @var array<string, mixed> $expanded */
        return [$expanded];
    }

    /**
     * Top-level dispatch for §5.5 (Expansion Algorithm).
     *
     * @return array<mixed>|null
     */
    private function expandElement(mixed $element, ?string $activeProperty): ?array
    {
        if ($element === null) {
            return null;
        }

        if (is_array($element)) {
            return array_is_list($element)
                ? $this->expandArray($element, $activeProperty)
                : $this->expandObject($element, $activeProperty);
        }

        // Scalar value (string, int, float, bool).
        return $this->expandScalar($element, $activeProperty);
    }

    /**
     * §5.5 step 4: scalars are expanded via Value Expansion (§5.4) unless the
     * active property is null or `@graph`, in which case they're dropped.
     *
     * @return array<string, mixed>|null
     */
    private function expandScalar(mixed $element, ?string $activeProperty): ?array
    {
        if ($activeProperty === null || $activeProperty === Keyword::Graph->value) {
            return null;
        }

        return $this->expandValue($activeProperty, $element);
    }

    /**
     * §5.5 step 5: each item in the array is expanded; nulls and empty
     * results are dropped. If the active property's term definition has
     * `@container: @list`, the result is wrapped in a `@list` object.
     *
     * @param  array<int, mixed>  $items
     * @return array<mixed>
     */
    private function expandArray(array $items, ?string $activeProperty): array
    {
        $result = [];

        foreach ($items as $item) {
            $expanded = $this->expandElement($item, $activeProperty);
            if ($expanded === null) {
                continue;
            }

            // Flatten one level — a nested list inside an array is treated as
            // a sequence of values in the same list (free-floating arrays
            // don't survive expansion).
            if (array_is_list($expanded)) {
                foreach ($expanded as $sub) {
                    if ($sub !== null) {
                        $result[] = $sub;
                    }
                }
            } else {
                $result[] = $expanded;
            }
        }

        if ($this->containerIs($activeProperty, Keyword::List->value) && ! $this->insideListContext($activeProperty)) {
            return [['@list' => $result]];
        }

        return $result;
    }

    /**
     * §5.5 main object expansion. Returns:
     *  - null if the object expands to nothing (free-floating, dropped node).
     *  - array<string, mixed> for a single expanded node / value object.
     *  - array (list) for `@list` / `@set` containers that produce multiple
     *    objects from a single input.
     *
     * @param  array<array-key, mixed>  $obj
     * @return array<mixed>|null
     */
    private function expandObject(array $obj, ?string $activeProperty): ?array
    {
        $result = [];

        foreach ($obj as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if ($key === Keyword::Context->value) {
                // Context already merged upstream by ContextProcessor.
                continue;
            }

            $expandedKey = $this->expandIri($key, vocab: true);
            if ($expandedKey === null) {
                continue;
            }

            // JSON-LD keyword keys get special handling.
            if ($this->isKeyword($expandedKey)) {
                $expandedValue = $this->expandKeywordValue($expandedKey, $value);
                if ($expandedValue !== null) {
                    $result[$expandedKey] = $expandedValue;
                }

                continue;
            }

            // Regular property: recursively expand the value with this key
            // as the active property (so nested term-def coercion works).
            $expandedValue = $this->expandElement($value, $key);
            if ($expandedValue === null) {
                continue;
            }

            // Normalise to array (spec wraps property values as lists).
            $list = array_is_list($expandedValue)
                ? $expandedValue
                : [$expandedValue];

            // Merge with any existing entries for the same expanded property
            // (e.g. when both an alias and the keyword form appear).
            if (isset($result[$expandedKey])) {
                /** @var array<mixed> $existing */
                $existing = is_array($result[$expandedKey]) && array_is_list($result[$expandedKey])
                    ? $result[$expandedKey]
                    : [$result[$expandedKey]];
                $result[$expandedKey] = array_merge($existing, $list);
            } else {
                $result[$expandedKey] = $list;
            }
        }

        // Value-object short-circuit: if @value is set, the spec treats this
        // as a value object and returns it as-is (a leaf, not a node).
        if (isset($result[Keyword::Value->value])) {
            ksort($result);

            return $result;
        }

        // @list object: pass through as-is (the @list value itself was
        // already expanded into a list of values).
        if (isset($result[Keyword::List->value])) {
            ksort($result);

            return $result;
        }

        // Free-floating node: an object whose only expanded entry is @id,
        // with no other properties, is dropped during expansion (§5.5 step
        // 14). At the top level (activeProperty === null) this is firm; on
        // nested objects we keep it because the spec allows references.
        if (
            $activeProperty === null
            && count($result) === 1
            && isset($result[Keyword::Id->value])
        ) {
            return null;
        }

        // Empty object → drop.
        if ($result === []) {
            return null;
        }

        ksort($result);

        return $result;
    }

    /**
     * Specialised expansion for JSON-LD keyword keys (`@id`, `@type`,
     * `@value`, `@list`, `@set`, `@language`, `@index`, etc).
     */
    private function expandKeywordValue(string $expandedKey, mixed $value): mixed
    {
        switch ($expandedKey) {
            case Keyword::Id->value:
                if (! is_string($value)) {
                    return null;
                }

                return $this->expandIri($value, documentRelative: true);

            case Keyword::Type->value:
                return $this->expandTypeValue($value);

            case Keyword::Value->value:
                // @value is always a scalar (string/int/float/bool) or null.
                if (is_array($value)) {
                    return null;
                }

                return $value;

            case Keyword::Language->value:
            case Keyword::Direction->value:
            case Keyword::Index->value:
                return is_string($value) ? $value : null;

            case Keyword::List->value:
                $expandedList = $this->expandElement($value, null);
                if (is_array($expandedList) && array_is_list($expandedList)) {
                    return $expandedList;
                }
                if ($expandedList === null) {
                    return [];
                }

                return [$expandedList];

            case Keyword::Set->value:
                $expandedSet = $this->expandElement($value, null);
                if (is_array($expandedSet) && array_is_list($expandedSet)) {
                    return $expandedSet;
                }
                if ($expandedSet === null) {
                    return [];
                }

                return [$expandedSet];

            case Keyword::Graph->value:
                $expandedGraph = $this->expandElement($value, Keyword::Graph->value);
                if (is_array($expandedGraph) && array_is_list($expandedGraph)) {
                    return $expandedGraph;
                }

                return $expandedGraph === null ? [] : [$expandedGraph];
        }

        // Unknown keyword — silently drop.
        return null;
    }

    /**
     * @type value expansion: each value is expanded as an IRI in vocab mode.
     *
     * @return list<string>|null
     */
    private function expandTypeValue(mixed $value): ?array
    {
        if (is_string($value)) {
            $expanded = $this->expandIri($value, vocab: true);

            return $expanded === null ? [] : [$expanded];
        }

        if (! is_array($value)) {
            return null;
        }

        $types = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $expanded = $this->expandIri($item, vocab: true);
            if ($expanded !== null) {
                $types[] = $expanded;
            }
        }

        return $types;
    }

    /**
     * §5.4 Value Expansion Algorithm. Produces a value object from a scalar
     * and the active property's term definition.
     *
     * @return array<string, mixed>
     */
    private function expandValue(string $activeProperty, mixed $value): array
    {
        $termDef = $this->termDefinitions->getTermDefinition($activeProperty);
        $typeMapping = $termDef !== null && isset($termDef['@type']) && is_string($termDef['@type'])
            ? $termDef['@type']
            : null;

        // Coerce to @id — produces a node reference, not a value object.
        if ($typeMapping === Keyword::Id->value && is_string($value)) {
            return [Keyword::Id->value => $this->expandIri($value, documentRelative: true) ?? $value];
        }

        // Coerce to @vocab — vocab-mode IRI expansion.
        if ($typeMapping === Keyword::Vocab->value && is_string($value)) {
            return [Keyword::Id->value => $this->expandIri($value, vocab: true) ?? $value];
        }

        // Typed literal.
        if ($typeMapping !== null && $typeMapping !== Keyword::Id->value && $typeMapping !== Keyword::Vocab->value) {
            $expandedType = $this->expandIri($typeMapping, vocab: true);
            // xsd:string is the default — omit @type to avoid noise.
            if (
                $expandedType === 'http://www.w3.org/2001/XMLSchema#string'
                || $expandedType === 'https://www.w3.org/2001/XMLSchema#string'
            ) {
                return [Keyword::Value->value => $value];
            }

            return [
                Keyword::Type->value => $expandedType ?? $typeMapping,
                Keyword::Value->value => $value,
            ];
        }

        // Plain value.
        return [Keyword::Value->value => $value];
    }

    /**
     * §5.2 IRI Expansion Algorithm.
     *
     * @param  bool  $vocab  If true, use @vocab as a fallback for undefined terms.
     * @param  bool  $documentRelative  If true, resolve relative IRIs against @base.
     */
    private function expandIri(string $value, bool $vocab = false, bool $documentRelative = false): ?string
    {
        // Step 1: null and keywords pass through.
        if ($this->isKeyword($value)) {
            return $value;
        }

        // Step 2: keyword-shaped but unknown → warn + null.
        if (str_starts_with($value, '@') && preg_match('/^@[A-Za-z]+$/', $value) === 1) {
            return null;
        }

        // Step 4-5: term definition lookup. Iterate when a term's IRI mapping
        // is itself a term (alias chains like AchievementCredential →
        // OpenBadgeCredential → https://…/OpenBadgeCredential). The spec's
        // Create Term Definition algorithm (§4.2.2) would normally pre-resolve
        // these during context processing; until that's wired up, we resolve
        // on demand here. `seen` guards against cycles.
        $seen = [];
        $current = $value;
        $resolvedThroughTermDef = false;
        while (! isset($seen[$current])) {
            $seen[$current] = true;
            $termDef = $this->termDefinitions->getTermDefinition($current);
            if (
                $termDef === null
                || ! isset($termDef['@id'])
                || ! is_string($termDef['@id'])
                || $termDef['@id'] === $current
            ) {
                break;
            }
            $mapping = $termDef['@id'];

            if ($this->isKeyword($mapping)) {
                return $mapping;
            }

            $current = $mapping;
            $resolvedThroughTermDef = true;
        }

        if ($resolvedThroughTermDef && $vocab) {
            // If iteration produced something that looks like an absolute
            // IRI, return it. Otherwise fall through to compact-IRI / @vocab
            // handling on the resolved value.
            if ($this->looksLikeAbsoluteIri($current)) {
                return $current;
            }
            $value = $current;
        } elseif ($resolvedThroughTermDef) {
            // Non-vocab mode: still return the resolved value if it's an
            // absolute IRI (matches v0.1.1 behaviour for @id contexts).
            if ($this->looksLikeAbsoluteIri($current)) {
                return $current;
            }
            $value = $current;
        }

        // Step 6: compact IRI / blank node / absolute IRI handling.
        if (str_contains($value, ':')) {
            [$prefix, $suffix] = explode(':', $value, 2);

            // Step 6.2: blank nodes and absolute IRIs.
            if ($prefix === '_' || str_starts_with($suffix, '//')) {
                return $value;
            }

            // Step 6.4: compact IRI expansion via prefix term def.
            $prefixDef = $this->termDefinitions->getTermDefinition($prefix);
            if ($prefixDef !== null && isset($prefixDef['@id']) && is_string($prefixDef['@id'])) {
                $prefixIri = $prefixDef['@id'];

                // The spec says compact-IRI expansion only applies when the
                // term definition is flagged as a prefix (@prefix: true), but
                // common practice (and the v0.1.1 behaviour we're replacing)
                // treats any term definition with a string IRI mapping as a
                // prefix. We follow that lenient interpretation here.
                return $prefixIri.$suffix;
            }

            // Step 6.5: if value has the form of an absolute IRI, return as-is.
            // We use a permissive check that accepts did:, urn:, mailto:,
            // etc — not just http(s)://.
            if ($this->looksLikeAbsoluteIri($value)) {
                return $value;
            }
        }

        // Step 7: @vocab fallback for vocab-mode IRI expansion.
        if ($vocab) {
            $vocabIri = $this->termDefinitions->getVocab();
            if ($vocabIri !== null) {
                return $vocabIri.$value;
            }
        }

        // Step 8: @base fallback for document-relative IRI expansion.
        // (Stub — @base isn't yet wired through ContextProcessor. When it
        // is, this should resolve `value` against the active base IRI per
        // RFC 3986 §5.3. For now we pass relative IRIs through unchanged.)
        if ($documentRelative) {
            return $value;
        }

        // Step 9: return as-is.
        return $value;
    }

    private function containerIs(?string $activeProperty, string $keyword): bool
    {
        if ($activeProperty === null) {
            return false;
        }
        $termDef = $this->termDefinitions->getTermDefinition($activeProperty);
        if ($termDef === null || ! isset($termDef['@container'])) {
            return false;
        }

        $container = $termDef['@container'];
        if (is_string($container)) {
            return $container === $keyword;
        }
        if (is_array($container)) {
            return in_array($keyword, $container, true);
        }

        return false;
    }

    /**
     * Conservative "already inside a list" check — to avoid double-wrapping
     * arrays that came from `@list: [...]` content. We don't yet track this
     * through recursive calls properly; this is a placeholder for the
     * recursion-state plumbing that would arrive in PR 4.5 (JsonLdOptions).
     */
    private function insideListContext(?string $activeProperty): bool
    {
        return false;
    }

    private function isKeyword(string $value): bool
    {
        return Keyword::contains($value);
    }

    /**
     * Cheap absolute-IRI detector — passes anything with a scheme followed
     * by a colon. Not RFC 3987-strict; intentionally permissive so `did:…`,
     * `urn:…`, `mailto:…` etc. all pass through unchanged.
     */
    private function looksLikeAbsoluteIri(string $value): bool
    {
        // Schemes are ALPHA *( ALPHA / DIGIT / "+" / "-" / "." ) per RFC 3986.
        return preg_match('/^[A-Za-z][A-Za-z0-9+\-.]*:/', $value) === 1;
    }
}
