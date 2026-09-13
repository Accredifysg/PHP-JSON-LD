<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Context\ContextProcessor;
use Accredify\JsonLd\Context\TermDefinitions;
use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Enums\Keyword;
use Accredify\JsonLd\Exceptions\DataLossException;
use Accredify\JsonLd\Exceptions\JsonLdException;
use Accredify\JsonLd\Internal\IriResolver;
use Accredify\JsonLd\JsonLdOptions;

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
     * Sentinel for a frame wildcard (`{}`). The associative-array document
     * model cannot tell an empty object `{}` from an empty array `[]`, so a
     * frame's `{}` is decoded to this marker and carried verbatim through
     * frame expansion, letting the framing matcher tell a wildcard (match any
     * present value) from `match none` (the empty list `[]`).
     */
    public const FRAME_WILDCARD = '@__wildcard__';

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

    /**
     * Optional loader, used to resolve remote / `@import` scoped contexts
     * (a term definition's `@context` that is a string URL or carries
     * `@import`). When null, only inline scoped contexts are applied.
     */
    private readonly ?DocumentLoader $documentLoader;

    /**
     * The property-scoped context most recently applied by the property loop,
     * tracked only when it is non-propagating (@propagate: false → it carries
     * a previous context). Per §5.5 a property-scoped context is applied
     * AFTER the step-7 previous-context rollback, so the property's IMMEDIATE
     * value object(s) keep the scope; it rolls back one node level deeper.
     * Tracking the instance lets the entry rollback in {@see expandObject}
     * skip exactly those immediate objects (#tso06).
     */
    private ?TermDefinitions $freshPropertyScope = null;

    public function __construct(
        TermDefinitions $termDefinitions,
        ?DocumentLoader $documentLoader = null,
        private readonly bool $frameExpansion = false,
        private readonly bool $safe = false,
    ) {
        $this->documentBase = $termDefinitions;
        $this->termDefinitions = $termDefinitions;
        $this->documentLoader = $documentLoader;
    }

    /**
     * Safe mode ({@see JsonLdOptions::$safe}): throw for a
     * drop site instead of letting the caller silently discard the datum.
     * No-op when safe mode is off — the caller then performs the default
     * (spec-sanctioned lossy) drop.
     *
     * @param  array<string, mixed>  $details
     *
     * @throws DataLossException
     */
    private function safeModeDrop(string $eventCode, string $message, array $details = []): void
    {
        if ($this->safe) {
            throw new DataLossException($eventCode, $message, $details);
        }
    }

    /**
     * The jsonld.js event code for a free-floating object about to be dropped
     * (§5.5 step 18), picked by shape as jsonld.js's `_dropUnsafeObject` does.
     * Only meaningful for maps that {@see isFreeFloating()} accepted.
     *
     * @param  array<array-key, mixed>  $item
     */
    private function freeFloatingEventCode(array $item): string
    {
        if (array_key_exists(Keyword::Value->value, $item)) {
            return 'object with only @value';
        }
        if (array_key_exists(Keyword::List->value, $item)) {
            return 'object with only @list';
        }

        return 'object with only @id';
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

        // If the result is a list, flatten it and drop free-floating items
        // (§5.5 step 18): at the top level a value object, a node with only an
        // @id, or a @list object carries no statement and is removed
        // (#t0045/#t0046/#t0047).
        if (array_is_list($expanded)) {
            $out = [];
            foreach ($expanded as $item) {
                if ($item === null) {
                    continue;
                }
                if (is_array($item) && ! array_is_list($item)) {
                    if (! $this->frameExpansion && $this->isFreeFloating($item)) {
                        $this->safeModeDrop(
                            $this->freeFloatingEventCode($item),
                            'a free-floating object at the top level carries no statement and is dropped',
                            ['object' => $item],
                        );

                        continue;
                    }
                    /** @var array<string, mixed> $item */
                    $out[] = $item;
                }
            }

            return $out;
        }

        // Single object → wrap in list, unless it is free-floating at the top
        // level (a lone value object / @list / @id-only node), which is dropped
        // (§5.5 step 18 / #t0045). Frame expansion keeps such patterns.
        /** @var array<string, mixed> $expanded */
        if (! $this->frameExpansion && $this->isFreeFloating($expanded)) {
            $this->safeModeDrop(
                $this->freeFloatingEventCode($expanded),
                'a free-floating object at the top level carries no statement and is dropped',
                ['object' => $expanded],
            );

            return [];
        }

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
            // A frame legitimately places match patterns where a document
            // cannot place data; dropping them is not data loss.
            if (! $this->frameExpansion) {
                $this->safeModeDrop(
                    'free-floating scalar',
                    'a scalar at the top level or directly inside @graph carries no statement and is dropped',
                    ['value' => $element],
                );
            }

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
            $this->assertNoListOfLists($result);

            return [['@list' => $result]];
        }

        return $result;
    }

    /**
     * §5.5: in JSON-LD 1.0 a list may not contain another list. (JSON-LD 1.1
     * lifted this restriction, so the check is gated on the 1.0 processing
     * mode — #ter24 / #ter32.)
     *
     * @param  array<mixed>  $items
     */
    private function assertNoListOfLists(array $items): void
    {
        if (! $this->termDefinitions->isJson10()) {
            return;
        }

        foreach ($items as $item) {
            if (is_array($item) && array_key_exists(Keyword::List->value, $item)) {
                throw new JsonLdException('List of lists: a @list value may not contain another list in JSON-LD 1.0');
            }
        }
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
        // A frame wildcard ({}) is carried verbatim so the matcher can tell it
        // from an empty list (match none); see {@see self::FRAME_WILDCARD}.
        if ($this->frameExpansion && array_key_exists(self::FRAME_WILDCARD, $obj)) {
            return [self::FRAME_WILDCARD => true];
        }

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
        $enteredFreshPropertyScope = $incoming === $this->freshPropertyScope ? $incoming : null;
        if ($enteredFreshPropertyScope !== null) {
            // §5.5: a property-scoped context (even @propagate: false) is
            // applied AFTER the previous-context rollback, so the property's
            // immediate value keeps the scope; it rolls back one node level
            // deeper (#tso06). Cleared for this subtree, restored in the
            // finally below so array siblings each keep the scope too.
            $this->freshPropertyScope = null;
        } elseif (
            $incoming->getPreviousContext() !== null
            && ! $this->objectHasKeyExpandingToValue($obj, $incoming)
            && ! $this->isSingleIdReference($obj)
        ) {
            $incoming = $incoming->getPreviousContext();
        }
        $this->termDefinitions = $incoming;

        // §5.5 step 9: an embedded @context (an inline term map, or array of
        // them) overlays onto the active context and PROPAGATES into nested
        // objects. Applied BEFORE type-scoped so type-scoped's previous-context
        // snapshot includes it. array_key_exists (not isset) so an explicit
        // `@context: null` performs the full context reset (#t0016/#t0060).
        if (array_key_exists(Keyword::Context->value, $obj)) {
            // An embedded node @context propagates into nested objects but may
            // NOT redefine protected terms (override-protected = false).
            $this->termDefinitions = $this->applyScopedContext($obj[Keyword::Context->value], $this->termDefinitions, overrideProtected: false);
        }

        // The active context as it stands after the node's embedded @context
        // but BEFORE per-type type-scoped contexts are applied. Per §5.5 the
        // node's @type VALUES are IRI-expanded against THIS context, so a
        // type-scoped @vocab / null-reset / term change introduced by a type
        // does not affect how that type is itself expressed (#tc010 / #tc014 /
        // #tc016 / #tc018).
        $preTypeScoped = $this->termDefinitions;

        // §5.5 step 11: type-scoped context activation. Built from the
        // post-embedded active context, recording it as the previous context
        // (type-scoped contexts do not propagate — @propagate = false).
        $typeScoped = $this->activateTypeScopedContexts($obj);
        if ($typeScoped !== null) {
            $this->termDefinitions = $typeScoped;
        }

        // @nest values are collected here and merged in a SECOND pass after all
        // base properties, so colliding arrays read [base, …, nested] (§5.5).
        // Each entry carries the nest term's definition so a property-scoped
        // @context on the nest alias still applies in that pass (#tc037).
        /** @var list<array{array<array-key, mixed>|null, mixed}> $deferredNestValues */
        $deferredNestValues = [];

        // §5.5: expansion processes object keys in lexicographic (code-point)
        // order, so arrays accumulated from sibling keys that map to the same
        // property (e.g. @type plus a `type` alias, or values across @nest
        // aliases) are deterministically ordered (#tpr30/#tn004).
        ksort($obj, SORT_STRING);

        try {
            foreach ($obj as $key => $value) {
                if (! is_string($key)) {
                    // PHP decodes a numeric-string JSON key ("1") to an int
                    // key, which jsonld.js would process as a normal term.
                    $this->safeModeDrop(
                        'invalid property',
                        "property '{$key}' has a non-string (numeric) key and is dropped",
                        ['property' => $key],
                    );

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
                    // Defer to the second pass (#tn003/#tn005/#tn006/#tn007).
                    $deferredNestValues[] = [$termDef, $value];

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
                        $this->safeModeDrop(
                            'reserved @reverse value',
                            "reverse property '{$key}' has @reverse '{$termDef['@reverse']}', which does not expand to an IRI; the property is dropped",
                            ['term' => $key, 'reverse' => $termDef['@reverse']],
                        );

                        continue;
                    }
                    if (! $this->looksLikeAbsoluteIri($reverseIri) && ! str_starts_with($reverseIri, '_:')) {
                        throw new JsonLdException('Invalid reverse property: @reverse must expand to an IRI or blank node');
                    }
                    // A reverse-property term may itself carry @container:@index
                    // (the only container shape a reverse term may legally take):
                    // the value is an index map whose entries expand to nodes
                    // with @index attached, which then become the reverse values
                    // (#t0063 plain @index, #t0131 property-valued @index). A
                    // property-scoped @context is intentionally not layered here —
                    // no legal reverse term shape combines @index with @context.
                    $reverseValues = null;
                    if ($this->containerIs($key, Keyword::Index->value)) {
                        $reverseValues = $this->expandContainerValue($key, $value, $termDef);
                    }
                    if ($reverseValues === null) {
                        $reverseValues = $this->expandElement($value, $key);
                    }
                    $this->collectReverseValues($reverseMap, $reverseIri, $reverseValues);

                    continue;
                }

                // §2.4.3 VC-DATA-INTEGRITY / §5.5 step 13: the two checks below
                // are the "undefined term is detected in an input document"
                // clause — the mechanism behind undefined-claim / empty-proof
                // canonicalization holes when data is silently dropped.
                $expandedKey = $this->expandIri($key, vocab: true);
                if ($expandedKey === null) {
                    $this->safeModeDrop(
                        'invalid property',
                        "term '{$key}' does not expand to an absolute IRI or keyword",
                        ['term' => $key],
                    );

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

                // JSON-LD keyword keys get special handling. @type VALUES are
                // expanded against the pre-type-scoped context (see above), so
                // a type's own scoped @vocab/null/term changes don't alter how
                // that type expands.
                if ($this->isKeyword($expandedKey)) {
                    $savedForKeyword = $this->termDefinitions;
                    if ($expandedKey === Keyword::Type->value) {
                        $this->termDefinitions = $preTypeScoped;
                    }

                    try {
                        $expandedValue = $this->expandKeywordValue($expandedKey, $value, $activeProperty);
                    } finally {
                        $this->termDefinitions = $savedForKeyword;
                    }
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
                    $this->safeModeDrop(
                        'invalid property',
                        "term '{$key}' does not expand to an absolute IRI or keyword",
                        ['term' => $key, 'expanded' => $expandedKey],
                    );

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
                $freshBeforeValue = $this->freshPropertyScope;
                if ($termDef !== null && array_key_exists('@context', $termDef)) {
                    $this->termDefinitions = $this->applyScopedContext($termDef['@context'], $beforeValue, overrideProtected: true);
                    if ($this->termDefinitions->getPreviousContext() !== null) {
                        // Non-propagating (@propagate: false) property scope:
                        // the immediate value must keep it (#tso06).
                        $this->freshPropertyScope = $this->termDefinitions;
                    }
                }

                try {
                    // @json type coercion: a term with `@type: @json`
                    // preserves its value verbatim as a JSON literal,
                    // regardless of the value's shape (scalar, array, or
                    // object). It bypasses normal node/value expansion.
                    if ($this->isJsonTyped($termDef)) {
                        // …unless the term ALSO has a plain @graph container:
                        // the JSON literal is a value object, which the
                        // graph-container wrap filters out as free-floating —
                        // such a term loses every value (jsonld.js parity; its
                        // wrap filter drops the literal the same way).
                        if (
                            ! $this->frameExpansion
                            && $this->hasContainer($termDef, Keyword::Graph->value)
                            && ! $this->hasContainer($termDef, Keyword::Index->value)
                            && ! $this->hasContainer($termDef, Keyword::Id->value)
                        ) {
                            $this->safeModeDrop(
                                'object with only @value',
                                'a JSON literal under a @graph container carries no statement and is dropped',
                                ['value' => $value],
                            );

                            continue;
                        }
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
                        // A plain @graph container (no @index/@id map) whose
                        // members were all dropped as free-floating — or whose
                        // raw value was empty — omits the property entirely
                        // (jsonld.js `continue`s its wrap); the parent may then
                        // fall to the step-18 drops itself. Map containers keep
                        // the key with an empty list instead (also jsonld.js).
                        if (
                            $containerHandled === []
                            && $this->hasContainer($termDef, Keyword::Graph->value)
                            && ! $this->hasContainer($termDef, Keyword::Index->value)
                            && ! $this->hasContainer($termDef, Keyword::Id->value)
                        ) {
                            continue;
                        }
                        $this->mergeProperty($result, $expandedKey, $containerHandled);

                        continue;
                    }

                    // A scalar value of a @container:@list property is treated
                    // as a single-element list so it expands to a {@list:[…]}
                    // object (#t0004), via the @list wrapping in expandArray.
                    if (! is_array($value) && $this->containerIs($key, Keyword::List->value)) {
                        $value = [$value];
                    }

                    $expandedValue = $this->expandElement($value, $key);
                } finally {
                    $this->termDefinitions = $beforeValue;
                    $this->freshPropertyScope = $freshBeforeValue;
                }

                if ($expandedValue === null) {
                    continue;
                }

                // A node object that carried an @id which expanded to null (e.g.
                // a keyword-shaped "@ignoreMe") and has no other content is NOT
                // a blank node: the intended identifier is simply absent, so the
                // value is dropped entirely (#te122 / #t0122). This is distinct
                // from a genuinely-empty node (no @id key) handled just below.
                if (
                    $expandedValue === []
                    && is_array($value)
                    && ! array_is_list($value)
                    && array_key_exists(Keyword::Id->value, $value)
                    && ! array_key_exists(Keyword::Set->value, $value)
                    && ! array_key_exists(Keyword::List->value, $value)
                    && ! array_key_exists(Keyword::Value->value, $value)
                ) {
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

            // Second pass: merge @nest values now that every base property is in
            // $result, so a property contributed by both reads [base, nested]
            // rather than [nested, base] (§5.5 step 13.4.4 / #tn003). A nest
            // term's property-scoped @context is active while its nested
            // values expand (#tc037).
            foreach ($deferredNestValues as [$nestTermDef, $deferredNest]) {
                $beforeNest = $this->termDefinitions;
                if ($nestTermDef !== null && array_key_exists('@context', $nestTermDef)) {
                    $this->termDefinitions = $this->applyScopedContext($nestTermDef['@context'], $beforeNest, overrideProtected: true);
                }

                try {
                    $this->mergeNestedObject($deferredNest, $result);
                } finally {
                    $this->termDefinitions = $beforeNest;
                }
            }
        } finally {
            $this->termDefinitions = $previousActive;
            if ($enteredFreshPropertyScope !== null) {
                $this->freshPropertyScope = $enteredFreshPropertyScope;
            }
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
            $valueObject = $this->finalizeValueObject($result);

            // §5.5 step 18: a value object at the top level, directly inside
            // @graph, or as the direct value of a @graph-container term is
            // free-floating — it carries no statement — and is dropped, after
            // validation (an invalid value object is still a syntax error,
            // matching jsonld.js). The top-level case is also caught in
            // expand(); this covers named graphs and graph containers, whose
            // members never pass through that top-level filter. Frame
            // expansion keeps value patterns.
            if (
                $valueObject !== null
                && ! $this->frameExpansion
                && $this->dropsFreeFloating($activeProperty)
            ) {
                $this->safeModeDrop(
                    'object with only @value',
                    'a value object at the top level or in graph position carries no statement and is dropped',
                    ['object' => $valueObject],
                );

                return null;
            }

            return $valueObject;
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
            // In a frame, {@language: "en"} is a legitimate match pattern —
            // dropping it is frame semantics, not document data loss.
            if (! $this->frameExpansion) {
                $this->safeModeDrop(
                    'object with only @language',
                    'an object carrying only @language/@direction has no @value to attach to and is dropped',
                    ['object' => $result],
                );
            }

            return null;
        }

        // @list object: pass through as-is (the @list value itself was
        // already expanded into a list of values). A @list object may only
        // carry @list and @index; any other entry (e.g. @id) makes it an
        // invalid set or list object (§5.5 step 13.4.4).
        if (isset($result[Keyword::List->value])) {
            foreach (array_keys($result) as $resultKey) {
                if ($resultKey !== Keyword::List->value && $resultKey !== Keyword::Index->value) {
                    throw new JsonLdException('Invalid set or list object: a @list object may only contain @list and @index');
                }
            }

            // §5.5 step 18: a @list object at the top level, directly inside
            // @graph, or as the direct value of a @graph-container term is
            // free-floating and dropped — a list carries no statement outside
            // a property (#t0047), and everything inside goes with it,
            // including node objects that would survive on their own.
            // Validation above still applies first (jsonld.js parity). Frame
            // expansion keeps list patterns.
            if (
                ! $this->frameExpansion
                && $this->dropsFreeFloating($activeProperty)
            ) {
                $this->safeModeDrop(
                    'object with only @list',
                    'a @list object at the top level or in graph position carries no statement and is dropped',
                    ['object' => $result],
                );

                return null;
            }

            ksort($result);

            return $result;
        }

        // Free-floating node: an object whose only expanded entry is @id,
        // with no other properties, is dropped during expansion (§5.5 step
        // 18: active property null or @graph, or a @graph-container term — a
        // graph's direct members carry no statement wherever the graph comes
        // from). On nested objects we keep it because the spec allows
        // references.
        if (
            ! $this->frameExpansion
            && $this->dropsFreeFloating($activeProperty)
            && count($result) === 1
            && isset($result[Keyword::Id->value])
        ) {
            $this->safeModeDrop(
                'object with only @id',
                'a node object containing only @id carries no statement in graph position and is dropped',
                ['object' => $result],
            );

            return null;
        }

        // An empty object is dropped only when free-floating (§5.5 step 18:
        // active property null or @graph, or a @graph-container term). As a
        // normal property value it is kept as an empty node object (e.g. a
        // node whose only term was decoupled by a scoped @context:null
        // reset). Under frame expansion an empty map is a wildcard and is
        // always kept.
        if (! $this->frameExpansion && $result === [] && $this->dropsFreeFloating($activeProperty)) {
            $this->safeModeDrop(
                'empty object',
                'an empty object in graph position carries no statement and is dropped',
            );

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
                if ($this->frameExpansion) {
                    // A frame's @id may be a string, an array of IRIs, or {}
                    // (wildcard); it expands to a list of IRIs (empty = wildcard).
                    $idItems = is_string($value)
                        ? [$value]
                        : (is_array($value) && array_is_list($value) ? $value : []);
                    $ids = [];
                    foreach ($idItems as $idItem) {
                        if (is_string($idItem) && ($expandedId = $this->expandIri($idItem, documentRelative: true)) !== null) {
                            $ids[] = $expandedId;
                        }
                    }

                    return $ids;
                }
                if (! is_string($value)) {
                    throw new JsonLdException('Invalid @id value: must be a string');
                }

                $expandedId = $this->expandIri($value, documentRelative: true);
                if ($this->safe) {
                    // An @id that expands to null (a keyword-shaped value such
                    // as "@ignoreMe") evaporates: the node silently becomes a
                    // blank node, losing its identity. A relative @id survives
                    // expansion but carries no RDF statement later. Both are
                    // identity loss on signable data.
                    if ($expandedId === null) {
                        throw new DataLossException(
                            'reserved @id value',
                            "@id value '{$value}' begins with '@' and is reserved for future use; the identifier is dropped",
                            ['id' => $value],
                        );
                    }
                    if (! str_starts_with($expandedId, '_:') && ! $this->looksLikeAbsoluteIri($expandedId)) {
                        throw new DataLossException(
                            'relative @id reference',
                            "@id value '{$value}' does not expand to an absolute IRI or blank node",
                            ['id' => $value, 'expanded' => $expandedId],
                        );
                    }
                }

                return $expandedId;

            case Keyword::Type->value:
                return $this->expandTypeValue($value);

            case Keyword::Default->value:
                if ($this->frameExpansion) {
                    $expandedDefault = $this->expandElement($value, $activeProperty);
                    if ($expandedDefault === null) {
                        return [];
                    }

                    return array_is_list($expandedDefault) ? $expandedDefault : [$expandedDefault];
                }

                $this->safeModeDrop(
                    'invalid property',
                    '@default is only meaningful in a frame and is dropped from a document',
                    ['keyword' => $expandedKey, 'value' => $value],
                );

                return null;

            case Keyword::Embed->value:
            case Keyword::Explicit->value:
            case Keyword::RequireAll->value:
            case Keyword::OmitDefault->value:
                // Frame keywords are preserved verbatim for the framing
                // algorithm; outside a frame they carry no meaning and drop.
                if (! $this->frameExpansion) {
                    $this->safeModeDrop(
                        'invalid property',
                        "{$expandedKey} is only meaningful in a frame and is dropped from a document",
                        ['keyword' => $expandedKey, 'value' => $value],
                    );

                    return null;
                }

                return $value;

                // Note: @value is handled directly in expandObject (recorded
                // verbatim, including null) and never reaches this method.

            case Keyword::Language->value:
                if ($this->frameExpansion) {
                    return $value; // a frame may use {} / a list as a @language pattern
                }
                if (! is_string($value)) {
                    throw new JsonLdException('Invalid language-tagged string: @language must be a string');
                }
                // A malformed BCP47 tag flows through expansion but its
                // statement is dropped at toRdf; jsonld.js's safe expansion
                // already fails closed here, so an expand/flatten-only
                // pipeline is protected too. Default mode keeps it verbatim.
                if ($this->safe && ! $this->wellFormedLanguageTag($value)) {
                    throw new DataLossException(
                        'invalid @language value',
                        "@language tag '{$value}' is not a well-formed BCP47 tag; its statement cannot be expressed in RDF",
                        ['language' => $value],
                    );
                }

                return $value;

            case Keyword::Index->value:
                if ($this->frameExpansion) {
                    return $value;
                }
                if (! is_string($value)) {
                    throw new JsonLdException('Invalid @index value: must be a string');
                }

                return $value;

            case Keyword::Direction->value:
                if ($this->frameExpansion) {
                    return $value; // a frame may use {} / a list as a @direction pattern
                }
                if (! is_string($value)) {
                    $this->safeModeDrop(
                        'invalid @direction value',
                        '@direction must be a string ("ltr"/"rtl"); a non-string value is dropped',
                        ['direction' => $value],
                    );

                    return null;
                }

                return $value;

            case Keyword::List->value:
                // §5.5: @list / @set contents expand under the *active property*
                // (the property the value belongs to) so scalar items
                // value-expand rather than being dropped as free-floating. Items
                // are expanded individually so a `@container: @list` term does
                // NOT re-wrap them — the @list keyword already establishes the
                // list. A list whose members include a list object is a list of
                // lists (rejected in JSON-LD 1.0).
                $listItems = $this->expandKeywordItems($value, $activeProperty);
                $this->assertNoListOfLists($listItems);

                return $listItems;

            case Keyword::Set->value:
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
                // "invalid @included value". Expanded under '@included' (not
                // null) so the free-floating drops don't fire first: a stray
                // scalar must surface as this unconditional spec error, not as
                // a safe-mode DataLossException claiming recoverable loss.
                $expandedIncluded = $this->expandElement($value, Keyword::Included->value);
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
        $this->safeModeDrop(
            'invalid property',
            "keyword {$expandedKey} is not usable as a node entry here and is dropped",
            ['keyword' => $expandedKey, 'value' => $value],
        );

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
     *             In frame-expansion mode the result may also carry a wildcard sentinel or a
     *             `{@default: …}` type frame for the framing matcher.
     *
     * @return list<string|array<string, mixed>>
     */
    private function expandTypeValue(mixed $value): array
    {
        if ($this->frameExpansion) {
            // A frame's @type may list IRIs, the keyword @default, a wildcard
            // {} (kept as the FRAME_WILDCARD sentinel so the matcher can tell it
            // from match-none []), or [] (match-none — an empty list).
            $typeItems = is_array($value) && array_is_list($value) ? $value : [$value];
            $types = [];
            foreach ($typeItems as $typeItem) {
                if ($typeItem === Keyword::Default->value) {
                    $types[] = Keyword::Default->value;
                } elseif (is_array($typeItem) && array_key_exists(self::FRAME_WILDCARD, $typeItem)) {
                    // A wildcard @type ({}): preserve the sentinel for the matcher.
                    $types[] = [self::FRAME_WILDCARD => true];
                } elseif (is_array($typeItem) && array_key_exists(Keyword::Default->value, $typeItem)) {
                    // `@type: {"@default": …}` — keep a default-type frame, with
                    // its default IRI(s) expanded, for the framing default step.
                    $defaultValue = $typeItem[Keyword::Default->value];
                    $defaultItems = is_array($defaultValue) && array_is_list($defaultValue) ? $defaultValue : [$defaultValue];
                    $expandedDefaults = [];
                    foreach ($defaultItems as $defaultItem) {
                        if (is_string($defaultItem) && ($expanded = $this->expandIri($defaultItem, vocab: true, documentRelative: true)) !== null) {
                            $expandedDefaults[] = $expanded;
                        }
                    }
                    $types[] = [Keyword::Default->value => $expandedDefaults];
                } elseif (is_string($typeItem) && ($expanded = $this->expandIri($typeItem, vocab: true, documentRelative: true)) !== null) {
                    $types[] = $expanded;
                }
            }

            return $types;
        }

        // @type IRIs expand with both vocab and document-relative modes
        // (§5.5): @vocab takes precedence if set, otherwise a relative @type
        // resolves against @base.
        if (is_string($value)) {
            $expanded = $this->expandIri($value, vocab: true, documentRelative: true);
            if ($expanded === null) {
                $this->safeModeDrop(
                    'relative @type reference',
                    "@type value '{$value}' does not expand to an IRI and is dropped",
                    ['type' => $value],
                );

                return [];
            }
            $this->assertSafeTypeIsAbsolute($value, $expanded);

            return [$expanded];
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
            if ($expanded === null) {
                $this->safeModeDrop(
                    'relative @type reference',
                    "@type value '{$item}' does not expand to an IRI and is dropped",
                    ['type' => $item],
                );

                continue;
            }
            $this->assertSafeTypeIsAbsolute($item, $expanded);
            $types[] = $expanded;
        }

        return $types;
    }

    /**
     * Safe mode: a @type that expands to a RELATIVE IRI survives expansion
     * (the spec keeps it) but can never become an rdf:type statement — an
     * external canonicalizer fed the safe-expanded output gets no error, so
     * fail closed here like jsonld.js's safe expansion does. No-op in default
     * mode, where only toRdf drops the statement.
     *
     * @throws DataLossException
     */
    private function assertSafeTypeIsAbsolute(string $raw, string $expanded): void
    {
        if (
            $this->safe
            && ! $this->isKeyword($expanded)
            && ! str_starts_with($expanded, '_:')
            && ! $this->looksLikeAbsoluteIri($expanded)
        ) {
            throw new DataLossException(
                'relative @type reference',
                "@type value '{$raw}' expands to '{$expanded}', which is not an absolute IRI or blank node; its rdf:type statement cannot be expressed",
                ['type' => $raw, 'expanded' => $expanded],
            );
        }
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

        // Coerce to @vocab — vocab-mode IRI expansion, falling back to
        // document-relative resolution when the value is neither a term nor
        // @vocab-resolvable (mirrors @id coercion / @type expansion) (#t0057).
        if ($typeMapping === Keyword::Vocab->value && is_string($value)) {
            return [Keyword::Id->value => $this->expandIri($value, vocab: true, documentRelative: true) ?? $value];
        }

        // Typed literal. @type:@none is NOT a datatype — it suppresses type
        // annotation, so such values fall through to the plain-value path and
        // expand to bare value objects without an @type member (#ttn02).
        if (
            $typeMapping !== null
            && $typeMapping !== Keyword::Id->value
            && $typeMapping !== Keyword::Vocab->value
            && $typeMapping !== Keyword::None->value
        ) {
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
            if (
                $prefixDef !== null
                && isset($prefixDef['@id'])
                && is_string($prefixDef['@id'])
                // A term explicitly flagged @prefix:false is NOT a prefix, so
                // "term:suffix" is not a compact IRI and is left for absolute-IRI
                // handling (#tpr29). We otherwise keep the lenient interpretation
                // (any string-IRI term acts as a prefix) for back-compat.
                && ($prefixDef[Keyword::Prefix->value] ?? null) !== false
            ) {
                // The prefix's @id may itself be a compact IRI built on an
                // earlier prefix (e.g. "site-cd" -> "site:..."); the spec stores
                // term IRI mappings fully expanded, so chase the chain here.
                // Bounded: cyclic self-mappings are rejected at definition time,
                // and the cap guards mutual cycles (#t0038/#ta038).
                $prefixIri = $prefixDef['@id'];
                for ($i = 0; $i < 10 && str_contains($prefixIri, ':'); $i++) {
                    [$innerPrefix, $innerSuffix] = explode(':', $prefixIri, 2);
                    if ($innerPrefix === '_' || str_starts_with($innerSuffix, '//')) {
                        break;
                    }
                    $innerDef = $this->termDefinitions->getTermDefinition($innerPrefix);
                    if (
                        $innerDef === null
                        || ! isset($innerDef['@id'])
                        || ! is_string($innerDef['@id'])
                        || ($innerDef[Keyword::Prefix->value] ?? null) === false
                    ) {
                        break;
                    }
                    $prefixIri = $innerDef['@id'].$innerSuffix;
                }

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
        // (RFC 3986 §5). A type-/property-scoped @context may override the base
        // for its scope (#tc015/#tc024); the active context carries it, falling
        // back to the document-level base when no scoped @base is in effect.
        if ($documentRelative) {
            $base = $this->termDefinitions->getBase() ?? $this->documentBase->getBase();
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
            if (is_array($expanded) && $expanded !== []) {
                // e.g. a @set inside @reverse unwraps to a list, which has no
                // reverse-map shape — the whole @reverse block is dropped.
                $this->safeModeDrop(
                    'dropped object',
                    'the @reverse value expanded to a list instead of a reverse property map and is dropped',
                    ['value' => $value],
                );
            }

            return;
        }

        foreach ($expanded as $prop => $items) {
            if (! is_string($prop)) {
                continue;
            }

            // A @reverse map may only contain reverse properties (and a nested
            // @reverse). Any other keyword (e.g. @id, @value) means the map was
            // not a valid reverse property map (§5.5 step 13.7).
            if (str_starts_with($prop, '@') && $prop !== Keyword::Reverse->value) {
                throw new JsonLdException("Invalid reverse property map: '{$prop}' is not a reverse property");
            }

            if (! is_array($items)) {
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
        if ($this->frameExpansion) {
            // A frame's value object is a match pattern (e.g. {@value: {}},
            // {@type: {}}, {@language: []}); it is kept verbatim, bypassing the
            // strict value-object validation that applies to real documents.
            ksort($result);

            return $result;
        }

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

        // @json values are preserved verbatim — no further constraints (a
        // @json literal may hold any JSON value, including null/objects/arrays).
        $type = $hasType ? $result[Keyword::Type->value] : null;
        $isJson = $type === Keyword::Json->value
            || (is_array($type) && in_array(Keyword::Json->value, $type, true));

        // @value: null → the value object is dropped, UNLESS it is a @json
        // literal, where JSON null is a legitimate value to serialise (#tjs22).
        if ($value === null && ! $isJson) {
            $this->safeModeDrop(
                'null @value value',
                'a value object whose @value is null is dropped',
                ['object' => $result],
            );

            return null;
        }

        // A language-tagged value requires a string @value.
        if ($hasLanguage && ! is_string($value)) {
            throw new JsonLdException('Invalid language-tagged value: @value must be a string when @language is present');
        }

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
     * A well-formed BCP47 language tag: ALPHA{1,8} (-(ALPHANUM){1,8})* —
     * the same shape {@see ToRdf} requires before emitting a language-tagged
     * literal, so safe expansion and safe serialization agree.
     */
    private function wellFormedLanguageTag(string $tag): bool
    {
        return preg_match('/^[a-zA-Z]{1,8}(-[a-zA-Z0-9]{1,8})*$/', $tag) === 1;
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
     * True if an expanded item is "free-floating" at the top level / in @graph
     * (§5.5 step 18) and therefore dropped: a value object (`@value`), a `@list`
     * object, or a node object whose only entry is `@id`.
     *
     * @param  array<array-key, mixed>  $item
     */
    private function isFreeFloating(array $item): bool
    {
        return array_key_exists(Keyword::Value->value, $item)
            || array_key_exists(Keyword::List->value, $item)
            || (count($item) === 1 && array_key_exists(Keyword::Id->value, $item));
    }

    /**
     * True when the free-floating drops of §5.5 step 18 apply for this active
     * property: at the top level (null), directly inside `@graph`, or — going
     * beyond the literal spec, matching jsonld.js — as the direct value of a
     * term whose container mapping includes `@graph` (a graph's members carry
     * no statement wherever the graph comes from). jsonld.js applies the
     * container condition to any `@graph`-bearing container, including the
     * `[@graph, @id]` / `[@graph, @index]` map forms, so free-floating OBJECT
     * members of those maps drop while raw scalars (which value expansion
     * turns into value objects without ever passing through here) survive to
     * be wrapped — byte parity requires mirroring that asymmetry.
     */
    private function dropsFreeFloating(?string $activeProperty): bool
    {
        if ($activeProperty === null || $activeProperty === Keyword::Graph->value) {
            return true;
        }
        if (str_starts_with($activeProperty, '@')) {
            return false;
        }

        return $this->hasContainer($this->termDefinitions->getTermDefinition($activeProperty), Keyword::Graph->value);
    }

    /**
     * True if any key of $obj IRI-expands to `@value` under $context. Used to
     * decide whether a non-propagating (type-scoped) context is rolled back
     * when entering a nested object (§5.5 step 7): the rollback is skipped when
     * the object is a value object, which the spec detects by "an entry
     * expanding to @value" — NOT merely a literal `@value` key, so a
     * type-scoped term aliasing `@value` (e.g. `value: "@value"`) keeps the
     * context active and makes the nested object a value object (#tc020/#tc021).
     *
     * @param  array<array-key, mixed>  $obj
     */
    private function objectHasKeyExpandingToValue(array $obj, TermDefinitions $context): bool
    {
        $saved = $this->termDefinitions;
        $this->termDefinitions = $context;
        try {
            foreach ($obj as $key => $ignored) {
                if (is_string($key) && $this->expandIri($key, vocab: true) === Keyword::Value->value) {
                    return true;
                }
            }
        } finally {
            $this->termDefinitions = $saved;
        }

        return false;
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
     * True if a scoped @context carries an explicit `@propagate: true` —
     * overriding the type-scoped default of non-propagation.
     */
    private function contextPropagateTrue(mixed $context): bool
    {
        $layers = is_array($context) && array_is_list($context) ? $context : [$context];
        foreach ($layers as $layer) {
            if (is_array($layer) && ($layer[Keyword::Propagate->value] ?? null) === true) {
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
        // Safe mode and the processing mode are per-call options, not context
        // state: they survive scoped copies (and null resets below) so the
        // definition-time checks in TermDefinitions keep firing in scope.
        $active->setSafe($this->safe);
        $active->setProcessingMode($this->documentBase->getProcessingMode());
        $vocab = $base->getVocab();
        if ($vocab !== null) {
            $active->setVocab($vocab);
        }
        // Carry the parent's active @base so a scoped context that does NOT set
        // @base keeps resolving document-relative IRIs against the inherited
        // base, and one that DOES set @base (#tc015/#tc024) overlays onto it.
        $parentBase = $base->getBase();
        if ($parentBase !== null) {
            $active->setBase($parentBase);
        }
        // The parent's default @language is inherited (spec §4.1: context
        // processing starts from a copy of the active context), so plain
        // strings in the scope keep their language tag — jsonld.js parity;
        // its N-Quads (and so VC signatures) depend on it. The default
        // @direction is deliberately NOT inherited: jsonld.js's active-context
        // clone (_cloneActiveContext) carries @base/@vocab/@language but omits
        // @direction — an upstream deviation from the spec, reported as
        // https://github.com/digitalbazaar/jsonld.js/issues/586 — and matching
        // the reference implementation's bytes wins for signing pipelines;
        // revisit when that issue is fixed. A scoped context's own explicit
        // @language/@direction entries are applied by overlayContextOnto
        // either way.
        $active->setDefaultLanguage($base->getDefaultLanguage());

        foreach ($layers as $layer) {
            if ($layer === null) {
                if (! $overrideProtected && $active->hasAnyProtected()) {
                    throw new JsonLdException('Invalid context nullification: a null context cannot clear protected terms');
                }
                $active = new TermDefinitions;
                $active->setSafe($this->safe);
                $active->setProcessingMode($this->documentBase->getProcessingMode());
            } elseif (is_string($layer) || (is_array($layer) && array_key_exists(Keyword::Import->value, $layer))) {
                // A remote (string) scoped context, or one that sources another
                // via @import, is resolved through the DocumentLoader, then
                // overlaid (terms and remote-set @language/@direction defaults).
                $this->overlayRemoteScopedContext($active, $layer, $overrideProtected);
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
     * Resolve a string (remote) or `@import`-bearing scoped context via
     * {@see ContextProcessor} (which handles loading and `@import`
     * reverse-merge). The full resolved {@see TermDefinitions} is returned —
     * not just the term map — so the caller can also apply the remote
     * context's own default `@language`/`@direction` (whose presence flags
     * distinguish an explicit null reset from the entry being absent).
     * Returns an empty context if no loader is wired.
     *
     * @param  string|array<array-key, mixed>  $layer
     */
    private function resolveRemoteContext(string|array $layer): TermDefinitions
    {
        if ($this->documentLoader === null) {
            // Every term the unresolvable context would define stays undefined,
            // so its data drops one term at a time downstream. Fail closed at
            // the root cause rather than degrading to an empty term map.
            $this->safeModeDrop(
                'context load failed',
                sprintf(
                    'scoped context %s cannot be resolved: no document loader is configured',
                    is_string($layer) ? "'{$layer}'" : 'with @import',
                ),
                ['context' => $layer],
            );

            return new TermDefinitions;
        }

        $processor = new ContextProcessor(
            ['@context' => $layer],
            $this->documentLoader,
            $this->documentBase->getBase(),
            $this->documentBase->getProcessingMode(),
            safe: $this->safe,
        );

        return $processor->getTermDefinitions();
    }

    /**
     * Overlay a remote / `@import`-bearing scoped context layer onto $target:
     * every resolved term definition is overlaid, and a default
     * `@language`/`@direction` the remote context itself sets is applied — an
     * explicit null there clears the inherited default, while an absent entry
     * leaves it untouched (the presence flags on the resolved
     * {@see TermDefinitions} tell the two apart). Matches jsonld.js, which
     * runs a remote scoped context through full context processing.
     *
     * @param  string|array<array-key, mixed>  $layer
     */
    private function overlayRemoteScopedContext(TermDefinitions $target, string|array $layer, bool $overrideProtected): void
    {
        $resolved = $this->resolveRemoteContext($layer);

        foreach ($resolved->termDefinitions as $term => $definition) {
            $this->overlayContextOnto($target, [$term => $definition], $overrideProtected);
        }

        if ($resolved->wasDefaultLanguageSet()) {
            $target->setDefaultLanguage($resolved->getDefaultLanguage());
        }
        if ($resolved->wasDefaultDirectionSet()) {
            $target->setDefaultDirection($resolved->getDefaultDirection());
        }
    }

    /**
     * Resolve a scoped `@vocab` value the way {@see ContextProcessor} resolves
     * a document-level one: empty string → the scope's base, a blank node is
     * kept, a compact IRI expands via a prefix term of the scope, a bare term
     * via its definition, and a relative reference is appended to the current
     * vocab (else resolved against the base).
     */
    private function resolveScopedVocab(string $vocab, TermDefinitions $target): string
    {
        if ($vocab === '') {
            return $target->getBase() ?? $this->documentBase->getBase() ?? '';
        }

        if (str_starts_with($vocab, '_:')) {
            return $vocab;
        }

        if (str_contains($vocab, ':')) {
            [$prefix, $suffix] = explode(':', $vocab, 2);
            if (! str_starts_with($suffix, '//')) {
                $prefixDef = $target->getTermDefinition($prefix);
                if ($prefixDef !== null && isset($prefixDef['@id']) && is_string($prefixDef['@id'])) {
                    return $prefixDef['@id'].$suffix;
                }
            }

            return $vocab; // absolute IRI
        }

        $termDef = $target->getTermDefinition($vocab);
        if ($termDef !== null && isset($termDef['@id']) && is_string($termDef['@id'])) {
            return $termDef['@id'];
        }

        $currentVocab = $target->getVocab();
        if ($currentVocab !== null) {
            return $currentVocab.$vocab;
        }
        $base = $target->getBase() ?? $this->documentBase->getBase();

        return $base !== null && $base !== '' ? IriResolver::resolve($base, $vocab) : $vocab;
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
            // §5.5 step 11 resolves the type against the ACTIVE context and
            // nothing else. No document-level fallback: after a context reset
            // (e.g. VC 2.0's `@context: null` isolation on
            // verifiableCredential) a document-level type-scoped context must
            // NOT activate, or its terms leak into the isolated node and the
            // canonical quads diverge from conformant processors
            // (tests/Interop `vc-context-null-isolation`). Types defined
            // inside another term's scoped @context (e.g. DataIntegrityProof
            // in an imported security context) are already visible here: the
            // property-scoped context is active by the time the typed node is
            // entered.
            $typeDef = $this->termDefinitions->getTermDefinition($type);
            if (
                $typeDef === null
                || ! array_key_exists('@context', $typeDef)
                || $typeDef['@context'] === null
            ) {
                continue;
            }
            $typeContext = $typeDef['@context'];
            if (! is_string($typeContext) && ! is_array($typeContext)) {
                continue;
            }

            if ($scoped === null) {
                // Initialise from the active (rolled-back) context. Copy term
                // definitions so the overlay doesn't mutate it, and record the
                // pre-type-scoped context as the previous context so a nested
                // node object rolls type-scoped terms back (§5.5 step 7;
                // type-scoped contexts have @propagate = false) — unless an
                // explicit @propagate:true makes the context propagate.
                $scoped = new TermDefinitions($this->termDefinitions->termDefinitions);
                $scoped->setSafe($this->safe);
                $scoped->setProcessingMode($this->documentBase->getProcessingMode());
                $vocab = $this->termDefinitions->getVocab();
                if ($vocab !== null) {
                    $scoped->setVocab($vocab);
                }
                // Inherit the default @language, NOT @direction — same
                // jsonld.js-parity rule as the property-scoped copy in
                // {@see applyScopedContext}.
                $scoped->setDefaultLanguage($this->termDefinitions->getDefaultLanguage());
                if (! $this->contextPropagateTrue($typeContext)) {
                    $scoped->setPreviousContext($this->termDefinitions);
                }
            }

            // Type-scoped contexts may NOT redefine/clear protected terms
            // (override-protected = false). A context may be a single map, a
            // remote IRI, or a LIST of layers (e.g. [null, {...}]); process each
            // layer. A remote / @import-bearing layer is resolved first.
            $layers = is_array($typeContext) && array_is_list($typeContext) ? $typeContext : [$typeContext];
            foreach ($layers as $layer) {
                if ($layer === null) {
                    // A null layer resets the context. Since override-protected
                    // is false, nulling a context that still has protected terms
                    // is an invalid nullification (#tpr17/#tpr18/#tpr20/#tpr21).
                    if ($scoped->hasAnyProtected()) {
                        throw new JsonLdException('Invalid context nullification: a type-scoped context may not clear protected terms');
                    }
                    $scoped = new TermDefinitions;
                    $scoped->setSafe($this->safe);
                    $scoped->setProcessingMode($this->documentBase->getProcessingMode());

                    continue;
                }
                if (is_string($layer) || (is_array($layer) && array_key_exists(Keyword::Import->value, $layer))) {
                    $this->overlayRemoteScopedContext($scoped, $layer, overrideProtected: false);
                } elseif (is_array($layer)) {
                    $this->overlayContextOnto($scoped, $layer, overrideProtected: false);
                }
            }
        }

        return $scoped;
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

            if ($term === Keyword::Vocab->value && ($definition === null || is_string($definition))) {
                // A scoped @vocab of null RESETS the active vocabulary (so a
                // relative @type goes document-relative and unmapped terms are
                // dropped) rather than inheriting the parent's @vocab (#t0059).
                if ($definition === null) {
                    $target->setVocab(null);
                } else {
                    // Resolve like the document-level path (ContextProcessor
                    // ::resolveVocab) and apply the same relative-@vocab safe
                    // check, so a scoped relative @vocab fails closed at the
                    // root cause instead of surfacing per-term as a misleading
                    // 'invalid property' — or, when the relative value happens
                    // to contain a colon, not at all.
                    $resolved = $this->resolveScopedVocab($definition, $target);
                    if (
                        $this->safe
                        && ! str_starts_with($resolved, '_:')
                        && ! $this->looksLikeAbsoluteIri($resolved)
                    ) {
                        throw new DataLossException(
                            'relative @vocab reference',
                            "scoped @vocab '{$definition}' does not resolve to an absolute IRI",
                            ['vocab' => $definition, 'resolved' => $resolved],
                        );
                    }
                    $target->pushVocab($resolved);
                }

                continue;
            }
            if ($term === Keyword::Base->value && ($definition === null || is_string($definition))) {
                // A scoped @base sets the document-relative resolution base for
                // @id values within this context's scope (#tc015 type-scoped,
                // #tc024 property-scoped). A relative @base resolves against
                // the currently-active base. A null CLEARS the base — stored
                // as '' (vs null = "unset") so IRI expansion does not fall back
                // to the document base and relative IRIs stay relative (#t0060).
                $target->setBase($definition === null ? '' : IriResolver::establishBase($target->getBase(), $definition));

                continue;
            }
            if (str_starts_with($term, '@')) {
                // Scoped @language / @direction set (or, with null, reset) the
                // scope's default for plain string values, exactly like the
                // document-level entries in ContextProcessor — same validation,
                // same case-preserving storage. jsonld.js applies scoped
                // defaults through full context processing; it reports an
                // invalid value as 'invalid scoped context', here it raises the
                // document-level error for the entry.
                if ($term === Keyword::Language->value) {
                    if ($definition !== null && (! is_string($definition) || ! $this->wellFormedLanguageTag($definition))) {
                        $repr = is_scalar($definition) ? (string) $definition : gettype($definition);
                        throw new JsonLdException("Invalid @language value: {$repr}");
                    }
                    /** @var string|null $definition */
                    $target->setDefaultLanguage($definition);

                    continue;
                }
                if ($term === Keyword::Direction->value) {
                    if ($definition !== null && $definition !== 'ltr' && $definition !== 'rtl') {
                        $repr = is_scalar($definition) ? (string) $definition : gettype($definition);
                        throw new JsonLdException("Invalid @direction value: {$repr}");
                    }
                    $target->setDefaultDirection($definition);

                    continue;
                }

                // @protected / @propagate / @import / @version are consumed by
                // the surrounding machinery.
                // A keyword-SHAPED term that is not a real keyword is reserved:
                // it is silently skipped here while the identical definition in
                // a document-level (or remote scoped) context throws 'reserved
                // term' — same construct, same rule on both write paths.
                if (preg_match('/^@[A-Za-z]+$/', $term) === 1 && ! Keyword::contains($term)) {
                    $this->safeModeDrop(
                        'reserved term',
                        "term '{$term}' has the form of a keyword; terms beginning with '@' are reserved for future use and cannot hold data",
                        ['term' => $term],
                    );
                }

                continue;
            }

            if ($definition === null) {
                // A scoped `term: null` removes the term: it maps to null, so
                // using it as a key drops that entry (e.g. nullifying an inherited
                // @nest term inside a property-scoped context — #tin06). Clearing
                // a protected term without override is rejected.
                if (! $overrideProtected && $target->isProtected($term)) {
                    throw new JsonLdException("Protected term redefinition: '{$term}' is protected and cannot be cleared by a scoped context");
                }
                $target->termDefinitions[$term] = [Keyword::Id->value => null];

                continue;
            }

            if (! is_string($definition) && ! is_array($definition)) {
                // The document-level equivalent throws unconditionally
                // (ContextProcessor); scoped processing tolerates the shape by
                // skipping the term, whose data then drops at use time.
                $this->safeModeDrop(
                    'invalid scoped term definition',
                    "term '{$term}' in a scoped context has a non-string, non-map definition and is ignored",
                    ['term' => $term, 'definition' => $definition],
                );

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
    /**
     * §5.5 — wrap an expanded map entry in a graph object unless it already is
     * one. Used by the combined `[@graph, @index]` / `[@graph, @id]` /
     * `[@graph, @type]` map cases (the spec's "and item is not a graph object"
     * guard, which prevents double-wrapping a value that is already a graph
     * object). The plain `@graph` container (see {@see expandGraphContainer})
     * wraps unconditionally and does NOT use this helper.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function wrapAsGraphObject(array $item): array
    {
        if (array_key_exists(Keyword::Graph->value, $item)) {
            return $item;
        }

        return [Keyword::Graph->value => [$item]];
    }

    /**
     * @param  array<array-key, mixed>|null  $termDef
     * @return list<mixed>|null
     */
    private function expandContainerValue(string $key, mixed $value, ?array $termDef): ?array
    {
        if ($termDef === null || ! isset($termDef['@container'])) {
            return null;
        }

        // A container may combine @graph with @index / @id / @type (e.g.
        // ["@graph", "@index"]). When @graph is present alongside a map
        // container, each map entry's expansion is additionally wrapped in a
        // graph object before the @index / @id / @type metadata is attached.
        $wrapGraph = $this->hasContainer($termDef, Keyword::Graph->value);

        // @language map: {en: "hi", fr: "salut"} → list of value objects with @language.
        if ($this->hasContainer($termDef, Keyword::Language->value) && is_array($value) && ! array_is_list($value)) {
            return $this->expandLanguageMap($value, $termDef);
        }

        // @index map: {first: ..., second: ...} → list of expanded nodes with @index.
        if ($this->hasContainer($termDef, Keyword::Index->value) && is_array($value) && ! array_is_list($value)) {
            return $this->expandIndexMap($key, $value, $wrapGraph);
        }

        // @id map: {"urn:1": {...}, "urn:2": {...}} → list of nodes with @id set.
        if ($this->hasContainer($termDef, Keyword::Id->value) && is_array($value) && ! array_is_list($value)) {
            return $this->expandIdMap($key, $value, $wrapGraph);
        }

        // @type map: {Person: {...}, Animal: {...}} → list of nodes with @type set.
        if ($this->hasContainer($termDef, Keyword::Type->value) && is_array($value) && ! array_is_list($value)) {
            return $this->expandTypeMap($key, $value, $wrapGraph);
        }

        // @graph container (graph-only or [@graph, @set]): value is wrapped in
        // a graph object, per element. An all-dropped / empty result comes
        // back as [] — null from expandGraphContainer would read as "not a
        // container value" here; the key loop turns the [] into omission.
        if ($wrapGraph) {
            return $this->expandGraphContainer($key, $value) ?? [];
        }

        return null;
    }

    /**
     * §5.5 step 13.4.7 — @language container.
     *
     * @param  array<array-key, mixed>  $map
     * @param  array<array-key, mixed>|null  $termDef
     * @return list<array<string, mixed>>
     */
    private function expandLanguageMap(array $map, ?array $termDef = null): array
    {
        // Each value object picks up the effective base @direction: the term's
        // own @direction (an explicit null suppresses it) wins over the active
        // context's default @direction (§5.5 step 13.4.7 / #tdi04/#tdi05/#tdi06).
        $direction = $this->effectiveLanguageOrDirection($termDef, Keyword::Direction->value, $this->termDefinitions->getDefaultDirection());

        // §5.5: container-map entries are emitted in lexicographic (code-point)
        // order of the RAW map key, independent of input order — the expanded
        // output is deterministically ordered (#tdi04–#tdi07, #t0030).
        ksort($map, SORT_STRING);
        $result = [];
        foreach ($map as $language => $entry) {
            if (! is_string($language)) {
                // PHP decodes a numeric-string JSON key ("1") to an int key;
                // jsonld.js would keep it as a language tag.
                $this->safeModeDrop(
                    'invalid map key',
                    "language map key '{$language}' is not a string and its entry is dropped",
                    ['key' => $language],
                );

                continue;
            }
            // A key whose IRI expansion is @none (the literal keyword or an
            // alias of it) produces value objects with no @language.
            $langIsNone = $language === Keyword::None->value
                || $this->expandIri($language, vocab: true) === Keyword::None->value;

            // Each entry can be a single string or a list of strings.
            // A malformed BCP47 map key produces statements toRdf must drop;
            // safe mode fails closed at the source (jsonld.js parity).
            if ($this->safe && ! $langIsNone && ! $this->wellFormedLanguageTag($language)) {
                throw new DataLossException(
                    'invalid @language value',
                    "language map key '{$language}' is not a well-formed BCP47 tag; its entries' statements cannot be expressed in RDF",
                    ['language' => $language],
                );
            }

            $items = is_array($entry) && array_is_list($entry) ? $entry : [$entry];
            foreach ($items as $item) {
                if ($item === null) {
                    continue;
                }
                if (! is_string($item)) {
                    throw new JsonLdException('Invalid language map value: values must be strings');
                }
                $valueObject = [Keyword::Value->value => $item];
                if (! $langIsNone) {
                    $valueObject[Keyword::Language->value] = $language;
                }
                if (is_string($direction) && $direction !== '') {
                    $valueObject[Keyword::Direction->value] = $direction;
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
    private function expandIndexMap(string $activeProperty, array $map, bool $wrapGraph): array
    {
        // Property-valued index (§5.5): when the active property's term sets
        // @index to a property IRI (rather than the @index keyword), each map
        // key is attached as a *value* of that property on the expanded item,
        // instead of as @index metadata.
        $termDef = $this->termDefinitions->getTermDefinition($activeProperty);
        $indexKey = is_array($termDef) && isset($termDef[Keyword::Index->value]) && is_string($termDef[Keyword::Index->value])
            ? $termDef[Keyword::Index->value]
            : Keyword::Index->value;
        // A property-valued index applies even when the container also wraps
        // entries in a graph object: the index property is attached to the
        // wrapped graph object itself (#tpi11).
        $indexProperty = ($indexKey !== Keyword::Index->value)
            ? $this->expandIri($indexKey, vocab: true)
            : null;

        // §5.5: index-map entries are emitted in lexicographic key order (#tpi06–
        // #tpi09); SORT_STRING so numeric-looking keys sort by code point.
        ksort($map, SORT_STRING);
        $result = [];
        foreach ($map as $index => $entry) {
            if (! is_string($index)) {
                // PHP decodes a numeric-string JSON key ("1") to an int key;
                // jsonld.js would keep it as an @index value.
                $this->safeModeDrop(
                    'invalid map key',
                    "index map key '{$index}' is not a string and its entry is dropped",
                    ['key' => $index],
                );

                continue;
            }
            // A key whose IRI expansion is @none (the literal keyword or an
            // alias of it) carries no @index metadata.
            $indexIsNone = $this->expandIri($index, vocab: true) === Keyword::None->value;

            $items = is_array($entry) && array_is_list($entry) ? $entry : [$entry];
            foreach ($items as $item) {
                $expanded = $this->expandElement($item, $activeProperty);
                if ($expanded === null) {
                    continue;
                }
                $list = array_is_list($expanded) ? $expanded : [$expanded];
                foreach ($list as $expandedItem) {
                    if (is_array($expandedItem) && ! array_is_list($expandedItem)) {
                        /** @var array<string, mixed> $expandedItem */
                        // Graph-wrap first (combined [@graph, @index]); the
                        // @index metadata then lands as a sibling of @graph.
                        if ($wrapGraph) {
                            $expandedItem = $this->wrapAsGraphObject($expandedItem);
                        }
                        if (! $indexIsNone) {
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
                            } elseif (! array_key_exists(Keyword::Index->value, $expandedItem)) {
                                // The container key supplies @index ONLY when the
                                // entry does not already carry one — an explicit
                                // @index on the entry wins over the map key
                                // (#t0036, step 13.8.3.7.3).
                                $expandedItem[Keyword::Index->value] = $index;
                            }
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
    private function expandIdMap(string $activeProperty, array $map, bool $wrapGraph): array
    {
        // §5.5: @id-map entries are emitted in lexicographic key order.
        ksort($map, SORT_STRING);
        $result = [];
        foreach ($map as $id => $entry) {
            if (! is_string($id)) {
                // PHP decodes a numeric-string JSON key ("1") to an int key;
                // jsonld.js would keep it as the entry's @id.
                $this->safeModeDrop(
                    'invalid map key',
                    "id map key '{$id}' is not a string and its entry is dropped",
                    ['key' => $id],
                );

                continue;
            }
            // A key whose IRI expansion is @none (the literal keyword or an
            // alias of it) carries no @id metadata.
            $idIsNone = $this->expandIri($id, vocab: true) === Keyword::None->value;

            $items = is_array($entry) && array_is_list($entry) ? $entry : [$entry];
            foreach ($items as $item) {
                $expanded = $this->expandElement($item, $activeProperty);
                if ($expanded === null) {
                    continue;
                }
                $list = array_is_list($expanded) ? $expanded : [$expanded];
                foreach ($list as $expandedItem) {
                    if (is_array($expandedItem) && ! array_is_list($expandedItem)) {
                        /** @var array<string, mixed> $expandedItem */
                        // Graph-wrap first (combined [@graph, @id]); the @id
                        // then lands as a sibling of @graph.
                        if ($wrapGraph) {
                            $expandedItem = $this->wrapAsGraphObject($expandedItem);
                        }
                        // §5.5 step 13.8.3.7.4: the map key supplies @id ONLY
                        // when the entry does not already carry one — an explicit
                        // @id on the entry wins over the index key (#tm002).
                        if (! $idIsNone && ! array_key_exists(Keyword::Id->value, $expandedItem)) {
                            $expandedId = $this->expandIri($id, documentRelative: true);
                            if ($expandedId !== null) {
                                // A key that stays a relative IRI is identity
                                // the RDF layer can never carry — the same
                                // check the direct @id branch applies. Default
                                // mode keeps it, per the spec.
                                if (
                                    $this->safe
                                    && ! str_starts_with($expandedId, '_:')
                                    && ! $this->looksLikeAbsoluteIri($expandedId)
                                ) {
                                    throw new DataLossException(
                                        'relative @id reference',
                                        "id map key '{$id}' does not expand to an absolute IRI or blank node",
                                        ['id' => $id, 'expanded' => $expandedId],
                                    );
                                }
                                $expandedItem[Keyword::Id->value] = $expandedId;
                            } else {
                                // The entry silently loses its identity and
                                // becomes a blank node — signable-data loss.
                                $this->safeModeDrop(
                                    'reserved @id value',
                                    "id map key '{$id}' does not expand to an IRI; the entry loses its identity",
                                    ['id' => $id],
                                );
                            }
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
     * §5.5 step 13.4.8 — @type container.
     *
     * @param  array<array-key, mixed>  $map
     * @return list<mixed>
     */
    private function expandTypeMap(string $activeProperty, array $map, bool $wrapGraph): array
    {
        // §5.5: @type-map entries are emitted in lexicographic key order
        // (#tm004/#tm009/#tm010); e.g. "_:bar" (0x5F) before "http://…" (0x68).
        ksort($map, SORT_STRING);
        $result = [];
        foreach ($map as $type => $entry) {
            if (! is_string($type)) {
                // PHP decodes a numeric-string JSON key ("1") to an int key;
                // jsonld.js would keep it as a @type value.
                $this->safeModeDrop(
                    'invalid map key',
                    "type map key '{$type}' is not a string and its entry is dropped",
                    ['key' => $type],
                );

                continue;
            }
            // A key whose IRI expansion is @none (the literal keyword or an
            // alias of it) carries no @type metadata.
            $expandedType = $this->expandIri($type, vocab: true);
            $typeIsNone = $expandedType === Keyword::None->value;
            if ($expandedType === null) {
                // The entries survive but silently lose their @type.
                $this->safeModeDrop(
                    'relative @type reference',
                    "type map key '{$type}' does not expand to an IRI; its entries lose their @type",
                    ['key' => $type],
                );
            } elseif (! $typeIsNone) {
                $this->assertSafeTypeIsAbsolute($type, $expandedType);
            }

            // §5.5 step 13.8.3.1: the map context for a @type map is the
            // PREVIOUS context (the context as it stood before the type-scoped
            // context that introduced this @type-container property), if any —
            // so entries do NOT inherit the containing object's type-scoped term
            // redefinitions (#tc013).
            $savedContext = $this->termDefinitions;
            $mapBase = $this->termDefinitions->getPreviousContext() ?? $this->termDefinitions;
            $this->termDefinitions = $mapBase;
            // §5.5 step 13.8.3.2: when the type (the map key) is a term in the
            // map context with a type-scoped @context, that context is active
            // while the entry is expanded (#tm008/#tc013). Restored after this
            // key's entries.
            $typeTermDef = $mapBase->getTermDefinition($type);
            if (is_array($typeTermDef) && isset($typeTermDef[Keyword::Context->value])) {
                $this->termDefinitions = $this->applyScopedContext($typeTermDef[Keyword::Context->value], $mapBase, overrideProtected: false);
            }

            $items = is_array($entry) && array_is_list($entry) ? $entry : [$entry];
            foreach ($items as $item) {
                // §5.5: a string entry of a @type map is a node reference, not a
                // literal — the string IRI-expands to @id. The active property's
                // @type mapping selects the mode: @type:@vocab resolves against
                // @vocab (#tm019); otherwise document-relative against @base
                // (#tm017).
                if (is_string($item)) {
                    $propDef = $savedContext->getTermDefinition($activeProperty);
                    $vocabType = is_array($propDef) && ($propDef[Keyword::Type->value] ?? null) === Keyword::Vocab->value;
                    $iri = $vocabType
                        ? $this->expandIri($item, vocab: true, documentRelative: true)
                        : $this->expandIri($item, documentRelative: true);
                    if ($iri === null) {
                        // The node reference the string denotes is dropped.
                        $this->safeModeDrop(
                            'reserved @id value',
                            "type map entry '{$item}' does not expand to an IRI and its node reference is dropped",
                            ['id' => $item],
                        );
                    }
                    $expanded = $iri !== null ? [Keyword::Id->value => $iri] : null;
                } else {
                    $expanded = $this->expandElement($item, $activeProperty);
                }
                if ($expanded === null) {
                    continue;
                }
                $list = array_is_list($expanded) ? $expanded : [$expanded];
                foreach ($list as $expandedItem) {
                    if (is_array($expandedItem) && ! array_is_list($expandedItem)) {
                        /** @var array<string, mixed> $expandedItem */
                        if ($wrapGraph) {
                            $expandedItem = $this->wrapAsGraphObject($expandedItem);
                        }
                        if (! $typeIsNone && $expandedType !== null) {
                            $existing = isset($expandedItem[Keyword::Type->value]) && is_array($expandedItem[Keyword::Type->value])
                                ? $expandedItem[Keyword::Type->value]
                                : [];
                            array_unshift($existing, $expandedType);
                            $expandedItem[Keyword::Type->value] = $existing;
                        }
                        ksort($expandedItem);
                    }
                    $result[] = $expandedItem;
                }
            }

            // Restore the active context before the next type key — a type's
            // scoped context is confined to its own entries.
            $this->termDefinitions = $savedContext;
        }

        return $result;
    }

    /**
     * §5.5 step 13.4.10 — @graph container. The property's value is wrapped
     * in a `@graph` object.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Returns null when nothing survives to be wrapped — the caller then
     * omits the property entirely (jsonld.js parity: its @graph-container
     * wrap `continue`s when every element is filtered out).
     *
     * @return list<mixed>|null
     */
    private function expandGraphContainer(string $activeProperty, mixed $value): ?array
    {
        $expanded = $this->expandElement($value, $activeProperty);
        if ($expanded === null) {
            return null;
        }
        $elements = array_is_list($expanded) ? $expanded : [$expanded];

        // §5.5 step 13.11: each top-level element is wrapped in its OWN graph
        // object, unconditionally — an element that is already a graph object
        // is wrapped one further level. (Multiple objects therefore become
        // multiple separate {@graph: […]} objects, not one shared graph.)
        //
        // Wrap-time free-floating filter (jsonld.js parity, beyond the
        // literal spec): object-shaped values were already judged during
        // their own expansion ({@see dropsFreeFloating} covers graph-container
        // terms), so what this catches is value objects that VALUE expansion
        // built after that judgement — raw scalars, @set-unwrapped scalars —
        // which would otherwise become a graph whose only member carries no
        // statement, leaving a dangling graph-name quad in the RDF output.
        // Frames keep such patterns.
        $result = [];
        foreach ($elements as $element) {
            if (
                ! $this->frameExpansion
                && is_array($element)
                && ($element === [] || (! array_is_list($element) && $this->isFreeFloating($element)))
            ) {
                $this->safeModeDrop(
                    $element === [] ? 'empty object' : $this->freeFloatingEventCode($element),
                    'a free-floating object under a @graph container carries no statement and is dropped',
                    ['object' => $element],
                );

                continue;
            }
            $result[] = [Keyword::Graph->value => is_array($element) && array_is_list($element) ? $element : [$element]];
        }

        return $result === [] ? null : $result;
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
                if (is_array($expandedNested) && $expandedNested !== []) {
                    // e.g. a @set inside @nest unwraps to a list, which cannot
                    // be merged as nested properties — the block is dropped.
                    $this->safeModeDrop(
                        'dropped object',
                        'a @nest value expanded to a list instead of a node object and is dropped',
                        ['value' => $nested],
                    );
                }

                continue;
            }

            foreach ($expandedNested as $nestedKey => $nestedValue) {
                if (! is_string($nestedKey)) {
                    continue;
                }
                // §5.5: scalar keywords (@id / @index) are merged verbatim — a
                // nested `@id` (e.g. via an `id` alias inside an @nest block) must
                // stay a scalar, not be wrapped into an array (#tin06).
                if ($nestedKey === Keyword::Id->value || $nestedKey === Keyword::Index->value) {
                    $result[$nestedKey] = $nestedValue;

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
