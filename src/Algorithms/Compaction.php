<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Context\TermDefinitions;
use Accredify\JsonLd\Enums\Keyword;
use Accredify\JsonLd\Exceptions\JsonLdException;

/**
 * JSON-LD 1.1 Compaction Algorithm (§5.6) — first-pass implementation.
 *
 * Compacts an *expanded* JSON-LD document against an active context,
 * applying term definitions, compact IRIs, `@vocab`, and container
 * coercions to produce the most concise applicable form.
 *
 * Implemented:
 *  - IRI compaction (§5.7): exact term match (preferring type/@id-coercion
 *    matches), compact-IRI (`prefix:suffix`), `@vocab` stripping, keyword
 *    aliases.
 *  - Value compaction (§5.9): dropping coerced `@type`, `@language`
 *    defaults, `@type: @id` node references, `@value`-only scalars,
 *    `@direction`, and the `@type: @none` no-compaction rule.
 *  - `@list` / `@set` container coercion; array-vs-single normalisation.
 *  - `@id` / `@type` keyword compaction; recursion into `@graph` / `@included`.
 *  - `@language` / `@index` / `@id` / `@type` container maps (incl. `@none`
 *    keys, aliased) and top-level multi-node `@graph` wrapping.
 *  - `@nest` property grouping.
 *
 * Deferred:
 *  - `@graph` container maps (`[@graph, @id]` / `[@graph, @index]`).
 *  - `@reverse`, value-aware inverse-context term selection (§5.6.2 full),
 *    and property-scoped / type-scoped contexts during compaction.
 *  - Spec-faithful "compactArrays" / "ordered" option handling beyond the
 *    defaults.
 *
 * This is intentionally narrower than {@see Expansion}; it covers the
 * shapes the W3C basic-compaction tests and real VC/OBv3 documents use.
 */
class Compaction
{
    /**
     * Inverse map: expanded IRI → list of term definitions that map to it,
     * each as ['term' => string, 'def' => array].
     *
     * @var array<string, list<array{term: string, def: array<array-key, mixed>}>>
     */
    private array $inverse = [];

    /**
     * Reverse inverse map: expanded reverse-IRI → the reverse-property term
     * name (a term defined via `@reverse`), so expanded `@reverse` entries can
     * compact back to that term.
     *
     * @var array<string, string>
     */
    private array $reverseInverse = [];

    /**
     * Snapshot of the active context + inverse to roll back to when entering a
     * NEW node object — set when a non-propagating type-scoped context is
     * activated (§5.6: type-scoped contexts do not propagate into nested node
     * objects). Null when no rollback is pending.
     *
     * @var array{context: TermDefinitions, inverse: array<string, list<array{term: string, def: array<array-key, mixed>}>>, reverse: array<string, string>}|null
     */
    private ?array $previousContext = null;

    public function __construct(private TermDefinitions $activeContext)
    {
        $this->buildInverse();
    }

    /**
     * Compact an expanded document (array of node objects, or a single node).
     *
     * @param  array<array-key, mixed>  $expanded
     * @return array<array-key, mixed>
     */
    public function compact(array $expanded): array
    {
        $result = $this->compactElement($expanded, null);

        // §5.6 step 7: shape the top-level result.
        if (is_array($result) && array_is_list($result)) {
            if ($result === []) {
                return [];
            }
            // A single node object is returned bare.
            if (count($result) === 1 && is_array($result[0])) {
                return $result[0];
            }

            // Multiple top-level nodes are wrapped in a (possibly aliased)
            // @graph map (#t0046 "multiple objects without @context use @graph").
            return [$this->compactIri(Keyword::Graph->value, vocab: true) => $result];
        }

        return is_array($result) ? $result : [];
    }

    private function buildInverse(): void
    {
        // Rebuilt from scratch each call (the active context changes when a
        // property-scoped @context is activated mid-compaction).
        $this->inverse = [];
        $this->reverseInverse = [];

        foreach ($this->activeContext->termDefinitions as $term => $def) {
            $definition = is_string($def) ? ['@id' => $def] : $def;
            // A reverse-property term (defined via @reverse) is indexed so an
            // expanded @reverse-map property can compact back to it.
            if (isset($definition['@reverse']) && is_string($definition['@reverse'])) {
                $this->reverseInverse[$this->expandTermIri($definition['@reverse'])] ??= (string) $term;
            }
            if (! isset($definition['@id']) || ! is_string($definition['@id'])) {
                continue;
            }
            // A term's @id is frequently a compact IRI (e.g. "ex:term1"); the
            // inverse must key on the FULLY-expanded IRI so that expanded
            // input properties resolve back to the term.
            $expandedIri = $this->expandTermIri($definition['@id']);
            $this->inverse[$expandedIri][] = ['term' => (string) $term, 'def' => $definition];
        }
    }

    /**
     * Returns the inline property-scoped `@context` map attached to a term, or
     * null. Remote/string scoped contexts are not resolved during compaction.
     *
     * @return array<array-key, mixed>|null
     */
    private function propertyScopedContext(string $term): ?array
    {
        $def = $this->activeContext->getTermDefinition($term);
        if (! is_array($def) || ! array_key_exists(Keyword::Context->value, $def)) {
            return null;
        }
        $ctx = $def[Keyword::Context->value];

        return is_array($ctx) ? $ctx : null;
    }

    /**
     * True when a scoped @context (a map, or a list of layers) carries an
     * explicit `@propagate` entry equal to $value.
     *
     * @param  array<array-key, mixed>  $context
     */
    private function contextHasPropagate(array $context, bool $value): bool
    {
        if (array_is_list($context)) {
            foreach ($context as $layer) {
                if (is_array($layer) && ($layer[Keyword::Propagate->value] ?? null) === $value) {
                    return true;
                }
            }

            return false;
        }

        return ($context[Keyword::Propagate->value] ?? null) === $value;
    }

    /**
     * @param  array<array-key, mixed>  $scoped
     */
    private function propagatesFalse(array $scoped): bool
    {
        return $this->contextHasPropagate($scoped, false);
    }

    /**
     * @param  list<array<array-key, mixed>>  $typeContexts
     */
    private function anyPropagatesTrue(array $typeContexts): bool
    {
        foreach ($typeContexts as $typeContext) {
            if ($this->contextHasPropagate($typeContext, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Overlays a property-scoped `@context` onto a clone of the active context
     * and rebuilds the inverse, so the scoped terms are selectable while the
     * property's value is compacted. A bare term's `@id` is resolved through
     * the inherited `@vocab` (so it appears in the inverse); a null entry
     * removes the term (nullification).
     *
     * @param  array<array-key, mixed>  $scoped
     */
    private function activateScopedContext(array $scoped): void
    {
        $ctx = clone $this->activeContext;
        $vocab = $ctx->getVocab();

        foreach ($scoped as $key => $value) {
            if (! is_string($key) || $this->isKeyword($key)) {
                continue; // keyword entries (@vocab/@language/…) are not applied here
            }
            if ($value === null) {
                unset($ctx->termDefinitions[$key]);

                continue;
            }
            $def = is_string($value) ? [Keyword::Id->value => $value] : (is_array($value) ? $value : null);
            if ($def === null) {
                continue;
            }
            // Resolve a bare term's @id through @vocab so the inverse keys on it.
            if (! isset($def[Keyword::Id->value]) && ! str_contains($key, ':') && $vocab !== null) {
                $def[Keyword::Id->value] = $vocab.$key;
            }
            $ctx->termDefinitions[$key] = $def;
        }

        $this->activeContext = $ctx;
        $this->buildInverse();
    }

    /**
     * Resolves a context value (a term's `@id` or `@type`) to its full IRI,
     * expanding compact IRIs (`prefix:suffix`) through the active context.
     *
     * @param  array<string, true>  $seen  cycle guard
     */
    private function expandTermIri(string $value, array $seen = []): string
    {
        if ($value === '' || $this->isKeyword($value) || isset($seen[$value])) {
            return $value;
        }
        if (! str_contains($value, ':')) {
            return $value;
        }

        [$prefix, $suffix] = explode(':', $value, 2);
        if ($prefix === '_' || str_starts_with($suffix, '//')) {
            // Blank node or already an absolute IRI (scheme://…).
            return $value;
        }

        $prefixDef = $this->activeContext->getTermDefinition($prefix);
        if (is_array($prefixDef) && isset($prefixDef['@id']) && is_string($prefixDef['@id'])) {
            $seen[$value] = true;

            return $this->expandTermIri($prefixDef['@id'], $seen).$suffix;
        }

        return $value;
    }

    private function compactElement(mixed $element, ?string $activeProperty): mixed
    {
        if (is_array($element) && array_is_list($element)) {
            $compactedItems = [];
            foreach ($element as $item) {
                $compactedItems[] = $this->compactElement($item, $activeProperty);
            }

            // §5.6: a single-item array compacts to the item unless the
            // active property is a @set container (which always keeps an
            // array). A @list container DOES unwrap here — the single
            // {@list: …} element it contains is then compacted to its bare
            // array form by the @list branch of compactObject.
            if (count($compactedItems) === 1 && ! $this->hasContainer($activeProperty, Keyword::Set->value)) {
                return $compactedItems[0];
            }

            return $compactedItems;
        }

        if (is_array($element)) {
            return $this->compactObject($element, $activeProperty);
        }

        return $element;
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private function compactObject(array $node, ?string $activeProperty): mixed
    {
        // Value object → value compaction.
        if (array_key_exists(Keyword::Value->value, $node) || isset($node[Keyword::Id->value]) && count($node) === 1) {
            $compactedValue = $this->compactValue($node, $activeProperty);
            if (! is_array($compactedValue)) {
                return $compactedValue;
            }
            $node = $compactedValue;
        }

        // @list object.
        if (isset($node[Keyword::List->value]) && is_array($node[Keyword::List->value])) {
            $listContainer = $this->isListContainer($activeProperty);
            $compactedList = $this->compactElement(array_values($node[Keyword::List->value]), $activeProperty);
            $asArray = is_array($compactedList) && array_is_list($compactedList) ? $compactedList : [$compactedList];
            if ($listContainer) {
                return $asArray;
            }

            return [$this->compactIri(Keyword::List->value, vocab: true) => $asArray];
        }

        // A new node object resets a non-propagating type-scoped context that
        // an ancestor activated (§5.6 — type-scoped contexts do not propagate
        // into nested node objects). Value objects / bare @id references
        // (handled above) are NOT node objects and keep the scoped context.
        $isNodeObject = ! array_key_exists(Keyword::Value->value, $node)
            && ! (isset($node[Keyword::Id->value]) && count($node) === 1);

        $savedPrevious = $this->previousContext;
        if ($isNodeObject && $this->previousContext !== null) {
            $this->activeContext = $this->previousContext['context'];
            $this->inverse = $this->previousContext['inverse'];
            $this->reverseInverse = $this->previousContext['reverse'];
            $this->previousContext = null;
        }

        // Type-scoped contexts (§5.6): activate the scoped @context of each of
        // the node's types (each type compacted to a term, in lexicographic
        // order of the type IRI) before the node's own keys/values compact.
        // Non-propagating: record the pre-activation context so nested node
        // objects roll back to it.
        $typeContexts = $this->nodeTypeScopedContexts($node);
        $tsSavedContext = $typeContexts !== [] ? $this->activeContext : null;
        $tsSavedInverse = $typeContexts !== [] ? $this->inverse : null;
        $tsSavedReverse = $typeContexts !== [] ? $this->reverseInverse : null;
        if ($typeContexts !== []) {
            $preActivation = ['context' => $this->activeContext, 'inverse' => $this->inverse, 'reverse' => $this->reverseInverse];
            foreach ($typeContexts as $scoped) {
                $this->activateScopedContext($scoped);
            }
            // Type-scoped contexts are non-propagating by default — record the
            // rollback snapshot so nested node objects revert. An explicit
            // @propagate:true makes them flow in (no rollback) (#tc026).
            if (! $this->anyPropagatesTrue($typeContexts)) {
                $this->previousContext = $preActivation;
            }
        }

        $result = [];
        foreach ($node as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if ($key === Keyword::Id->value) {
                $result[$this->compactIri(Keyword::Id->value, vocab: true)] = is_string($value)
                    ? $this->compactIri($value, vocab: false)
                    : $value;

                continue;
            }

            if ($key === Keyword::Type->value) {
                $result[$this->compactIri(Keyword::Type->value, vocab: true)] = $this->compactTypeValue($value);

                continue;
            }

            // @graph / @included carry node objects whose contents must be
            // compacted recursively (the previous code passed them through
            // verbatim, leaving inner nodes un-compacted). A single compacted
            // member unwraps to an object; multiple members stay an array.
            if (($key === Keyword::Graph->value || $key === Keyword::Included->value) && is_array($value)) {
                $items = array_is_list($value) ? $value : [$value];
                $compactedItems = [];
                foreach ($items as $item) {
                    $compactedItems[] = $this->compactElement($item, null);
                }
                $result[$this->compactIri($key, vocab: true)] = count($compactedItems) === 1 && is_array($compactedItems[0])
                    ? $compactedItems[0]
                    : $compactedItems;

                continue;
            }

            // @reverse map: each reverse property is compacted; a property
            // with a reverse-coerced term (defined via @reverse) is hoisted to
            // the top level under that term, the rest stay under (aliased)
            // @reverse (§5.6).
            if ($key === Keyword::Reverse->value && is_array($value)) {
                $reverseMap = [];
                foreach ($value as $prop => $nodes) {
                    if (! is_string($prop)) {
                        continue;
                    }
                    $nodeList = is_array($nodes) && array_is_list($nodes) ? $nodes : [$nodes];
                    $reverseTerm = $this->reverseInverse[$prop] ?? null;
                    $activeProp = $reverseTerm ?? $this->compactIri($prop, vocab: true);
                    $compacted = $this->compactElement($nodeList, $activeProp);
                    if ($reverseTerm !== null) {
                        $result[$reverseTerm] = $compacted;
                    } else {
                        $reverseMap[$this->compactIri($prop, vocab: true)] = $compacted;
                    }
                }
                if ($reverseMap !== []) {
                    $result[$this->compactIri(Keyword::Reverse->value, vocab: true)] = $reverseMap;
                }

                continue;
            }

            if ($this->isKeyword($key)) {
                // Other keywords pass through with a compacted key.
                $result[$this->compactIri($key, vocab: true)] = $value;

                continue;
            }

            // An empty array still produces the property (under its
            // value-agnostic term).
            if (is_array($value) && array_is_list($value) && $value === []) {
                $this->assignProperty($result, $this->compactIri($key, vocab: true), []);

                continue;
            }

            // §5.6.2: each expanded item may select a DIFFERENT term (e.g. one
            // node ref whose @id round-trips picks a @type:@vocab term while
            // another picks @type:@id; lists with different common type /
            // language pick different @list terms). Group the value's items by
            // their per-item term, then compact each group under its term. For
            // single-valued / uniform properties this collapses to one group
            // (identical to non-grouped behaviour).
            $items = is_array($value) && array_is_list($value) ? $value : [$value];
            $groups = [];
            $order = [];
            foreach ($items as $item) {
                $term = $this->compactIri($key, vocab: true, value: [$item]);
                if (! array_key_exists($term, $groups)) {
                    $groups[$term] = [];
                    $order[] = $term;
                }
                $groups[$term][] = $item;
            }

            foreach ($order as $term) {
                $groupItems = $groups[$term];

                // §5.6: a property whose term carries a property-scoped
                // @context activates it while its value is compacted, then
                // rolls back. The term itself was already selected against the
                // outer context above.
                $scoped = $this->propertyScopedContext($term);
                $savedContext = $scoped !== null ? $this->activeContext : null;
                $savedInverse = $scoped !== null ? $this->inverse : null;
                $savedReverse = $scoped !== null ? $this->reverseInverse : null;
                $savedPrevious = $this->previousContext;
                if ($scoped !== null) {
                    $preScoped = ['context' => $this->activeContext, 'inverse' => $this->inverse, 'reverse' => $this->reverseInverse];
                    $this->activateScopedContext($scoped);
                    // A property-scoped context PROPAGATES into the value's
                    // nested nodes (unlike type-scoped): clear the type-scoped
                    // rollback so the nested node keeps these terms (#tc013/
                    // #tc019). An explicit @propagate:false confines it instead,
                    // so the nested node rolls back to the pre-activation
                    // context (#tc027).
                    $this->previousContext = $this->propagatesFalse($scoped) ? $preScoped : null;
                }

                if ($this->hasContainer($term, Keyword::Graph->value)) {
                    $compactedValue = $this->compactGraphContainer($groupItems, $term);
                } else {
                    $mapType = $this->mapContainerType($term);
                    $compactedValue = $mapType !== null
                        ? $this->compactContainerMap($groupItems, $mapType)
                        : $this->compactElement($groupItems, $term);
                }

                if ($scoped !== null && $savedContext !== null) {
                    $this->activeContext = $savedContext;
                    $this->inverse = $savedInverse ?? [];
                    $this->reverseInverse = $savedReverse ?? [];
                    $this->previousContext = $savedPrevious;
                }

                $this->assignProperty($result, $term, $compactedValue);
            }
        }

        // Roll back any type-scoped context activated for this node, and
        // restore the inherited rollback snapshot.
        if ($tsSavedContext !== null) {
            $this->activeContext = $tsSavedContext;
            $this->inverse = $tsSavedInverse ?? [];
            $this->reverseInverse = $tsSavedReverse ?? [];
        }
        $this->previousContext = $savedPrevious;

        return $result;
    }

    /**
     * Returns the inline type-scoped `@context` maps for a node's `@type`
     * values: each type is compacted to a term and, if that term carries an
     * inline `@context`, it is collected — in lexicographic order of the type
     * IRI (§5.6 / §4.3). Computed against the pre-activation context.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<array<array-key, mixed>>
     */
    private function nodeTypeScopedContexts(array $node): array
    {
        $types = $node[Keyword::Type->value] ?? null;
        if ($types === null) {
            return [];
        }
        $types = is_array($types) && array_is_list($types) ? $types : [$types];
        $typeIris = array_values(array_filter($types, 'is_string'));
        sort($typeIris, SORT_STRING);

        $out = [];
        foreach ($typeIris as $typeIri) {
            $def = $this->activeContext->getTermDefinition($this->compactIri($typeIri, vocab: true));
            if (is_array($def) && array_key_exists(Keyword::Context->value, $def) && is_array($def[Keyword::Context->value])) {
                $out[] = $def[Keyword::Context->value];
            }
        }

        return $out;
    }

    /**
     * Assigns a compacted property value into the result, routing it under the
     * (aliased) nest term when the property's term definition carries `@nest`
     * (§5.6), otherwise placing it at the top level.
     *
     * @param  array<string, mixed>  $result  modified in place
     */
    private function assignProperty(array &$result, string $term, mixed $value): void
    {
        $nestTerm = $this->nestTermFor($term);
        if ($nestTerm !== null) {
            if (! isset($result[$nestTerm]) || ! is_array($result[$nestTerm])) {
                $result[$nestTerm] = [];
            }
            $result[$nestTerm][$term] = $value;
        } else {
            $result[$term] = $value;
        }
    }

    /**
     * Returns the (verbatim) nest term a property compacts under when its term
     * definition carries `@nest`, or null. The `@nest` value must be `@nest`
     * itself or a term that aliases `@nest`; anything else is an invalid
     * `@nest` value (§5.6).
     */
    private function nestTermFor(string $compactedKey): ?string
    {
        $termDef = $this->activeContext->getTermDefinition($compactedKey);
        if (! is_array($termDef) || ! isset($termDef[Keyword::Nest->value]) || ! is_string($termDef[Keyword::Nest->value])) {
            return null;
        }

        $nest = $termDef[Keyword::Nest->value];
        if ($nest !== Keyword::Nest->value) {
            $nestDef = $this->activeContext->getTermDefinition($nest);
            if (! is_array($nestDef) || ($nestDef[Keyword::Id->value] ?? null) !== Keyword::Nest->value) {
                throw new JsonLdException("Invalid @nest value in term '{$compactedKey}'");
            }
        }

        return $nest;
    }

    /**
     * §5.9 Value Compaction.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function compactValue(array $value, ?string $activeProperty): mixed
    {
        $termDef = $activeProperty !== null ? $this->activeContext->getTermDefinition($activeProperty) : null;
        $typeMapping = is_array($termDef) && isset($termDef['@type']) && is_string($termDef['@type']) ? $termDef['@type'] : null;

        // Node reference under a @type: @id term → compact to the bare IRI.
        if (isset($value[Keyword::Id->value]) && count($value) === 1 && is_string($value[Keyword::Id->value])) {
            if ($typeMapping === Keyword::Id->value || $typeMapping === Keyword::Vocab->value) {
                return $this->compactIri($value[Keyword::Id->value], vocab: $typeMapping === Keyword::Vocab->value);
            }

            // Otherwise keep it as an {@id: …} object with a compacted @id key.
            return [$this->compactIri(Keyword::Id->value, vocab: true) => $this->compactIri($value[Keyword::Id->value], vocab: false)];
        }

        if (! array_key_exists(Keyword::Value->value, $value)) {
            return $value;
        }

        $raw = $value[Keyword::Value->value];
        $valueType = isset($value[Keyword::Type->value]) && is_string($value[Keyword::Type->value]) ? $value[Keyword::Type->value] : null;
        $valueLang = isset($value[Keyword::Language->value]) && is_string($value[Keyword::Language->value]) ? $value[Keyword::Language->value] : null;
        $valueDir = isset($value[Keyword::Direction->value]) && is_string($value[Keyword::Direction->value]) ? $value[Keyword::Direction->value] : null;
        $hasOther = (bool) array_diff(array_keys($value), [Keyword::Value->value, Keyword::Type->value, Keyword::Language->value, Keyword::Direction->value]);

        // A @type: @none term disables value compaction (§5.6.3): the value
        // object is rebuilt with aliased keys, never collapsed to a scalar.
        $typeNone = $typeMapping === Keyword::None->value;

        // If the term coerces to the value's @type, drop @type.
        if (! $typeNone && $valueType !== null && $typeMapping !== null && $this->expandedEquals($typeMapping, $valueType) && ! $hasOther && $valueLang === null && $valueDir === null) {
            return $raw;
        }

        // Plain @value with no @type / @language / @direction → the scalar.
        if (! $typeNone && $valueType === null && $valueLang === null && $valueDir === null && ! $hasOther) {
            return $raw;
        }

        // @language collapse (§5.6.3): a language-tagged value whose language
        // matches the term's @language coercion — or, absent one, the active
        // default @language — drops @language and becomes the bare scalar.
        // Skipped when a default @direction is active (direction would be lost).
        $termHasLang = is_array($termDef) && array_key_exists(Keyword::Language->value, $termDef);
        $termLang = $termHasLang && is_string($termDef[Keyword::Language->value]) ? $termDef[Keyword::Language->value] : null;
        $effectiveLang = $termHasLang ? $termLang : $this->activeContext->getDefaultLanguage();
        if (
            ! $typeNone
            && $valueType === null
            && $valueDir === null
            && ! $hasOther
            && $valueLang !== null
            && $effectiveLang !== null
            && $this->activeContext->getDefaultDirection() === null
            && strtolower($valueLang) === strtolower($effectiveLang)
        ) {
            return $raw;
        }

        // Otherwise rebuild the value object with compacted keys.
        $out = [$this->compactIri(Keyword::Value->value, vocab: true) => $raw];
        if ($valueType !== null) {
            $out[$this->compactIri(Keyword::Type->value, vocab: true)] = $this->compactIri($valueType, vocab: true);
        }
        if ($valueLang !== null) {
            $out[$this->compactIri(Keyword::Language->value, vocab: true)] = $valueLang;
        }
        if ($valueDir !== null) {
            $out[$this->compactIri(Keyword::Direction->value, vocab: true)] = $valueDir;
        }

        return $out;
    }

    /**
     * Returns the map-container type (@language / @index / @id / @type) of a
     * compacted property term, or null if it has no map container. Combined
     * containers that include @graph are excluded (graph maps are a separate,
     * deferred feature).
     */
    private function mapContainerType(?string $compactedKey): ?string
    {
        if ($compactedKey === null || $this->hasContainer($compactedKey, Keyword::Graph->value)) {
            return null;
        }
        foreach ([Keyword::Language->value, Keyword::Index->value, Keyword::Id->value, Keyword::Type->value] as $kw) {
            if ($this->hasContainer($compactedKey, $kw)) {
                return $kw;
            }
        }

        return null;
    }

    /**
     * Compacts the value of a `@graph`-container property (§5.6). Each item is
     * a graph object `{@graph:[…], @id?, @index?}`:
     *  - `[@graph, @id]`   → a map keyed by the (compacted) graph @id, else @none;
     *  - `[@graph, @index]`→ a map keyed by the graph @index, else @none;
     *  - plain `@graph`    → a simple graph unwraps to its node (single) or an
     *                        `@included` wrapper (multiple); a *named* graph
     *                        (has @id) is kept as `{@id , @graph}`.
     * `@set` keeps the result (or each map entry) as an array.
     *
     * @param  list<mixed>  $items
     */
    private function compactGraphContainer(array $items, string $activeProperty): mixed
    {
        $hasId = $this->hasContainer($activeProperty, Keyword::Id->value);
        $hasIndex = $this->hasContainer($activeProperty, Keyword::Index->value);
        $asSet = $this->hasContainer($activeProperty, Keyword::Set->value);
        $none = $this->compactIri(Keyword::None->value, vocab: true);

        $map = [];
        $list = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! array_key_exists(Keyword::Graph->value, $item)) {
                // Not a graph object (e.g. a plain node reference) — compact normally.
                $list[] = $this->compactElement($item, $activeProperty);

                continue;
            }

            $graphValue = $item[Keyword::Graph->value];
            $graphNodes = is_array($graphValue) && array_is_list($graphValue) ? $graphValue : [$graphValue];
            $content = $this->compactElement($graphNodes, null);

            if ($hasId || $hasIndex) {
                if ($hasId) {
                    $key = isset($item[Keyword::Id->value]) && is_string($item[Keyword::Id->value])
                        ? $this->compactIri($item[Keyword::Id->value], vocab: false)
                        : $none;
                } else {
                    $key = isset($item[Keyword::Index->value]) && is_string($item[Keyword::Index->value])
                        ? $item[Keyword::Index->value]
                        : $none;
                }
                $entry = ($asSet && ! (is_array($content) && array_is_list($content))) ? [$content] : $content;
                if (array_key_exists($key, $map)) {
                    if (! is_array($map[$key]) || ! array_is_list($map[$key])) {
                        $map[$key] = [$map[$key]];
                    }
                    $map[$key][] = $entry;
                } else {
                    $map[$key] = $entry;
                }

                continue;
            }

            // Plain @graph container.
            if (isset($item[Keyword::Id->value]) && is_string($item[Keyword::Id->value])) {
                // A named graph is preserved as {@id, @graph}.
                $list[] = [
                    $this->compactIri(Keyword::Id->value, vocab: true) => $this->compactIri($item[Keyword::Id->value], vocab: false),
                    $this->compactIri(Keyword::Graph->value, vocab: true) => $content,
                ];
            } elseif (is_array($content) && array_is_list($content)) {
                // A simple graph with multiple nodes → @included wrapper.
                $list[] = [$this->compactIri(Keyword::Included->value, vocab: true) => $content];
            } else {
                // A simple graph with a single node unwraps to the bare node.
                $list[] = $content;
            }
        }

        if ($hasId || $hasIndex) {
            return $map;
        }

        return (! $asSet && count($list) === 1) ? $list[0] : $list;
    }

    /**
     * Compacts an expanded array of value/node objects into a container map
     * keyed per $mapType. Collisions on the same key arrayify (the second
     * value turns a scalar entry into a list).
     *
     * @param  list<mixed>  $items
     * @return array<string, mixed>
     */
    private function compactContainerMap(array $items, string $mapType): array
    {
        $map = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            [$key, $entry] = $this->mapKeyAndEntry($item, $mapType);
            if ($key === null) {
                continue;
            }

            if (array_key_exists($key, $map)) {
                if (! is_array($map[$key]) || ! array_is_list($map[$key])) {
                    $map[$key] = [$map[$key]];
                }
                $map[$key][] = $entry;
            } else {
                $map[$key] = $entry;
            }
        }

        return $map;
    }

    /**
     * Derives the (map key, compacted entry) pair for one expanded item under
     * a given container-map type.
     *
     * @param  array<array-key, mixed>  $item
     * @return array{0: ?string, 1: mixed}
     */
    private function mapKeyAndEntry(array $item, string $mapType): array
    {
        // The synthetic "no key" sentinel is @none, which must itself be
        // compacted to a keyword alias if the active context defines one
        // (e.g. "none": "@none").
        $none = $this->compactIri(Keyword::None->value, vocab: true);

        switch ($mapType) {
            case Keyword::Language->value:
                $lang = isset($item[Keyword::Language->value]) && is_string($item[Keyword::Language->value])
                    ? $item[Keyword::Language->value]
                    : $none;

                // Within a @language map the entry is the bare @value.
                return [$lang, $item[Keyword::Value->value] ?? null];

            case Keyword::Index->value:
                $index = isset($item[Keyword::Index->value]) && is_string($item[Keyword::Index->value])
                    ? $item[Keyword::Index->value]
                    : $none;
                $stripped = $item;
                unset($stripped[Keyword::Index->value]);

                return [$index, $this->compactObject($stripped, null)];

            case Keyword::Id->value:
                if (! isset($item[Keyword::Id->value]) || ! is_string($item[Keyword::Id->value])) {
                    return [$none, $this->compactObject($item, null)];
                }
                $idKey = $this->compactIri($item[Keyword::Id->value], vocab: false);
                $stripped = $item;
                unset($stripped[Keyword::Id->value]);

                return [$idKey, $this->compactObject($stripped, null)];

            case Keyword::Type->value:
                $types = isset($item[Keyword::Type->value]) && is_array($item[Keyword::Type->value])
                    ? array_values($item[Keyword::Type->value])
                    : [];
                if ($types === [] || ! is_string($types[0])) {
                    return [$none, $this->compactObject($item, null)];
                }
                $typeKey = $this->compactIri($types[0], vocab: true);
                $rest = array_slice($types, 1);
                $stripped = $item;
                if ($rest === []) {
                    unset($stripped[Keyword::Type->value]);
                } else {
                    $stripped[Keyword::Type->value] = $rest;
                }

                return [$typeKey, $this->compactObject($stripped, null)];
        }

        return [null, null];
    }

    /**
     * @return string|list<string>
     */
    private function compactTypeValue(mixed $value): string|array
    {
        $types = is_array($value) ? $value : [$value];
        $compacted = [];
        foreach ($types as $t) {
            if (is_string($t)) {
                $compacted[] = $this->compactIri($t, vocab: true);
            }
        }
        if (count($compacted) === 1) {
            return $compacted[0];
        }

        return $compacted;
    }

    /**
     * §5.7 IRI Compaction.
     */
    private function compactIri(string $iri, bool $vocab, mixed $value = null): string
    {
        // A keyword compacts to a keyword alias when the active context
        // defines one (a term whose @id maps to the keyword, e.g. "id": "@id"
        // or "type": "@type"); otherwise it compacts to itself. Keyword
        // selection is value-agnostic.
        if ($this->isKeyword($iri)) {
            if ($vocab && isset($this->inverse[$iri])) {
                return $this->selectTerm($this->inverse[$iri]);
            }

            return $iri;
        }

        // Exact term match. When the value being compacted is known, prefer a
        // term whose coercion (@type / @language) matches it, so value
        // compaction collapses the value; otherwise a plain term wins (stable
        // round-trip). Any exact match beats a compact IRI.
        if ($vocab && isset($this->inverse[$iri])) {
            return $this->selectTerm($this->inverse[$iri], $value);
        }

        // Compact IRI: find the longest prefix term whose @id is a strict
        // prefix of the IRI.
        $best = null;
        $bestLen = -1;
        foreach ($this->inverse as $termIri => $candidates) {
            if ($termIri === $iri || ! str_starts_with($iri, $termIri)) {
                continue;
            }
            $prefixTerm = $candidates[0]['term'];
            // Don't form a compact IRI from a term that itself contains ':'.
            if (str_contains($prefixTerm, ':')) {
                continue;
            }
            $len = strlen($termIri);
            if ($len > $bestLen) {
                $bestLen = $len;
                $best = $prefixTerm.':'.substr($iri, $len);
            }
        }
        if ($best !== null) {
            return $best;
        }

        // @vocab stripping for vocab-mode IRIs.
        if ($vocab) {
            $vocabIri = $this->activeContext->getVocab();
            if ($vocabIri !== null && str_starts_with($iri, $vocabIri) && $iri !== $vocabIri) {
                return substr($iri, strlen($vocabIri));
            }
        }

        // Document-relative compaction against @base for non-vocab IRIs.
        if (! $vocab) {
            $base = $this->activeContext->getBase();
            if ($base !== null && $base !== '' && str_starts_with($iri, $base)) {
                $suffix = substr($iri, strlen($base));
                if ($suffix !== '') {
                    return $suffix;
                }
            }
        }

        return $iri;
    }

    /**
     * Pick the best term among candidates mapping to the same IRI.
     *
     * When the value being compacted is known ($value !== null) the term whose
     * coercion best matches the value wins, so value compaction can collapse
     * the value (e.g. a `@type: @vocab` term for a node reference, a
     * `@type: T` / `@language: L` term for matching value objects). When the
     * coercion does not match — or no value is supplied (keyword / @id
     * compaction) — a plain term (no `@type`/`@container`/`@language`) wins for
     * a stable round-trip, then the shortest / lexicographically-first name.
     *
     * @param  list<array{term: string, def: array<array-key, mixed>}>  $candidates
     */
    private function selectTerm(array $candidates, mixed $value = null): string
    {
        $sig = $value !== null ? $this->valueSignature($value) : null;

        usort($candidates, function (array $a, array $b) use ($sig, $value) {
            if ($sig !== null) {
                $sa = $this->scoreCandidate($a['def'], $sig, $value);
                $sb = $this->scoreCandidate($b['def'], $sig, $value);
                if ($sa !== $sb) {
                    return $sb <=> $sa; // higher score first
                }
            } else {
                $aPlain = ! isset($a['def']['@type']) && ! isset($a['def']['@container']);
                $bPlain = ! isset($b['def']['@type']) && ! isset($b['def']['@container']);
                if ($aPlain !== $bPlain) {
                    return $aPlain ? -1 : 1;
                }
            }
            $lenCmp = strlen($a['term']) <=> strlen($b['term']);

            return $lenCmp !== 0 ? $lenCmp : strcmp($a['term'], $b['term']);
        });

        return $candidates[0]['term'];
    }

    /**
     * Summarise an expanded property value (a list of value/node objects, or a
     * single `@list` object) for value-aware term selection. A lone `@list` is
     * summarised by its CONTENTS with `isList` set, so a `@container: @list`
     * term is required.
     *
     * @return array{allNodeRefs: bool, allValueObjects: bool, commonType: ?string, commonLang: ?string, noTypeNoLang: bool, empty: bool, isList: bool}
     */
    private function valueSignature(mixed $value): array
    {
        $items = is_array($value) && array_is_list($value) ? $value : [$value];

        // A single @list object → select against the list's contents.
        if (count($items) === 1 && is_array($items[0]) && array_key_exists(Keyword::List->value, $items[0]) && is_array($items[0][Keyword::List->value])) {
            $sig = $this->valueSignature(array_values($items[0][Keyword::List->value]));
            $sig['isList'] = true;

            return $sig;
        }

        $sig = ['allNodeRefs' => true, 'allValueObjects' => true, 'commonType' => null, 'commonLang' => null, 'noTypeNoLang' => true, 'empty' => $items === [], 'isList' => false];

        $types = [];
        $langs = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                // A bare scalar is a value with neither @type nor @language.
                $sig['allNodeRefs'] = false;
                $types["\0"] = null;
                $langs["\0"] = null;

                continue;
            }
            if (! (isset($item[Keyword::Id->value]) && count($item) === 1)) {
                $sig['allNodeRefs'] = false;
            }
            if (array_key_exists(Keyword::Value->value, $item)) {
                $t = isset($item[Keyword::Type->value]) && is_string($item[Keyword::Type->value]) ? $item[Keyword::Type->value] : null;
                $l = isset($item[Keyword::Language->value]) && is_string($item[Keyword::Language->value]) ? $item[Keyword::Language->value] : null;
                $types[$t ?? "\0"] = $t;
                $langs[$l ?? "\0"] = $l;
                if ($t !== null || $l !== null) {
                    $sig['noTypeNoLang'] = false;
                }
            } else {
                $sig['allValueObjects'] = false;
                $sig['noTypeNoLang'] = false;
            }
        }

        if ($sig['allValueObjects'] && count($types) === 1) {
            $sig['commonType'] = reset($types);
        }
        if ($sig['allValueObjects'] && count($langs) === 1) {
            $sig['commonLang'] = reset($langs);
        }

        return $sig;
    }

    /**
     * Score how well a term definition's coercion matches a value signature.
     * Higher is better; a non-matching coerced term scores 0 (below the plain
     * baseline of 1) so it never displaces a plain term unless it genuinely
     * matches.
     *
     * @param  array<array-key, mixed>  $def
     * @param  array{allNodeRefs: bool, allValueObjects: bool, commonType: ?string, commonLang: ?string, noTypeNoLang: bool, empty: bool, isList: bool}  $sig
     */
    private function scoreCandidate(array $def, array $sig, mixed $value): int
    {
        $type = isset($def[Keyword::Type->value]) && is_string($def[Keyword::Type->value]) ? $def[Keyword::Type->value] : null;
        $typeIri = $type !== null ? $this->resolveTypeMapping($type) : null;
        $hasLang = array_key_exists(Keyword::Language->value, $def);
        $lang = $hasLang && is_string($def[Keyword::Language->value]) ? $def[Keyword::Language->value] : null;
        $coerced = $type !== null || $hasLang || isset($def[Keyword::Container->value]);
        $baseline = $coerced ? 0 : 1;

        if ($sig['empty']) {
            return $baseline;
        }

        $langMatches = $hasLang && $lang !== null && $sig['commonLang'] !== null && strtolower($lang) === strtolower($sig['commonLang']);
        // A term's @type matches the value's common @type whether the value
        // carries the literal term ("type1") or the resolved IRI.
        $typeMatches = $sig['commonType'] !== null && ($type === $sig['commonType'] || $typeIri === $sig['commonType']);

        // A @list value requires a @container: @list term; among those the most
        // specific (common @type, common @language, plain-string @language:null)
        // wins, else a plain @list term carries a mixed list.
        if ($sig['isList']) {
            if (! ($this->defHasContainer($def, Keyword::List->value))) {
                return $baseline;
            }
            if ($typeMatches) {
                return 6;
            }
            if ($sig['commonType'] === null && $langMatches) {
                return 6;
            }
            if ($sig['allValueObjects'] && $sig['noTypeNoLang'] && $hasLang && $lang === null) {
                return 6;
            }
            if ($type === null && ! $hasLang) {
                return 4; // plain @list term — the mixed-list fallback
            }

            return 0;
        }

        // Node references → prefer @type: @vocab when every @id round-trips to
        // a vocab term/relative, else @type: @id.
        if ($sig['allNodeRefs'] && $type !== null) {
            if ($type === Keyword::Vocab->value) {
                return $this->allIdsRoundTripVocab($value) ? 5 : $baseline;
            }
            if ($type === Keyword::Id->value) {
                return 3;
            }

            return $baseline;
        }

        // Value objects sharing a single @type → prefer the @type-coerced term.
        if ($typeMatches) {
            return 4;
        }

        // Value objects sharing a single @language (and no @type) → prefer the
        // @language-coerced term.
        if ($sig['commonType'] === null && $langMatches) {
            return 4;
        }

        // Plain strings (value objects with neither @type nor @language) →
        // prefer a @language: null term (language reset).
        if ($sig['allValueObjects'] && $sig['noTypeNoLang'] && $hasLang && $lang === null) {
            return 4;
        }

        return $baseline;
    }

    /**
     * Resolve a term-definition `@type` value to the IRI it coerces to: a
     * keyword stays itself; a term resolves through its `@id`; otherwise the
     * value is expanded as a (compact) IRI.
     */
    private function resolveTypeMapping(string $type): string
    {
        if ($this->isKeyword($type)) {
            return $type;
        }
        $def = $this->activeContext->getTermDefinition($type);
        if (is_array($def) && isset($def[Keyword::Id->value]) && is_string($def[Keyword::Id->value])) {
            return $this->expandTermIri($def[Keyword::Id->value]);
        }

        return $this->expandTermIri($type);
    }

    /**
     * @param  array<array-key, mixed>  $def
     */
    private function defHasContainer(array $def, string $keyword): bool
    {
        $container = $def[Keyword::Container->value] ?? null;
        if (is_string($container)) {
            return $container === $keyword;
        }
        if (is_array($container)) {
            return in_array($keyword, $container, true);
        }

        return false;
    }

    /**
     * True when every node reference in $value has an @id that compacts to a
     * vocab term / @vocab-relative form (so a @type: @vocab term round-trips).
     */
    private function allIdsRoundTripVocab(mixed $value): bool
    {
        $items = is_array($value) && array_is_list($value) ? $value : [$value];
        if ($items === []) {
            return false;
        }
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item[Keyword::Id->value]) || ! is_string($item[Keyword::Id->value])) {
                return false;
            }
            $id = $item[Keyword::Id->value];
            $compacted = $this->compactIri($id, vocab: true);
            if ($compacted === $id || str_contains($compacted, '://')) {
                return false;
            }
        }

        return true;
    }

    private function isListContainer(?string $activeProperty): bool
    {
        return $this->hasContainer($activeProperty, Keyword::List->value);
    }

    private function hasContainer(?string $activeProperty, string $keyword): bool
    {
        if ($activeProperty === null) {
            return false;
        }
        $def = $this->activeContext->getTermDefinition($activeProperty);
        if (! is_array($def) || ! isset($def['@container'])) {
            return false;
        }
        $container = $def['@container'];
        if (is_string($container)) {
            return $container === $keyword;
        }
        if (is_array($container)) {
            return in_array($keyword, $container, true);
        }

        return false;
    }

    /**
     * True if compacting $iri in vocab mode yields the same expanded IRI as
     * $other — used to decide whether a value's @type matches the term's
     * coercion. We compare the raw IRIs after resolving the term's @type
     * mapping through the active context.
     */
    private function expandedEquals(string $termType, string $valueType): bool
    {
        if ($termType === $valueType) {
            return true;
        }

        // The term's @type may be a compact IRI ("ex:datatype") or a defined
        // term ("type1"); resolve it to a full IRI before comparing.
        return $this->resolveTypeMapping($termType) === $valueType;
    }

    private function isKeyword(string $value): bool
    {
        return Keyword::contains($value);
    }
}
