<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Context\TermDefinitions;
use Accredify\JsonLd\Enums\Keyword;
use Accredify\JsonLd\Exceptions\JsonLdException;
use Accredify\JsonLd\Internal\IriResolver;

/**
 * JSON-LD 1.1 Expansion Algorithm.
 *
 * Implements §5.2 (IRI Expansion), §5.4 (Value Expansion), and §5.5
 * (Expansion) of the JSON-LD 1.1 API specification:
 * https://www.w3.org/TR/json-ld11-api/
 *
 * Implemented:
 *
 *  - Core Expansion Algorithm: drops free-floating nodes, wraps the result
 *    in an outer array, expands properties via IRI Expansion (vocab mode),
 *    handles `@id` / `@type` / `@list` / `@set`.
 *  - Value Expansion + value-object finalisation: term-definition `@type`
 *    coercion to `@id` / `@vocab` / datatype IRIs; value-object validation
 *    (disallowed keys, `@type`/`@language` mutual exclusion, language-tagged
 *    `@value` must be a string, `@value: null` drops); `@json` typed
 *    literals preserved verbatim.
 *  - Container handling: `@language`, `@index`, `@id`, `@type`, `@graph`,
 *    `@nest`.
 *  - `@reverse` — both the keyword (a map of reverse relations, with
 *    double-reverse folding back to forward) and reverse-property terms
 *    (`{"@reverse": "…"}`). Rejects `@value` / `@list` reverse values.
 *  - Type-scoped + property-scoped context activation (each object derives
 *    its own active context from documentBase).
 *  - IRI Expansion: keywords, blank nodes (`_:…`), full IRIs
 *    (`scheme://…`, `did:…`, `urn:…`), compact IRIs (`prefix:suffix`),
 *    `@vocab` fallback.
 *
 *  - `@base` / document-relative IRI resolution (RFC 3986 §5) for `@id`
 *    and `@type`, via {@see IriResolver}.
 *
 * Deferred (future Phase 4 PRs):
 *
 *  - `@included` blocks.
 *  - Relative / compact `@vocab` resolution.
 *  - `@propagate: true`; `@protected` enforcement; `@import` in contexts.
 *  - Spec-faithful error codes for the full negative-test surface.
 */
class Expansion
{
    /**
     * Document-level active context — the term definitions produced by
     * processing the document's `@context`. Stays constant during expansion;
     * nested objects always re-derive their per-object scope from this.
     */
    private readonly TermDefinitions $documentBase;

    /**
     * "Current" active context — what term lookups use right now. Equals
     * `$documentBase` except inside an object that has activated a
     * type-scoped or property-scoped overlay.
     */
    private TermDefinitions $termDefinitions;

    public function __construct(TermDefinitions $termDefinitions)
    {
        $this->documentBase = $termDefinitions;
        $this->termDefinitions = $termDefinitions;
    }

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

        // Top-level @graph unwrap: a result that is a single map containing
        // only @graph represents the default graph, so it is replaced by the
        // @graph contents (rather than becoming a free-floating named graph).
        if (
            ! array_is_list($expanded)
            && array_keys($expanded) === [Keyword::Graph->value]
            && is_array($expanded[Keyword::Graph->value])
        ) {
            $expanded = $expanded[Keyword::Graph->value];
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

        // Accumulates reverse relations (from the `@reverse` keyword and from
        // reverse-property terms). Merged into $result['@reverse'] at the end.
        /** @var array<string, list<mixed>> $reverseMap */
        $reverseMap = [];

        $previousActive = $this->termDefinitions;

        // §5.5 step 7: a non-propagated (type-scoped) context is rolled back
        // when a NEW node object is entered — unless this object is a value
        // object or a single-@id node reference. This confines a type-scoped
        // context to the node on which the @type appeared, while letting
        // property-scoped / embedded contexts (which propagate) flow in.
        $incoming = $this->termDefinitions;
        if (
            $incoming->getPreviousContext() !== null
            && ! array_key_exists(Keyword::Value->value, $obj)
            && ! $this->isSingleIdReference($obj)
        ) {
            $incoming = $incoming->getPreviousContext();
        }
        $this->termDefinitions = $incoming;

        // §5.5 step 9: an embedded @context (an inline term map, or array of
        // them) overlays onto the active context and PROPAGATES into nested
        // objects. Applied BEFORE type-scoped so type-scoped's previous-context
        // snapshot includes it.
        if (isset($obj[Keyword::Context->value])) {
            // An embedded node @context propagates into nested objects but may
            // NOT redefine protected terms (override-protected = false).
            $this->termDefinitions = $this->applyScopedContext($obj[Keyword::Context->value], $this->termDefinitions, overrideProtected: false);
        }

        // §5.5 step 11: type-scoped context activation. Built from the
        // post-embedded active context, recording it as the previous context
        // (type-scoped contexts do not propagate — @propagate = false).
        $typeScoped = $this->activateTypeScopedContexts($obj);
        if ($typeScoped !== null) {
            $this->termDefinitions = $typeScoped;
        }

        try {
            foreach ($obj as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }
                if ($key === Keyword::Context->value) {
                    // Context already merged upstream by ContextProcessor.
                    continue;
                }

                // @nest unwrapping: the value is a map whose entries are
                // treated as if they were direct properties of the parent.
                $termDef = $this->termDefinitions->getTermDefinition($key);
                $isNestKey = $key === Keyword::Nest->value
                    || ($termDef !== null && ($termDef['@id'] ?? null) === Keyword::Nest->value)
                    || $this->hasContainer($termDef, Keyword::Nest->value);
                if ($isNestKey) {
                    $this->mergeNestedObject($value, $result);

                    continue;
                }

                // Reverse-property term: a term whose definition carries a
                // `@reverse` mapping (e.g. `"isKnownBy": {"@reverse": "…knows"}`).
                // Its values are expanded as nodes and stored under the
                // reverse IRI in the @reverse map. (§5.5 step 13.7.)
                if ($termDef !== null && isset($termDef['@reverse']) && is_string($termDef['@reverse'])) {
                    $reverseIri = $this->expandIri($termDef['@reverse'], vocab: true);
                    if ($reverseIri === null) {
                        // A keyword-shaped @reverse (e.g. "@ignoreMe") expands
                        // to null — the term is silently ignored, not an error.
                        continue;
                    }
                    if (! $this->looksLikeAbsoluteIri($reverseIri) && ! str_starts_with($reverseIri, '_:')) {
                        throw new JsonLdException('Invalid reverse property: @reverse must expand to an IRI or blank node');
                    }
                    $this->collectReverseValues($reverseMap, $reverseIri, $this->expandElement($value, $key));

                    continue;
                }

                $expandedKey = $this->expandIri($key, vocab: true);
                if ($expandedKey === null) {
                    continue;
                }

                // @reverse keyword: a map of reverse relations. Each entry is
                // expanded and moved into this node's @reverse map; a nested
                // @reverse (double reverse) folds back to forward properties.
                if ($expandedKey === Keyword::Reverse->value) {
                    $this->expandReverseKeyword($value, $result, $reverseMap);

                    continue;
                }

                // @value is a literal — recorded verbatim (including null,
                // which finalizeValueObject uses to drop the object). It is
                // never expanded, so it bypasses expandKeywordValue.
                if ($expandedKey === Keyword::Value->value) {
                    if (array_key_exists(Keyword::Value->value, $result)) {
                        throw new JsonLdException('Colliding keywords: two properties expand to @value');
                    }
                    $result[Keyword::Value->value] = $value;

                    continue;
                }

                // JSON-LD keyword keys get special handling.
                if ($this->isKeyword($expandedKey)) {
                    $expandedValue = $this->expandKeywordValue($expandedKey, $value, $activeProperty);
                    if ($expandedValue !== null) {
                        // @type and @included may be contributed by more than
                        // one property and are merged; any other keyword
                        // appearing twice is a colliding-keywords error (§5.5).
                        if (
                            ($expandedKey === Keyword::Type->value || $expandedKey === Keyword::Included->value)
                            && array_key_exists($expandedKey, $result)
                        ) {
                            $existing = is_array($result[$expandedKey]) && array_is_list($result[$expandedKey])
                                ? $result[$expandedKey]
                                : [$result[$expandedKey]];
                            $incoming = is_array($expandedValue) && array_is_list($expandedValue)
                                ? $expandedValue
                                : [$expandedValue];
                            $result[$expandedKey] = array_merge($existing, $incoming);
                        } elseif (array_key_exists($expandedKey, $result)) {
                            throw new JsonLdException("Colliding keywords: two properties expand to {$expandedKey}");
                        } else {
                            $result[$expandedKey] = $expandedValue;
                        }
                    }

                    continue;
                }

                // §5.5 step 13: a property that did not expand to an absolute
                // IRI (no colon) and is not a keyword is an unmapped relative
                // term — it is dropped, not emitted with a relative predicate.
                if (! str_contains($expandedKey, ':')) {
                    continue;
                }

                // Property-scoped context: if the property's term def has
                // a `@context`, that overlay is active during the value's
                // expansion. We layer it on top of the current active
                // (which includes any type-scoped overlay), so the value
                // sees both the typed object's terms and the property's
                // own terms.
                // Property-scoped context: a term's @context is active while
                // expanding the property's value, and propagates into nested
                // node objects. Property-scoped contexts MAY redefine protected
                // terms (override-protected = true).
                $beforeValue = $this->termDefinitions;
                if ($termDef !== null && array_key_exists('@context', $termDef)) {
                    $this->termDefinitions = $this->applyScopedContext($termDef['@context'], $beforeValue, overrideProtected: true);
                }

                try {
                    // @json type coercion: a term with `@type: @json`
                    // preserves its value verbatim as a JSON literal,
                    // regardless of the value's shape (scalar, array, or
                    // object). It bypasses normal node/value expansion.
                    if ($this->isJsonTyped($termDef)) {
                        $this->mergeProperty($result, $expandedKey, [[
                            Keyword::Value->value => $value,
                            Keyword::Type->value => Keyword::Json->value,
                        ]]);

                        continue;
                    }

                    // Container handling: @language / @index / @id / @type
                    // / @graph maps each transform the value's shape before
                    // expansion. @list and @set are handled in expandArray
                    // / expandKeywordValue.
                    $containerHandled = $this->expandContainerValue($key, $value, $termDef);
                    if ($containerHandled !== null) {
                        $this->mergeProperty($result, $expandedKey, $containerHandled);

                        continue;
                    }

                    $expandedValue = $this->expandElement($value, $key);
                } finally {
                    $this->termDefinitions = $beforeValue;
                }

                if ($expandedValue === null) {
                    continue;
                }

                // Normalise to array (spec wraps property values as lists).
                // An empty result from a single node-object value is an EMPTY
                // NODE ({}), not an empty list — PHP can't tell [] (map) from
                // [] (list), so wrap it as [{}] when the input was a plain node
                // object (e.g. its only term was decoupled by a @context:null
                // reset). @set / @list / @value inputs keep list semantics.
                if (
                    $expandedValue === []
                    && is_array($value)
                    && ! array_is_list($value)
                    && ! array_key_exists(Keyword::Set->value, $value)
                    && ! array_key_exists(Keyword::List->value, $value)
                    && ! array_key_exists(Keyword::Value->value, $value)
                ) {
                    $list = [[]];
                } else {
                    $list = array_is_list($expandedValue)
                        ? $expandedValue
                        : [$expandedValue];
                }

                $this->mergeProperty($result, $expandedKey, $list);
            }
        } finally {
            $this->termDefinitions = $previousActive;
        }

        // Attach accumulated reverse relations (§5.5 step 13.7.4 / 13.4.6).
        if ($reverseMap !== []) {
            ksort($reverseMap);
            $result[Keyword::Reverse->value] = $reverseMap;
        }

        // @set unwrap (§5.5 step 13.4.5): the @set wrapper is dropped and its
        // already-expanded contents become the expansion result directly. An
        // empty @set therefore expands to nothing.
        if (array_key_exists(Keyword::Set->value, $result)) {
            $set = $result[Keyword::Set->value];

            return is_array($set) && array_is_list($set) ? $set : [$set];
        }

        // Value-object finalization (§5.5 step 15). If @value is present,
        // the object is a value object and is validated + normalised.
        if (array_key_exists(Keyword::Value->value, $result)) {
            return $this->finalizeValueObject($result);
        }

        // A bare {@language}/{@direction} object with no @value is a
        // free-floating language/direction with nothing to attach to —
        // dropped during expansion. (Matches W3C expand test #t0008's
        // "drop-lang-only" case.)
        if (
            ! isset($result[Keyword::Id->value])
            && (isset($result[Keyword::Language->value]) || isset($result[Keyword::Direction->value]))
            && ! $this->hasNonValueObjectProperty($result)
        ) {
            return null;
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

        // An empty object is dropped only when free-floating (§5.5 step 18:
        // active property null or @graph). As a property value it is kept as
        // an empty node object (e.g. a node whose only term was decoupled by a
        // scoped @context:null reset).
        if ($result === [] && ($activeProperty === null || $activeProperty === Keyword::Graph->value)) {
            return null;
        }

        ksort($result);

        return $result;
    }

    /**
     * Specialised expansion for JSON-LD keyword keys (`@id`, `@type`,
     * `@value`, `@list`, `@set`, `@language`, `@index`, etc).
     */
    private function expandKeywordValue(string $expandedKey, mixed $value, ?string $activeProperty = null): mixed
    {
        switch ($expandedKey) {
            case Keyword::Id->value:
                if (! is_string($value)) {
                    throw new JsonLdException('Invalid @id value: must be a string');
                }

                return $this->expandIri($value, documentRelative: true);

            case Keyword::Type->value:
                return $this->expandTypeValue($value);

                // Note: @value is handled directly in expandObject (recorded
                // verbatim, including null) and never reaches this method.

            case Keyword::Language->value:
                if (! is_string($value)) {
                    throw new JsonLdException('Invalid language-tagged string: @language must be a string');
                }

                return $value;

            case Keyword::Index->value:
                if (! is_string($value)) {
                    throw new JsonLdException('Invalid @index value: must be a string');
                }

                return $value;

            case Keyword::Direction->value:
                return is_string($value) ? $value : null;

            case Keyword::List->value:
            case Keyword::Set->value:
                // §5.5: @list / @set contents expand under the *active property*
                // (the property the value belongs to) so scalar items
                // value-expand rather than being dropped as free-floating. Items
                // are expanded individually so a `@container: @list` term does
                // NOT re-wrap them — the @list keyword already establishes the
                // list.
                return $this->expandKeywordItems($value, $activeProperty);

            case Keyword::Graph->value:
                $expandedGraph = $this->expandElement($value, Keyword::Graph->value);
                if (is_array($expandedGraph) && array_is_list($expandedGraph)) {
                    return $expandedGraph;
                }

                return $expandedGraph === null ? [] : [$expandedGraph];

            case Keyword::Included->value:
                // §5.5 step 13.4.14: @included contents are expanded as node
                // objects. The result must be a (non-empty) array of node
                // objects — a scalar, a value object, or a list object is an
                // "invalid @included value".
                $expandedIncluded = $this->expandElement($value, null);
                if ($expandedIncluded === null) {
                    throw new JsonLdException('Invalid @included value');
                }
                $includedList = array_is_list($expandedIncluded) ? $expandedIncluded : [$expandedIncluded];
                if ($includedList === []) {
                    throw new JsonLdException('Invalid @included value');
                }
                foreach ($includedList as $includedItem) {
                    if (
                        ! is_array($includedItem)
                        || array_is_list($includedItem)
                        || array_key_exists(Keyword::Value->value, $includedItem)
                        || array_key_exists(Keyword::List->value, $includedItem)
                    ) {
                        throw new JsonLdException('Invalid @included value');
                    }
                }

                return $includedList;
        }

        // Unknown keyword — silently drop.
        return null;
    }

    /**
     * Expand the members of an `@list` / `@set` value under the active
     * property, one item at a time, flattening one level. Expanding per item
     * avoids re-triggering a `@container: @list` wrap on the property.
     *
     * @return list<mixed>
     */
    private function expandKeywordItems(mixed $value, ?string $activeProperty): array
    {
        $items = is_array($value) && array_is_list($value) ? $value : [$value];

        $result = [];
        foreach ($items as $item) {
            $expanded = $this->expandElement($item, $activeProperty);
            if ($expanded === null) {
                continue;
            }
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

        return $result;
    }

    /**
     * @type value expansion: each value is expanded as an IRI in vocab mode.
     *
     * @return list<string>
     */
    private function expandTypeValue(mixed $value): array
    {
        // @type IRIs expand with both vocab and document-relative modes
        // (§5.5): @vocab takes precedence if set, otherwise a relative @type
        // resolves against @base.
        if (is_string($value)) {
            $expanded = $this->expandIri($value, vocab: true, documentRelative: true);

            return $expanded === null ? [] : [$expanded];
        }

        if (! is_array($value)) {
            throw new JsonLdException('Invalid type value: @type must be a string or an array of strings');
        }

        $types = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new JsonLdException('Invalid type value: @type must be a string or an array of strings');
            }
            $expanded = $this->expandIri($item, vocab: true, documentRelative: true);
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

        // Plain value. String values pick up @language / @direction: the
        // term definition's mapping wins (including an explicit null, which
        // suppresses the default), otherwise the active context's defaults
        // apply. Non-string values never carry language/direction.
        $result = [Keyword::Value->value => $value];
        if (is_string($value)) {
            $language = $this->effectiveLanguageOrDirection($termDef, Keyword::Language->value, $this->termDefinitions->getDefaultLanguage());
            $direction = $this->effectiveLanguageOrDirection($termDef, Keyword::Direction->value, $this->termDefinitions->getDefaultDirection());
            if (is_string($language) && $language !== '') {
                $result[Keyword::Language->value] = $language;
            }
            if (is_string($direction) && $direction !== '') {
                $result[Keyword::Direction->value] = $direction;
            }
            ksort($result);
        }

        return $result;
    }

    /**
     * Resolves the effective @language / @direction for a plain string value:
     * a term-definition mapping (if the key is present — even as null, which
     * suppresses the context default) takes precedence over the default.
     *
     * @param  array<array-key, mixed>|null  $termDef
     */
    private function effectiveLanguageOrDirection(?array $termDef, string $keyword, ?string $default): ?string
    {
        if ($termDef !== null && array_key_exists($keyword, $termDef)) {
            $mapped = $termDef[$keyword];

            return is_string($mapped) ? $mapped : null;
        }

        return $default;
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

        // Step 4: a term whose IRI mapping is a keyword resolves to that
        // keyword regardless of mode (e.g. a `type` → `@type` alias used as a
        // property key OR an @id value).
        $valueDef = $this->termDefinitions->getTermDefinition($value);
        if (
            $valueDef !== null
            && isset($valueDef['@id'])
            && is_string($valueDef['@id'])
            && $this->isKeyword($valueDef['@id'])
        ) {
            return $valueDef['@id'];
        }

        // Step 5: a term's IRI mapping only applies in vocab mode. For a
        // non-vocab value (e.g. an @id), a bare term is NOT resolved against a
        // term definition — it falls through to compact-IRI handling (step 6)
        // and then document-relative resolution (step 8).
        if ($vocab) {
            // A term explicitly mapped to null (`"term": null` / `{"@id": null}`)
            // is decoupled: it does not expand and must not fall back to @vocab.
            if ($valueDef !== null && array_key_exists('@id', $valueDef) && $valueDef['@id'] === null) {
                return null;
            }

            // Resolve alias chains (e.g. AchievementCredential →
            // OpenBadgeCredential → https://…/OpenBadgeCredential). The spec's
            // Create Term Definition algorithm pre-resolves these; we resolve
            // on demand. `seen` guards against cycles.
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

            if ($resolvedThroughTermDef) {
                // Continue IRI expansion on the resolved mapping so that a term
                // whose @id is itself a compact IRI (e.g. "label" → "rdfs:label")
                // is fully expanded via its prefix at step 6.
                $value = $current;
            }
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

        // Step 8: document-relative resolution against the active @base
        // (RFC 3986 §5). The base lives on documentBase — @base is a
        // document-level setting, so scoped overlays don't carry it.
        if ($documentRelative) {
            $base = $this->documentBase->getBase();
            if ($base !== null && $base !== '') {
                return IriResolver::resolve($base, $value);
            }
        }

        // Step 9: return as-is.
        return $value;
    }

    /**
     * Handles the `@reverse` keyword: a map of reverse relations. Each entry
     * is expanded and folded into the node's reverse map; a nested `@reverse`
     * (double reverse) folds back into forward properties on $result.
     *
     * @param  array<string, mixed>  $result  forward properties (modified in place)
     * @param  array<string, list<mixed>>  $reverseMap  reverse relations (modified in place)
     */
    private function expandReverseKeyword(mixed $value, array &$result, array &$reverseMap): void
    {
        // The @reverse value must be a map (node object), not a list/scalar.
        if (! is_array($value) || array_is_list($value)) {
            throw new JsonLdException('Invalid @reverse value: must be a map');
        }

        $expanded = $this->expandObject($value, Keyword::Reverse->value);
        if (! is_array($expanded) || array_is_list($expanded)) {
            return;
        }

        foreach ($expanded as $prop => $items) {
            if (! is_string($prop) || ! is_array($items)) {
                continue;
            }

            // A nested @reverse inside a @reverse is a double reverse — its
            // entries are forward properties of the current node.
            if ($prop === Keyword::Reverse->value) {
                foreach ($items as $fwdProp => $fwdItems) {
                    if (is_string($fwdProp) && is_array($fwdItems)) {
                        $this->mergeProperty($result, $fwdProp, array_values($fwdItems));
                    }
                }

                continue;
            }

            $this->collectReverseValues($reverseMap, $prop, $items);
        }
    }

    /**
     * Appends expanded values to a reverse-property entry, rejecting value
     * objects and list objects (reverse properties can only reference nodes).
     *
     * @param  array<string, list<mixed>>  $reverseMap  modified in place
     */
    private function collectReverseValues(array &$reverseMap, string $reverseIri, mixed $expandedValue): void
    {
        if ($expandedValue === null) {
            return;
        }
        $items = is_array($expandedValue) && array_is_list($expandedValue)
            ? $expandedValue
            : [$expandedValue];

        foreach ($items as $item) {
            if (is_array($item) && (array_key_exists(Keyword::Value->value, $item) || isset($item[Keyword::List->value]))) {
                throw new JsonLdException('Invalid reverse property value: reverse properties cannot have @value or @list values');
            }
        }

        $reverseMap[$reverseIri] = array_merge($reverseMap[$reverseIri] ?? [], $items);
    }

    /**
     * §5.5 step 15: validate + normalise a value object.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function finalizeValueObject(array $result): ?array
    {
        $allowed = [
            Keyword::Value->value => true,
            Keyword::Type->value => true,
            Keyword::Language->value => true,
            Keyword::Index->value => true,
            Keyword::Direction->value => true,
        ];
        foreach (array_keys($result) as $resultKey) {
            if (! isset($allowed[$resultKey])) {
                throw new JsonLdException("Invalid value object: unexpected key '{$resultKey}'");
            }
        }

        $hasType = isset($result[Keyword::Type->value]);
        $hasLanguage = isset($result[Keyword::Language->value]);
        $hasDirection = isset($result[Keyword::Direction->value]);

        // @type is mutually exclusive with @language / @direction.
        if ($hasType && ($hasLanguage || $hasDirection)) {
            throw new JsonLdException('Invalid value object: @type cannot coexist with @language or @direction');
        }

        $value = $result[Keyword::Value->value];

        // @value: null → the value object is dropped.
        if ($value === null) {
            return null;
        }

        // A language-tagged value requires a string @value.
        if ($hasLanguage && ! is_string($value)) {
            throw new JsonLdException('Invalid language-tagged value: @value must be a string when @language is present');
        }

        // @json values are preserved verbatim — no further constraints.
        $type = $hasType ? $result[Keyword::Type->value] : null;
        $isJson = $type === Keyword::Json->value
            || (is_array($type) && in_array(Keyword::Json->value, $type, true));

        // For non-@json value objects, @value must be a scalar.
        if (! $isJson && ! is_scalar($value)) {
            throw new JsonLdException('Invalid value object: @value must be a scalar');
        }

        // Inside a value object, @type is a single datatype IRI (a scalar
        // string), not an array — expandTypeValue produces a list, so collapse
        // a single-element list back to a scalar. (Node-object @type, which
        // stays an array, never reaches finalizeValueObject.)
        if (isset($result[Keyword::Type->value]) && is_array($result[Keyword::Type->value]) && count($result[Keyword::Type->value]) === 1) {
            $result[Keyword::Type->value] = $result[Keyword::Type->value][0];
        }

        // A value object's @type must be a single, well-formed, absolute IRI
        // (or @json). A multi-element datatype, a blank node, a relative IRI,
        // or an IRI containing whitespace is an "invalid typed value".
        if (! $isJson && isset($result[Keyword::Type->value])) {
            $typeValue = $result[Keyword::Type->value];
            $invalid = is_array($typeValue)
                || ! is_string($typeValue)
                || str_starts_with($typeValue, '_:')
                || ! $this->looksLikeAbsoluteIri($typeValue)
                || preg_match('/\s/', $typeValue) === 1;
            if ($invalid) {
                throw new JsonLdException('Invalid typed value: @type must be a single absolute IRI');
            }
        }

        ksort($result);

        return $result;
    }

    /**
     * True if a result map carries any key that isn't a value-object
     * keyword — i.e. it's a node object rather than a (malformed) value
     * object. Used to decide whether a `{@language}`-only object should be
     * dropped.
     *
     * @param  array<string, mixed>  $result
     */
    private function hasNonValueObjectProperty(array $result): bool
    {
        $valueObjectKeywords = [
            Keyword::Value->value => true,
            Keyword::Type->value => true,
            Keyword::Language->value => true,
            Keyword::Index->value => true,
            Keyword::Direction->value => true,
        ];
        foreach (array_keys($result) as $resultKey) {
            if (! isset($valueObjectKeywords[$resultKey])) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if the term definition coerces its values to `@json` literals.
     *
     * @param  array<array-key, mixed>|null  $termDef
     */
    private function isJsonTyped(?array $termDef): bool
    {
        return $termDef !== null
            && isset($termDef['@type'])
            && $termDef['@type'] === Keyword::Json->value;
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

    /**
     * §5.5 step 12: collect the @type values of the object and overlay each
     * type's nested `@context` onto a fresh active context derived from
     * documentBase. Returns null if no types declare a scoped context.
     *
     * True if the object is a single node reference (its only entry expands
     * to @id) — such an object does not trigger a type-scoped rollback.
     *
     * @param  array<array-key, mixed>  $obj
     */
    private function isSingleIdReference(array $obj): bool
    {
        if (count($obj) !== 1) {
            return false;
        }
        $key = array_key_first($obj);

        return is_string($key) && $this->expandIri($key, vocab: true) === Keyword::Id->value;
    }

    /**
     * True if a scoped @context (a map, or array of layers) carries an
     * explicit `@propagate: false` — meaning it must not propagate into nested
     * node objects.
     */
    private function contextPropagateFalse(mixed $context): bool
    {
        $layers = is_array($context) && array_is_list($context) ? $context : [$context];
        foreach ($layers as $layer) {
            if (is_array($layer) && ($layer[Keyword::Propagate->value] ?? null) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply a scoped @context (property-scoped / embedded / type-scoped) onto a
     * base active context and return the resulting context. Handles:
     *  - a null / [null] layer → reset to a fresh context (the original
     *    document base is preserved via {@see $documentBase}); when
     *    override-protected is false, clearing protected terms is an "invalid
     *    context nullification";
     *  - inline map layers → overlaid (protected-aware per $overrideProtected);
     *  - an explicit @propagate:false → records the base as the previous
     *    context so the scope does not propagate into nested node objects.
     */
    private function applyScopedContext(mixed $context, TermDefinitions $base, bool $overrideProtected): TermDefinitions
    {
        $layers = is_array($context) && array_is_list($context) ? $context : [$context];

        $active = new TermDefinitions($base->termDefinitions);
        $vocab = $base->getVocab();
        if ($vocab !== null) {
            $active->setVocab($vocab);
        }

        foreach ($layers as $layer) {
            if ($layer === null) {
                if (! $overrideProtected && $active->hasAnyProtected()) {
                    throw new JsonLdException('Invalid context nullification: a null context cannot clear protected terms');
                }
                $active = new TermDefinitions;
            } elseif (is_array($layer)) {
                $this->overlayContextOnto($active, $layer, $overrideProtected);
            }
        }

        if ($this->contextPropagateFalse($context)) {
            $active->setPreviousContext($base);
        }

        return $active;
    }

    /**
     * @param  array<array-key, mixed>  $obj
     */
    private function activateTypeScopedContexts(array $obj): ?TermDefinitions
    {
        // Types come from either `@type` directly, or any alias the active
        // context maps to `@type`. We look at the current active context for
        // alias resolution.
        $rawTypes = [];
        foreach ($obj as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if ($key === Keyword::Type->value) {
                $rawTypes = array_merge($rawTypes, is_array($value) ? $value : [$value]);

                continue;
            }
            $td = $this->termDefinitions->getTermDefinition($key);
            if ($td !== null && isset($td['@id']) && $td['@id'] === Keyword::Type->value) {
                $rawTypes = array_merge($rawTypes, is_array($value) ? $value : [$value]);
            }
        }

        $types = [];
        foreach ($rawTypes as $t) {
            if (is_string($t)) {
                $types[] = $t;
            }
        }
        if ($types === []) {
            return null;
        }

        // Spec §5.5 step 12.1: sort types alphabetically so activation is
        // deterministic when multiple types overlap.
        sort($types);

        $scoped = null;
        foreach ($types as $type) {
            // Types may be defined inside another term's nested @context
            // (e.g. DataIntegrityProof inside an imported security context).
            // We allow recursive lookup HERE so the scope can activate, but
            // not for regular property resolution where leaking would
            // violate spec scoping.
            $typeDef = $this->documentBase->getTermDefinition($type)
                ?? $this->findTypeDefRecursive($type, $this->documentBase->termDefinitions);
            if (
                $typeDef === null
                || ! isset($typeDef['@context'])
                || ! is_array($typeDef['@context'])
            ) {
                continue;
            }

            if ($scoped === null) {
                // Initialise from the active (rolled-back) context. Copy term
                // definitions so the overlay doesn't mutate it, and record the
                // pre-type-scoped context as the previous context so a nested
                // node object rolls type-scoped terms back (§5.5 step 7;
                // type-scoped contexts have @propagate = false).
                $scoped = new TermDefinitions($this->termDefinitions->termDefinitions);
                $vocab = $this->termDefinitions->getVocab();
                if ($vocab !== null) {
                    $scoped->setVocab($vocab);
                }
                $scoped->setPreviousContext($this->termDefinitions);
            }

            // Type-scoped contexts may NOT redefine protected terms
            // (override-protected = false).
            $this->overlayContextOnto($scoped, $typeDef['@context'], overrideProtected: false);
        }

        return $scoped;
    }

    /**
     * Recursively searches for a type's term definition by walking into any
     * nested `@context` entries it encounters. Used ONLY for type lookup
     * during scope activation — not for general property resolution, where
     * the spec requires strict scope isolation.
     *
     * @param  array<array-key, mixed>  $haystack
     * @return array<array-key, mixed>|null
     */
    private function findTypeDefRecursive(string $type, array $haystack): ?array
    {
        foreach ($haystack as $term => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            if ($term === $type && isset($definition['@context'])) {
                return $definition;
            }
            if (isset($definition['@context']) && is_array($definition['@context'])) {
                $nested = $this->findTypeDefRecursive($type, $definition['@context']);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * Overlays the entries of a scoped @context onto an existing
     * {@see TermDefinitions}. Keyword entries (`@vocab`, `@base`, …) update
     * the relevant active-context state; everything else is added as a term
     * definition, replacing any existing entry for the same key.
     *
     * @param  array<array-key, mixed>  $context
     * @param  bool  $overrideProtected  True for property-scoped contexts (and
     *                                   `@import`), which may redefine a
     *                                   protected term; false for type-scoped
     *                                   and embedded node contexts.
     */
    private function overlayContextOnto(TermDefinitions $target, array $context, bool $overrideProtected = false): void
    {
        $protectedContext = ($context[Keyword::Protected->value] ?? null) === true;

        foreach ($context as $term => $definition) {
            if (! is_string($term)) {
                continue;
            }

            if ($term === Keyword::Vocab->value && is_string($definition)) {
                $target->pushVocab($definition);

                continue;
            }
            if (str_starts_with($term, '@')) {
                // Other keyword overrides (@base, @language, @direction, etc.)
                // are out of scope for this PR.
                continue;
            }

            if (! is_string($definition) && ! is_array($definition)) {
                continue;
            }

            // Replace any existing entry — scoped contexts intentionally
            // shadow the base — but enforce protected-term redefinition rules
            // (which depend on the scope's override-protected flag). overlayTerm
            // skips the term-syntax check so keyword-alias / compact terms in a
            // scoped context still apply.
            $normalized = is_string($definition) ? [Keyword::Id->value => $definition] : $definition;
            $target->overlayTerm($term, $normalized, $protectedContext, $overrideProtected);
        }
    }

    /**
     * @param  array<array-key, mixed>|null  $termDef
     */
    private function hasContainer(?array $termDef, string $keyword): bool
    {
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
     * Dispatches a property's value through the appropriate container-handling
     * routine. Returns the expanded list of values, or null if no container
     * applies and the caller should fall through to default expansion.
     *
     * @param  array<array-key, mixed>|null  $termDef
     * @return list<mixed>|null
     */
    private function expandContainerValue(string $key, mixed $value, ?array $termDef): ?array
    {
        if ($termDef === null || ! isset($termDef['@container'])) {
            return null;
        }

        // @language map: {en: "hi", fr: "salut"} → list of value objects with @language.
        if ($this->hasContainer($termDef, Keyword::Language->value) && is_array($value) && ! array_is_list($value)) {
            return $this->expandLanguageMap($value);
        }

        // @index map: {first: ..., second: ...} → list of expanded nodes with @index.
        if ($this->hasContainer($termDef, Keyword::Index->value) && is_array($value) && ! array_is_list($value)) {
            return $this->expandIndexMap($key, $value);
        }

        // @id map: {"urn:1": {...}, "urn:2": {...}} → list of nodes with @id set.
        if ($this->hasContainer($termDef, Keyword::Id->value) && is_array($value) && ! array_is_list($value)) {
            return $this->expandIdMap($key, $value);
        }

        // @type map: {Person: {...}, Animal: {...}} → list of nodes with @type set.
        if ($this->hasContainer($termDef, Keyword::Type->value) && is_array($value) && ! array_is_list($value)) {
            return $this->expandTypeMap($key, $value);
        }

        // @graph container: value is wrapped in a graph object.
        if ($this->hasContainer($termDef, Keyword::Graph->value)) {
            return $this->expandGraphContainer($key, $value);
        }

        return null;
    }

    /**
     * §5.5 step 13.4.7 — @language container.
     *
     * @param  array<array-key, mixed>  $map
     * @return list<array<string, mixed>>
     */
    private function expandLanguageMap(array $map): array
    {
        $result = [];
        foreach ($map as $language => $entry) {
            if (! is_string($language)) {
                continue;
            }
            // Each entry can be a single string or a list of strings.
            $items = is_array($entry) && array_is_list($entry) ? $entry : [$entry];
            foreach ($items as $item) {
                if ($item === null) {
                    continue;
                }
                if (! is_string($item)) {
                    throw new JsonLdException('Invalid language map value: values must be strings');
                }
                $valueObject = [Keyword::Value->value => $item];
                if ($language !== Keyword::None->value) {
                    $valueObject[Keyword::Language->value] = $language;
                }
                ksort($valueObject);
                $result[] = $valueObject;
            }
        }

        return $result;
    }

    /**
     * §5.5 step 13.4.9 — @index container.
     *
     * @param  array<array-key, mixed>  $map
     * @return list<mixed>
     */
    private function expandIndexMap(string $activeProperty, array $map): array
    {
        // Property-valued index (§5.5): when the active property's term sets
        // @index to a property IRI (rather than the @index keyword), each map
        // key is attached as a *value* of that property on the expanded item,
        // instead of as @index metadata.
        $termDef = $this->termDefinitions->getTermDefinition($activeProperty);
        $indexKey = is_array($termDef) && isset($termDef[Keyword::Index->value]) && is_string($termDef[Keyword::Index->value])
            ? $termDef[Keyword::Index->value]
            : Keyword::Index->value;
        $indexProperty = $indexKey !== Keyword::Index->value
            ? $this->expandIri($indexKey, vocab: true)
            : null;

        $result = [];
        foreach ($map as $index => $entry) {
            if (! is_string($index)) {
                continue;
            }
            $items = is_array($entry) && array_is_list($entry) ? $entry : [$entry];
            foreach ($items as $item) {
                $expanded = $this->expandElement($item, $activeProperty);
                if ($expanded === null) {
                    continue;
                }
                $list = array_is_list($expanded) ? $expanded : [$expanded];
                foreach ($list as $expandedItem) {
                    if (is_array($expandedItem) && ! array_is_list($expandedItem) && $index !== Keyword::None->value) {
                        /** @var array<string, mixed> $expandedItem */
                        if ($indexProperty !== null) {
                            // A property-valued index entry must expand to a
                            // node object, never a value object.
                            if (array_key_exists(Keyword::Value->value, $expandedItem)) {
                                throw new JsonLdException('Invalid value object: a property-valued index entry must be a node object');
                            }
                            $indexValue = $this->expandValue($indexKey, $index);
                            $existing = isset($expandedItem[$indexProperty]) && is_array($expandedItem[$indexProperty])
                                ? $expandedItem[$indexProperty]
                                : [];
                            array_unshift($existing, $indexValue);
                            $expandedItem[$indexProperty] = $existing;
                        } else {
                            $expandedItem[Keyword::Index->value] = $index;
                        }
                        ksort($expandedItem);
                    }
                    $result[] = $expandedItem;
                }
            }
        }

        return $result;
    }

    /**
     * §5.5 step 13.4.8 — @id container.
     *
     * @param  array<array-key, mixed>  $map
     * @return list<mixed>
     */
    private function expandIdMap(string $activeProperty, array $map): array
    {
        $result = [];
        foreach ($map as $id => $entry) {
            if (! is_string($id)) {
                continue;
            }
            $items = is_array($entry) && array_is_list($entry) ? $entry : [$entry];
            foreach ($items as $item) {
                $expanded = $this->expandElement($item, $activeProperty);
                if ($expanded === null) {
                    continue;
                }
                $list = array_is_list($expanded) ? $expanded : [$expanded];
                foreach ($list as $expandedItem) {
                    if (is_array($expandedItem) && ! array_is_list($expandedItem) && $id !== Keyword::None->value) {
                        /** @var array<string, mixed> $expandedItem */
                        $expandedId = $this->expandIri($id, documentRelative: true);
                        if ($expandedId !== null) {
                            $expandedItem[Keyword::Id->value] = $expandedId;
                            ksort($expandedItem);
                        }
                    }
                    $result[] = $expandedItem;
                }
            }
        }

        return $result;
    }

    /**
     * §5.5 step 13.4.8 — @type container.
     *
     * @param  array<array-key, mixed>  $map
     * @return list<mixed>
     */
    private function expandTypeMap(string $activeProperty, array $map): array
    {
        $result = [];
        foreach ($map as $type => $entry) {
            if (! is_string($type)) {
                continue;
            }
            $items = is_array($entry) && array_is_list($entry) ? $entry : [$entry];
            foreach ($items as $item) {
                $expanded = $this->expandElement($item, $activeProperty);
                if ($expanded === null) {
                    continue;
                }
                $list = array_is_list($expanded) ? $expanded : [$expanded];
                foreach ($list as $expandedItem) {
                    if (is_array($expandedItem) && ! array_is_list($expandedItem) && $type !== Keyword::None->value) {
                        /** @var array<string, mixed> $expandedItem */
                        $expandedType = $this->expandIri($type, vocab: true);
                        if ($expandedType !== null) {
                            $existing = isset($expandedItem[Keyword::Type->value]) && is_array($expandedItem[Keyword::Type->value])
                                ? $expandedItem[Keyword::Type->value]
                                : [];
                            array_unshift($existing, $expandedType);
                            $expandedItem[Keyword::Type->value] = $existing;
                            ksort($expandedItem);
                        }
                    }
                    $result[] = $expandedItem;
                }
            }
        }

        return $result;
    }

    /**
     * §5.5 step 13.4.10 — @graph container. The property's value is wrapped
     * in a `@graph` object.
     *
     * @return list<array<string, mixed>>
     */
    private function expandGraphContainer(string $activeProperty, mixed $value): array
    {
        $expanded = $this->expandElement($value, $activeProperty);
        if ($expanded === null) {
            return [];
        }
        $graphContent = array_is_list($expanded) ? $expanded : [$expanded];

        return [[Keyword::Graph->value => $graphContent]];
    }

    /**
     * §5.5 step 13.4.4 — @nest. The nested object's keys are treated as if
     * they were direct properties of the parent.
     *
     * @param  array<string, mixed>  $result  modified in place
     */
    private function mergeNestedObject(mixed $value, array &$result): void
    {
        // §5.5 step 13.4.4: the value of an @nest key MUST be a node object or
        // an array of node objects — a scalar is an "invalid @nest value".
        $items = is_array($value) && array_is_list($value) ? $value : [$value];

        foreach ($items as $nested) {
            // Flatten a nested array one level (an array of arrays of nests).
            if (is_array($nested) && array_is_list($nested)) {
                $this->mergeNestedObject($nested, $result);

                continue;
            }
            if (! is_array($nested)) {
                throw new JsonLdException('Invalid @nest value');
            }

            // A nest object must not be a value object: no key may expand to
            // @value.
            foreach (array_keys($nested) as $nestedKey) {
                if (is_string($nestedKey) && $this->expandIri($nestedKey, vocab: true) === Keyword::Value->value) {
                    throw new JsonLdException('Invalid @nest value');
                }
            }

            // Recursively expand the nested object as if it were the parent,
            // then merge its keys into $result.
            $expandedNested = $this->expandObject($nested, null);
            if (! is_array($expandedNested) || array_is_list($expandedNested)) {
                continue;
            }

            foreach ($expandedNested as $nestedKey => $nestedValue) {
                if (! is_string($nestedKey)) {
                    continue;
                }
                $list = is_array($nestedValue) && array_is_list($nestedValue) ? $nestedValue : [$nestedValue];
                $this->mergeProperty($result, $nestedKey, $list);
            }
        }
    }

    /**
     * Merges a list of expanded values into the result map under
     * `$expandedKey`, concatenating if the key is already present.
     *
     * @param  array<string, mixed>  $result  modified in place
     * @param  list<mixed>  $values
     */
    private function mergeProperty(array &$result, string $expandedKey, array $values): void
    {
        if (isset($result[$expandedKey])) {
            /** @var array<mixed> $existing */
            $existing = is_array($result[$expandedKey]) && array_is_list($result[$expandedKey])
                ? $result[$expandedKey]
                : [$result[$expandedKey]];
            $result[$expandedKey] = array_merge($existing, $values);
        } else {
            $result[$expandedKey] = $values;
        }
    }
}
