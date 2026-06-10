<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Context\TermDefinitions;
use Accredify\JsonLd\Enums\Keyword;
use Accredify\JsonLd\Exceptions\JsonLdException;
use Accredify\JsonLd\Internal\IriResolver;

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

    /**
     * @param  bool  $compactArrays  When true (the default, §5.6.2), a
     *                               single-element array is unwrapped to its
     *                               item and `@graph`/`@set` array wrappers are
     *                               dropped where a scalar/object suffices. When
     *                               false, arrays are kept verbatim (#t0070/
     *                               #t0091/#t0093).
     */
    public function __construct(private TermDefinitions $activeContext, private bool $compactArrays = true)
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
            // A single node object is returned bare — unless compactArrays is
            // false, which keeps the top-level array (#t0091/#t0093).
            if ($this->compactArrays && count($result) === 1 && is_array($result[0])) {
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
            // A term with no explicit @id whose NAME is compact-IRI-shaped
            // (e.g. "ex:vocab/date": {"@type": …}) takes its IRI mapping from
            // the expansion of the term itself (§4.2.2), so it must be indexed
            // — its exact inverse match outranks @vocab-stripping (#t0023).
            if (! isset($definition['@id']) && ! isset($definition['@reverse']) && str_contains((string) $term, ':')) {
                $definition['@id'] = (string) $term;
            }
            if (! isset($definition['@id']) || ! is_string($definition['@id'])) {
                continue;
            }
            // A term's @id is frequently a compact IRI (e.g. "ex:term1"); the
            // inverse must key on the FULLY-expanded IRI so that expanded
            // input properties resolve back to the term.
            $expandedIri = $this->expandTermIri($this->resolveTermId($definition['@id'], (string) $term));
            $this->inverse[$expandedIri][] = ['term' => (string) $term, 'def' => $definition];
        }
    }

    /**
     * Resolves a term-definition `@id` that is itself a bare TERM NAME (no
     * colon) before inverse indexing — mirroring IRI expansion of the @id at
     * definition time: a `container` term whose @id is "label" must key on
     * the IRI that the `label` term maps to (#t0027), and an `s` term with
     * the same @id under an active vocabulary must key on the concatenation
     * of @vocab and "label" (#t0089). A self-referential @id (the name is
     * the term itself, or the referenced definition maps back to the same
     * name) falls through to the vocabulary concatenation. Keywords and
     * values containing ':' pass through untouched ({@see expandTermIri}
     * handles compact IRIs).
     */
    private function resolveTermId(string $id, string $term): string
    {
        if ($id === '' || $this->isKeyword($id) || str_contains($id, ':')) {
            return $id;
        }
        if ($id !== $term) {
            $refDef = $this->activeContext->getTermDefinition($id);
            $refId = is_array($refDef) && isset($refDef[Keyword::Id->value]) && is_string($refDef[Keyword::Id->value])
                ? $refDef[Keyword::Id->value]
                : null;
            if ($refId !== null && $refId !== $id) {
                return $refId;
            }
        }
        $vocab = $this->activeContext->getVocab();

        return ($vocab !== null && $vocab !== '') ? $vocab.$id : $id;
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
     * @param  bool  $overrideProtected  True for a property-scoped context,
     *                                   which MAY redefine protected terms
     *                                   (§4.1.2); false for a type-scoped
     *                                   context, which may not (#tpr03).
     */
    private function activateScopedContext(array $scoped, bool $overrideProtected = false): void
    {
        // A scoped @context may be a LIST of layers (e.g. [{...}] in #tc017,
        // [null, {...}] in #tc018). Apply each layer in turn, composing on the
        // prior layer's result; a null/non-map layer is a no-op here (any terms
        // a null would "reset" are reinstated by the non-propagating rollback
        // when descending into a nested node object).
        if (array_is_list($scoped)) {
            foreach ($scoped as $layer) {
                if (is_array($layer)) {
                    $this->activateScopedContext($layer, $overrideProtected);
                }
            }

            return;
        }

        $ctx = clone $this->activeContext;
        $vocab = $ctx->getVocab();

        foreach ($scoped as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            // A scoped @base applies to the cloned active context so that
            // compactIri can relativise document-relative IRIs against it
            // (e.g. #tc015 type-scoped base, #tc024 property-scoped base).
            if ($key === Keyword::Base->value && (is_string($value) || $value === null)) {
                $ctx->setBase($value === null ? null : IriResolver::establishBase($ctx->getBase(), $value));

                continue;
            }
            // A scoped @vocab applies to the cloned active context so the node's
            // OTHER properties compact through it (#tc016). The @type values that
            // triggered this scope are unaffected: their IRIs resolve via an
            // explicit inverse term, which compactIri consults BEFORE @vocab
            // stripping. $vocab is kept in sync so bare-term @id resolution below
            // uses the scoped vocabulary.
            if ($key === Keyword::Vocab->value && (is_string($value) || $value === null)) {
                $ctx->setVocab($value);
                $vocab = $value;

                continue;
            }
            if ($this->isKeyword($key)) {
                continue; // other keyword entries carry no term definition here
            }
            if ($value === null) {
                // Nullifying a protected term is a protected-term redefinition
                // unless overriding is permitted (#tpr03 family).
                if (! $overrideProtected && $ctx->isProtected($key)) {
                    throw new JsonLdException("Protected term redefinition: '{$key}' is protected and cannot be cleared by a scoped context");
                }
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
            // Store protected-aware: a type-scoped context redefining a
            // protected term differently must throw (#tpr03); a property-scoped
            // context passes overrideProtected and is allowed through.
            $ctx->overlayTerm($key, $def, false, $overrideProtected);
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
            // array) or compactArrays is false (which keeps arrays verbatim,
            // #t0070). A @list container DOES still unwrap even when
            // compactArrays is false — the single {@list: …} element it
            // contains must reach the @list branch of compactObject to become
            // its bare array form.
            $isListContainer = $this->hasContainer($activeProperty, Keyword::List->value);
            if (
                count($compactedItems) === 1
                && ! $this->hasContainer($activeProperty, Keyword::Set->value)
                && ($this->compactArrays || $isListContainer)
            ) {
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
        // Value object → value compaction. The result — scalar, rebuilt value
        // object, or {@id} reference — is FINAL: re-running it through the
        // node-object loop would re-compact already-compacted strings (e.g. a
        // kept "xsd:dateTime" @type), which is wrong now that IRI compaction
        // raises "IRI confused with prefix" (#t0006/#t0011 vs #te002).
        if (array_key_exists(Keyword::Value->value, $node) || isset($node[Keyword::Id->value]) && count($node) === 1) {
            return $this->compactValue($node, $activeProperty);
        }

        // @list object.
        if (isset($node[Keyword::List->value]) && is_array($node[Keyword::List->value])) {
            $listContainer = $this->isListContainer($activeProperty);
            // Compact each list item individually so a nested @list (which
            // compacts to an array) stays nested rather than being collapsed by
            // the single-item unwrap in compactElement (#tli01/#tli02/#tli03).
            $asArray = [];
            foreach (array_values($node[Keyword::List->value]) as $listItem) {
                $asArray[] = $this->compactElement($listItem, $activeProperty);
            }
            if ($listContainer) {
                return $asArray;
            }

            // §5.6.3: a @list object that also carries an @index (and whose term
            // is not a @container:@index) keeps the index as a sibling of the
            // (aliased) @list key (#t0042).
            $listResult = [$this->compactIri(Keyword::List->value, vocab: true) => $asArray];
            if (isset($node[Keyword::Index->value]) && is_string($node[Keyword::Index->value])
                && ! $this->hasContainer($activeProperty, Keyword::Index->value)) {
                $listResult[$this->compactIri(Keyword::Index->value, vocab: true)] = $node[Keyword::Index->value];
            }

            return $listResult;
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
                $typeKey = $this->compactIri(Keyword::Type->value, vocab: true);
                $compactedType = $this->compactTypeValue($value);
                // When the @type term carries @container:@set, @type stays an
                // array even for a single value (#t0104/#t0105) — a JSON-LD 1.1
                // feature; JSON-LD 1.0 ignores it and keeps the scalar (#t0106).
                if (! is_array($compactedType) && ! $this->activeContext->isJson10() && $this->hasContainer($typeKey, Keyword::Set->value)) {
                    $compactedType = [$compactedType];
                }
                $result[$typeKey] = $compactedType;

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
                // A NAMED graph (the node also has an @id) keeps @graph as an
                // array (#t0039/#t0016); a SIMPLE graph (only @graph) and
                // @included unwrap a single member to a bare object (#t0090/
                // #t0092) — UNLESS the (aliased) key's term carries
                // @container:@set, which keeps the array (#tin01).
                $compactedKey = $this->compactIri($key, vocab: true);
                $isNamedGraph = $key === Keyword::Graph->value && isset($node[Keyword::Id->value]);
                $keepArray = $isNamedGraph || ! $this->compactArrays || $this->hasContainer($compactedKey, Keyword::Set->value);
                $result[$compactedKey] = (! $keepArray && count($compactedItems) === 1 && is_array($compactedItems[0]))
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
                    if ($reverseTerm !== null) {
                        // A reverse term may itself carry a container (@index —
                        // incl. property-valued — or @graph): route through the
                        // same container-map machinery as a forward property so
                        // the reverse values become an index map rather than a
                        // flat array (#t0036/#t0114).
                        if ($this->hasContainer($reverseTerm, Keyword::Graph->value)) {
                            $compacted = $this->compactGraphContainer($nodeList, $reverseTerm);
                        } else {
                            $mapType = $this->mapContainerType($reverseTerm);
                            $compacted = $mapType !== null
                                ? $this->compactContainerMap($nodeList, $mapType, $reverseTerm)
                                : $this->compactElement($nodeList, $reverseTerm);
                        }
                        $result[$reverseTerm] = $compacted;

                        continue;
                    }

                    // No reverse-coerced term: mirror the forward path's
                    // value-aware PER-ITEM term selection (§5.6.2). One reverse
                    // value may pick a @type:@vocab term (its @id round-trips
                    // through @vocab) while a sibling picks the @type:@id term
                    // (#t0044). Group the items by their per-item term, then
                    // compact each group under its term (containers routed as
                    // for a forward property).
                    $revGroups = [];
                    $revOrder = [];
                    foreach ($nodeList as $item) {
                        $term = $this->compactIri($prop, vocab: true, value: [$item]);
                        if (! array_key_exists($term, $revGroups)) {
                            $revGroups[$term] = [];
                            $revOrder[] = $term;
                        }
                        $revGroups[$term][] = $item;
                    }
                    foreach ($revOrder as $term) {
                        $groupItems = $revGroups[$term];
                        if ($this->hasContainer($term, Keyword::Graph->value)) {
                            $compacted = $this->compactGraphContainer($groupItems, $term);
                        } else {
                            $mapType = $this->mapContainerType($term);
                            $compacted = $mapType !== null
                                ? $this->compactContainerMap($groupItems, $mapType, $term)
                                : $this->compactElement($groupItems, $term);
                        }
                        $reverseMap[$term] = $compacted;
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
                $term = $this->compactIri($key, vocab: true, value: [$item], multiValued: count($items) > 1);
                if (! array_key_exists($term, $groups)) {
                    $groups[$term] = [];
                    $order[] = $term;
                }
                $groups[$term][] = $item;
            }

            foreach ($order as $term) {
                $groupItems = $groups[$term];

                // Snapshot the active context around EVERY property's value
                // compaction: a property-scoped @context activated here, or a
                // type-scoped rollback consumed inside a nested-node value, must
                // not leak into sibling properties — sibling compaction must be
                // order-independent (#tc015/#tc019).
                $savedContext = $this->activeContext;
                $savedInverse = $this->inverse;
                $savedReverse = $this->reverseInverse;
                $savedPrevious = $this->previousContext;

                // §5.6: a property whose term carries a property-scoped @context
                // activates it while its value is compacted. It PROPAGATES into
                // the value's nested nodes (unlike type-scoped): clear the
                // type-scoped rollback so nested nodes keep these terms (#tc013/
                // #tc019); an explicit @propagate:false confines it instead,
                // rolling nested nodes back to the pre-activation context (#tc027).
                $scoped = $this->propertyScopedContext($term);
                if ($scoped !== null) {
                    $preScoped = ['context' => $this->activeContext, 'inverse' => $this->inverse, 'reverse' => $this->reverseInverse];
                    $this->activateScopedContext($scoped, overrideProtected: true);
                    $this->previousContext = $this->propagatesFalse($scoped) ? $preScoped : null;
                }

                $jsonLiteral = $this->verbatimJsonLiteral($groupItems, $term);
                if ($jsonLiteral !== null) {
                    $compactedValue = $jsonLiteral['value'];
                } elseif ($this->hasContainer($term, Keyword::Graph->value)) {
                    $compactedValue = $this->compactGraphContainer($groupItems, $term);
                } else {
                    $mapType = $this->mapContainerType($term);
                    $compactedValue = $mapType !== null
                        ? $this->compactContainerMap($groupItems, $mapType, $term)
                        : $this->compactElement($groupItems, $term);
                }

                // Restore for the next sibling property.
                $this->activeContext = $savedContext;
                $this->inverse = $savedInverse;
                $this->reverseInverse = $savedReverse;
                $this->previousContext = $savedPrevious;

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

        // The term's effective @language: its own @language coercion (an
        // explicit null opts out) or, absent one, the active default @language.
        $termHasLang = is_array($termDef) && array_key_exists(Keyword::Language->value, $termDef);
        $termLang = $termHasLang && is_string($termDef[Keyword::Language->value]) ? $termDef[Keyword::Language->value] : null;
        $effectiveLang = $termHasLang ? $termLang : $this->activeContext->getDefaultLanguage();

        // The term's effective @direction mirrors @language: its own
        // @direction coercion (an explicit null opts out) or, absent one, the
        // active default @direction.
        $termHasDir = is_array($termDef) && array_key_exists(Keyword::Direction->value, $termDef);
        $termDir = $termHasDir && is_string($termDef[Keyword::Direction->value]) ? $termDef[Keyword::Direction->value] : null;
        $effectiveDir = $termHasDir ? $termDir : $this->activeContext->getDefaultDirection();

        // If the term coerces to the value's @type, drop @type.
        if (! $typeNone && $valueType !== null && $typeMapping !== null && $this->expandedEquals($typeMapping, $valueType) && ! $hasOther && $valueLang === null && $valueDir === null) {
            return $raw;
        }

        // Plain @value with no @type / @language / @direction → the scalar —
        // UNLESS it is a string and an effective @language or @direction is
        // active, where collapsing would wrongly imply that language /
        // direction on round-trip, so the value object is kept (#t0072).
        if (! $typeNone && $valueType === null && $valueLang === null && $valueDir === null && ! $hasOther) {
            if (! is_string($raw) || ($effectiveLang === null && $effectiveDir === null)) {
                return $raw;
            }
        }

        // @language collapse (§5.6.3): a language-tagged value whose language
        // matches the term's @language coercion — or, absent one, the active
        // default @language — drops @language and becomes the bare scalar.
        // The value's @direction (possibly absent) must equally match the
        // term's effective @direction, or direction would be mangled on
        // round-trip.
        if (
            ! $typeNone
            && $valueType === null
            && ! $hasOther
            && $valueLang !== null
            && $effectiveLang !== null
            && $valueDir === $effectiveDir
            && strtolower($valueLang) === strtolower($effectiveLang)
        ) {
            return $raw;
        }

        // @direction collapse (#tdi03): a direction-tagged value with no
        // language whose direction matches the term's effective @direction —
        // while no effective @language would be implied — likewise becomes
        // the bare scalar.
        if (
            ! $typeNone
            && $valueType === null
            && ! $hasOther
            && $valueLang === null
            && $effectiveLang === null
            && $valueDir !== null
            && $valueDir === $effectiveDir
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
        // Preserve @index on a value object whose property is NOT an @index
        // container (an index container consumes @index as the map key before
        // this point); dropping it would lose the index on round-trip (#t0030).
        if (isset($value[Keyword::Index->value]) && is_string($value[Keyword::Index->value])) {
            $out[$this->compactIri(Keyword::Index->value, vocab: true)] = $value[Keyword::Index->value];
        }

        return $out;
    }

    /**
     * Detects a property value that is a single JSON literal (a value object
     * of `@type: @json`) whose selected term also coerces `@type: @json`.
     * Value compaction yields the raw `@value` VERBATIM — including a raw
     * ARRAY, which must reach the output as the property value itself rather
     * than being iterated as list items (and double-wrapped under a `@set`
     * container, #tjs07). Returns the delivered value wrapped in
     * `['value' => …]` (the raw `@value` may itself be null, #tjs11), or null
     * when the bypass does not apply.
     *
     * @param  list<mixed>  $groupItems
     * @return array{value: mixed}|null
     */
    private function verbatimJsonLiteral(array $groupItems, string $term): ?array
    {
        if (count($groupItems) !== 1 || ! is_array($groupItems[0])) {
            return null;
        }
        $item = $groupItems[0];
        if (
            count($item) !== 2
            || ! array_key_exists(Keyword::Value->value, $item)
            || ($item[Keyword::Type->value] ?? null) !== Keyword::Json->value
        ) {
            return null;
        }
        // Only when the term genuinely coerces @type:@json (so value compaction
        // collapses to the raw @value); a term-less / un-coerced property keeps
        // its rebuilt {@value, @type} object form (#tjs08/#tjs09). Container
        // maps and @graph containers keep their own delivery.
        $def = $this->activeContext->getTermDefinition($term);
        if (! is_array($def) || ($def[Keyword::Type->value] ?? null) !== Keyword::Json->value) {
            return null;
        }
        if ($this->mapContainerType($term) !== null || $this->hasContainer($term, Keyword::Graph->value)) {
            return null;
        }

        $raw = $item[Keyword::Value->value];
        // @container:@set (or compactArrays: false) keeps the property as an
        // array: a raw ARRAY already is one and is delivered as-is (#tjs07);
        // any other raw value is wrapped once.
        if ($this->hasContainer($term, Keyword::Set->value) || ! $this->compactArrays) {
            return ['value' => is_array($raw) && array_is_list($raw) ? $raw : [$raw]];
        }

        return ['value' => $raw];
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

            // §5.6 (12.8.7): only a SIMPLE graph object (no @id) may enter a
            // [@graph, @index] map; a graph object WITH @id keeps its full
            // (aliased) {@id, @index, @graph} form instead (#t0083).
            if ($hasIndex && ! $hasId && isset($item[Keyword::Id->value]) && is_string($item[Keyword::Id->value])) {
                $full = [
                    $this->compactIri(Keyword::Id->value, vocab: true) => $this->compactIri($item[Keyword::Id->value], vocab: false),
                ];
                if (isset($item[Keyword::Index->value]) && is_string($item[Keyword::Index->value])) {
                    $full[$this->compactIri(Keyword::Index->value, vocab: true)] = $item[Keyword::Index->value];
                }
                $full[$this->compactIri(Keyword::Graph->value, vocab: true)] = $content;
                $list[] = $full;

                continue;
            }

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
            // Graph objects that fell back to the full form bypass the map;
            // when the map is otherwise empty, return them like a plain @graph
            // container value (#t0083).
            if ($map === [] && $list !== []) {
                return (! $asSet && $this->compactArrays && count($list) === 1) ? $list[0] : $list;
            }

            return $map;
        }

        return (! $asSet && $this->compactArrays && count($list) === 1) ? $list[0] : $list;
    }

    /**
     * Compacts an expanded array of value/node objects into a container map
     * keyed per $mapType. Collisions on the same key arrayify (the second
     * value turns a scalar entry into a list).
     *
     * @param  list<mixed>  $items
     * @return array<string, mixed>
     */
    private function compactContainerMap(array $items, string $mapType, ?string $activeProperty = null): array
    {
        // §5.6 step 12.8.9: when the container also includes @set (a JSON-LD
        // 1.1 combination) — or compactArrays is false — every map entry stays
        // an array even when single-valued (#ts002).
        $asArray = ! $this->compactArrays
            || (! $this->activeContext->isJson10() && $this->hasContainer($activeProperty, Keyword::Set->value));
        $map = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            [$key, $entry] = $this->mapKeyAndEntry($item, $mapType, $activeProperty);
            if ($key === null) {
                continue;
            }

            if (array_key_exists($key, $map)) {
                if (! is_array($map[$key]) || ! array_is_list($map[$key])) {
                    $map[$key] = [$map[$key]];
                }
                $map[$key][] = $entry;
            } else {
                $map[$key] = $asArray ? [$entry] : $entry;
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
    private function mapKeyAndEntry(array $item, string $mapType, ?string $activeProperty = null): array
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
                // Property-valued index (§5.6): when the term sets @index to a
                // property IRI (not the @index keyword), the map key is that
                // property's VALUE on the node, which is then removed (#tpi01-05).
                $def = $activeProperty !== null ? $this->activeContext->getTermDefinition($activeProperty) : null;
                $indexTerm = is_array($def)
                    && isset($def[Keyword::Index->value])
                    && is_string($def[Keyword::Index->value])
                    && $def[Keyword::Index->value] !== Keyword::Index->value
                    ? $def[Keyword::Index->value]
                    : null;
                $indexProp = null;
                if ($indexTerm !== null) {
                    // Resolve the index property through the term definition: a
                    // defined term (e.g. predicate -> rdf:predicate) wins over a
                    // bare @vocab concatenation (#t0114); resolveTypeMapping also
                    // handles the @vocab-relative and compact-IRI cases.
                    $indexProp = $this->resolveTypeMapping($indexTerm);
                }
                if ($indexProp !== null) {
                    // Compact the node first, then take the key from the COMPACTED
                    // index-property value: a string (incl. a @type:@id-coerced
                    // node ref) becomes the key and is removed; a non-string
                    // (e.g. a {@id} object) leaves the key @none and the property
                    // intact (#tpi01/#tpi02/#tpi03/#tpi06).
                    $entry = $this->compactObject($item, null);
                    $propKey = $this->compactIri($indexProp, vocab: true);
                    $key = $none;
                    $hadIndexProp = is_array($entry) && array_key_exists($propKey, $entry);
                    if ($hadIndexProp) {
                        $propVal = $entry[$propKey];
                        if (is_string($propVal)) {
                            $key = $propVal;
                            unset($entry[$propKey]);
                        } elseif (is_array($propVal) && array_is_list($propVal) && isset($propVal[0]) && is_string($propVal[0])) {
                            $key = $propVal[0];
                            $rest = array_slice($propVal, 1);
                            $entry[$propKey] = count($rest) === 1 ? $rest[0] : $rest;
                            if ($rest === []) {
                                unset($entry[$propKey]);
                            }
                        }
                    }

                    // A node that never HAD the index property (so the key stays
                    // @none) and is a sole-@id reference collapses to the bare
                    // IRI string when the container term coerces @type:@id /
                    // @type:@vocab (#tpi05) — recomputed from the EXPANDED @id,
                    // not the entry's compacted {@id} form. Entries whose index
                    // property was extracted above keep their object form
                    // (#tpi01-#tpi04), as do nodes carrying a non-string index
                    // property (#tpi06).
                    $defType = isset($def[Keyword::Type->value]) && is_string($def[Keyword::Type->value])
                        ? $def[Keyword::Type->value]
                        : null;
                    if (
                        ! $hadIndexProp
                        && ($defType === Keyword::Id->value || $defType === Keyword::Vocab->value)
                        && count($item) === 1
                        && isset($item[Keyword::Id->value])
                        && is_string($item[Keyword::Id->value])
                    ) {
                        $entry = $this->compactIri($item[Keyword::Id->value], vocab: $defType === Keyword::Vocab->value);
                    }

                    return [$key, $entry];
                }
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
                    return [$none, $this->typeMapEntry($item, $activeProperty)];
                }
                $typeKey = $this->compactIri($types[0], vocab: true);
                $rest = array_slice($types, 1);
                $stripped = $item;
                if ($rest === []) {
                    unset($stripped[Keyword::Type->value]);
                } else {
                    $stripped[Keyword::Type->value] = $rest;
                }

                // §5.6: the type (map key) term may carry a type-scoped @context
                // that must be active while the entry is compacted, so the
                // entry's properties compact through the scoped term defs
                // (#tm007). Save/restore the active context + inverse around it.
                $typeDef = $this->activeContext->getTermDefinition($typeKey);
                $scoped = is_array($typeDef) && isset($typeDef[Keyword::Context->value]) && is_array($typeDef[Keyword::Context->value])
                    ? $typeDef[Keyword::Context->value]
                    : null;
                if ($scoped === null) {
                    return [$typeKey, $this->typeMapEntry($stripped, $activeProperty)];
                }
                $savedCtx = $this->activeContext;
                $savedInverse = $this->inverse;
                $savedReverse = $this->reverseInverse;
                $this->activateScopedContext($scoped);
                $entry = $this->typeMapEntry($stripped, $activeProperty);
                $this->activeContext = $savedCtx;
                $this->inverse = $savedInverse;
                $this->reverseInverse = $savedReverse;

                return [$typeKey, $entry];
        }

        return [null, null];
    }

    /**
     * Compacts one @type-container map entry. A node left with only @id
     * compacts to the bare, compacted @id STRING (#tm020-023); the @id is
     * vocab-compacted when the container term coerces to `@type: @vocab`,
     * otherwise document-relative. Anything else compacts as a node object.
     *
     * @param  array<array-key, mixed>  $node
     */
    private function typeMapEntry(array $node, ?string $activeProperty): mixed
    {
        if (count($node) === 1 && isset($node[Keyword::Id->value]) && is_string($node[Keyword::Id->value])) {
            $def = $activeProperty !== null ? $this->activeContext->getTermDefinition($activeProperty) : null;
            $vocab = is_array($def) && ($def[Keyword::Type->value] ?? null) === Keyword::Vocab->value;

            return $this->compactIri($node[Keyword::Id->value], vocab: $vocab);
        }

        return $this->compactObject($node, null);
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
    private function compactIri(string $iri, bool $vocab, mixed $value = null, bool $multiValued = false): string
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
            $term = $this->selectTerm($this->inverse[$iri], $value, $multiValued);
            if ($term !== '') {
                return $term;
            }
            // selectTerm declined: the only matching term would destroy the
            // value (e.g. a @type:@id term for a plain string) — fall through
            // to a compact IRI / @vocab / full IRI (#t0006).
        }

        // @vocab stripping for vocab-mode IRIs — checked BEFORE forming a
        // compact IRI: §5.7 prefers the @vocab-relative form over a (possibly
        // shorter) prefix:suffix (#t0023 "prefer @vocab over compacted IRIs").
        // The stripped name is rejected when it is itself a defined term
        // mapping to a DIFFERENT IRI — using it would not round-trip (#t0043/
        // #tc011); fall through to a compact IRI / the full IRI.
        if ($vocab) {
            $vocabIri = $this->activeContext->getVocab();
            if ($vocabIri !== null && str_starts_with($iri, $vocabIri) && $iri !== $vocabIri) {
                $stripped = substr($iri, strlen($vocabIri));
                $strippedDef = $this->activeContext->getTermDefinition($stripped);
                $collides = is_array($strippedDef)
                    && isset($strippedDef[Keyword::Id->value])
                    && is_string($strippedDef[Keyword::Id->value])
                    && $this->expandTermIri($strippedDef[Keyword::Id->value]) !== $iri;
                if (! $collides) {
                    return $stripped;
                }
            }
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
            // §5.7: only a prefix-flagged term may form a compact IRI. The flag
            // is true for a simple term def ending in a gen-delim, or for an
            // explicit @prefix:true; an expanded def without @prefix (#tp001/
            // #tp002) or an explicit @prefix:false (#tp008) is not usable.
            $prefixDef = $this->activeContext->getTermDefinition($prefixTerm);
            if (! is_array($prefixDef)) {
                continue;
            }
            if (($prefixDef[Keyword::Prefix->value] ?? false) !== true) {
                // JSON-LD 1.0 predates the prefix flag. There, a term defined
                // with EXPANDED syntax still forms compact IRIs when the
                // expanded form was REQUIRED to express a coercion
                // (@container/@type/…) — 1.0 had no other way to write it, so
                // it carries no anti-prefix signal (#t0038 "title:/value"). An
                // expanded definition carrying nothing beyond @id deliberately
                // avoided the simple string form, which (per the 1.1 suite's
                // 1.0-mode expectation) opts the term out of prefix use (#tp001).
                $legacyPrefix = $this->activeContext->isJson10()
                    && array_diff(array_keys($prefixDef), [Keyword::Id->value, Keyword::Prefix->value, Keyword::Protected->value]) !== [];
                if (! $legacyPrefix) {
                    continue;
                }
            }
            $candidate = $prefixTerm.':'.substr($iri, strlen($termIri));
            // §5.7 step 9.4: a candidate that is itself a defined term is only
            // usable when no value is being compacted AND its IRI mapping
            // equals $iri — otherwise it would not round-trip (#t0007).
            $candidateDef = $this->activeContext->getTermDefinition($candidate);
            if ($candidateDef !== null) {
                // The candidate term's IRI mapping: its @id when present, else
                // (compact-IRI-shaped term) its own expansion; an explicit
                // null @id (a nullified term) never matches.
                if (array_key_exists(Keyword::Id->value, $candidateDef)) {
                    $candidateId = is_string($candidateDef[Keyword::Id->value])
                        ? $this->expandTermIri($candidateDef[Keyword::Id->value])
                        : null;
                } else {
                    $candidateId = $this->expandTermIri($candidate);
                }
                if ($value !== null || $candidateId !== $iri) {
                    continue;
                }
            }
            $len = strlen($termIri);
            if ($len > $bestLen) {
                $bestLen = $len;
                $best = $candidate;
            }
        }
        if ($best !== null) {
            return $best;
        }

        // §5.7: an IRI that LOOKS like a compact IRI on a declared prefix
        // term ("tag:champin.net,2019:prop" with prefix term "tag") must not be
        // returned as-is — round-tripping would wrongly expand it through the
        // prefix. This is the "IRI confused with prefix" error (#te002).
        $colon = strpos($iri, ':');
        if ($colon !== false && $colon > 0) {
            $scheme = substr($iri, 0, $colon);
            $schemeDef = $this->activeContext->getTermDefinition($scheme);
            if (is_array($schemeDef) && ($schemeDef[Keyword::Prefix->value] ?? false) === true) {
                throw new JsonLdException("IRI confused with prefix: '{$iri}' begins with declared prefix '{$scheme}'");
            }
        }

        // Document-relative compaction against @base for non-vocab IRIs: a full
        // relative reference (RFC 3986), e.g. "../parent", "#frag" (#t0045/
        // #t0066/#t0111). relativize() returns the IRI unchanged when it cannot
        // be relativised (no base, or a differing scheme/authority).
        if (! $vocab) {
            $base = $this->activeContext->getBase();
            if ($base !== null && $base !== '') {
                return IriResolver::relativize($base, $iri);
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
    private function selectTerm(array $candidates, mixed $value = null, bool $multiValued = false): string
    {
        $sig = $value !== null ? $this->valueSignature($value) : null;

        usort($candidates, function (array $a, array $b) use ($sig, $value, $multiValued) {
            if ($sig !== null) {
                $sa = $this->scoreCandidate($a['def'], $sig, $value, $multiValued);
                $sb = $this->scoreCandidate($b['def'], $sig, $value, $multiValued);
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

        // If even the best candidate's @type or @language coercion would
        // destroy the value, decline (return '') so the caller falls through
        // to a compact IRI / @vocab / full IRI rather than emit under a lossy
        // term (#t0006/#t0017).
        if ($sig !== null && ! $sig['empty']
            && ($this->isDestructiveTypeCoercion($candidates[0]['def'], $sig) || $this->isDestructiveLanguageCoercion($candidates[0]['def'], $sig))) {
            return '';
        }

        // A {@list, @index} value must not select a @container:@list term —
        // the container form would drop the @index. Decline so the property
        // falls back to its full-IRI key with an explicit {@list, @index}
        // object (#t0041).
        if ($sig !== null && $sig['isList'] && $sig['listHasIndex'] && $this->defHasContainer($candidates[0]['def'], Keyword::List->value)) {
            return '';
        }

        // Likewise decline when the best candidate's @language map cannot
        // hold the value (mismatched @direction / stray @index): the property
        // falls back to a compact IRI / full IRI with expanded value objects
        // (#tdi07/#t0065).
        if ($sig !== null && ! $sig['empty'] && ! $sig['isList'] && $this->languageMapIneligible($candidates[0]['def'], $value)) {
            return '';
        }

        return $candidates[0]['term'];
    }

    /**
     * True when selecting $def (the best candidate) would DESTROY the value:
     * its `@type` coercion cannot represent the value, so emitting under this
     * term would drop or mangle data. The carve-outs are load-bearing:
     * `@type: @none`, `@container` terms, and `@list` values are never
     * destructive, and an `@id`/`@vocab` term is fine when every value is a
     * node reference.
     *
     * @param  array<array-key, mixed>  $def
     * @param  array{allNodeRefs: bool, allValueObjects: bool, commonType: ?string, commonLang: ?string, commonDir: ?string, noTypeNoLang: bool, empty: bool, isList: bool, listHasIndex: bool}  $sig
     */
    private function isDestructiveTypeCoercion(array $def, array $sig): bool
    {
        $type = isset($def[Keyword::Type->value]) && is_string($def[Keyword::Type->value])
            ? $def[Keyword::Type->value]
            : null;

        if ($type === null || $type === Keyword::None->value || $sig['isList'] || isset($def[Keyword::Container->value])) {
            return false;
        }
        if ($type === Keyword::Id->value || $type === Keyword::Vocab->value) {
            // An @id/@vocab coercion only destroys VALUE objects / scalars
            // (value compaction would emit a bare string that re-expands as a
            // node reference). Full node objects — not just bare references —
            // survive intact, and the spec selects the term for them
            // (type/language value @id) (#t0007).
            return $sig['allValueObjects'];
        }
        if (! $sig['allValueObjects']) {
            return true;
        }
        $typeIri = $this->resolveTypeMapping($type);

        return ! ($sig['commonType'] !== null && ($type === $sig['commonType'] || $typeIri === $sig['commonType']));
    }

    /**
     * True when selecting $def would coerce the value into the WRONG language:
     * the term carries an explicit `@language: "L"` while the value's common
     * language is a non-null, non-matching tag — the value must decline to the
     * full-IRI key instead (#t0017: the German value may not sit under the
     * English-coerced comment_en). Restricted to plain (container-less) terms
     * and language-TAGGED values: a value with NO language (commonLang null)
     * and a term with `@language: null` never decline here.
     *
     * @param  array<array-key, mixed>  $def
     * @param  array{allNodeRefs: bool, allValueObjects: bool, commonType: ?string, commonLang: ?string, commonDir: ?string, noTypeNoLang: bool, empty: bool, isList: bool, listHasIndex: bool}  $sig
     */
    private function isDestructiveLanguageCoercion(array $def, array $sig): bool
    {
        $lang = isset($def[Keyword::Language->value]) && is_string($def[Keyword::Language->value])
            ? $def[Keyword::Language->value]
            : null;
        if ($lang === null || $sig['isList'] || isset($def[Keyword::Container->value])) {
            return false;
        }

        return $sig['commonLang'] !== null && strtolower($sig['commonLang']) !== strtolower($lang);
    }

    /**
     * Summarise an expanded property value (a list of value/node objects, or a
     * single `@list` object) for value-aware term selection. A lone `@list` is
     * summarised by its CONTENTS with `isList` set, so a `@container: @list`
     * term is required.
     *
     * @return array{allNodeRefs: bool, allValueObjects: bool, commonType: ?string, commonLang: ?string, commonDir: ?string, noTypeNoLang: bool, empty: bool, isList: bool, listHasIndex: bool}
     */
    private function valueSignature(mixed $value): array
    {
        $items = is_array($value) && array_is_list($value) ? $value : [$value];

        // A single @list object → select against the list's contents.
        if (count($items) === 1 && is_array($items[0]) && array_key_exists(Keyword::List->value, $items[0]) && is_array($items[0][Keyword::List->value])) {
            $sig = $this->valueSignature(array_values($items[0][Keyword::List->value]));
            $sig['isList'] = true;
            // A @list object carrying an @index cannot live under a
            // @container:@list term — the container form has nowhere to keep
            // the index (#t0041).
            $sig['listHasIndex'] = isset($items[0][Keyword::Index->value]) && is_string($items[0][Keyword::Index->value]);

            return $sig;
        }

        $sig = ['allNodeRefs' => true, 'allValueObjects' => true, 'commonType' => null, 'commonLang' => null, 'commonDir' => null, 'noTypeNoLang' => true, 'empty' => $items === [], 'isList' => false, 'listHasIndex' => false];

        $types = [];
        $langs = [];
        $dirs = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                // A bare scalar is a value with neither @type nor @language.
                $sig['allNodeRefs'] = false;
                $types["\0"] = null;
                $langs["\0"] = null;
                $dirs["\0"] = null;

                continue;
            }
            if (! (isset($item[Keyword::Id->value]) && count($item) === 1)) {
                $sig['allNodeRefs'] = false;
            }
            if (array_key_exists(Keyword::Value->value, $item)) {
                $t = isset($item[Keyword::Type->value]) && is_string($item[Keyword::Type->value]) ? $item[Keyword::Type->value] : null;
                $l = isset($item[Keyword::Language->value]) && is_string($item[Keyword::Language->value]) ? $item[Keyword::Language->value] : null;
                $d = isset($item[Keyword::Direction->value]) && is_string($item[Keyword::Direction->value]) ? $item[Keyword::Direction->value] : null;
                $types[$t ?? "\0"] = $t;
                $langs[$l ?? "\0"] = $l;
                $dirs[$d ?? "\0"] = $d;
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
        if ($sig['allValueObjects'] && count($dirs) === 1) {
            $sig['commonDir'] = reset($dirs);
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
     * @param  array{allNodeRefs: bool, allValueObjects: bool, commonType: ?string, commonLang: ?string, commonDir: ?string, noTypeNoLang: bool, empty: bool, isList: bool, listHasIndex: bool}  $sig
     */
    private function scoreCandidate(array $def, array $sig, mixed $value, bool $multiValued = false): int
    {
        $type = isset($def[Keyword::Type->value]) && is_string($def[Keyword::Type->value]) ? $def[Keyword::Type->value] : null;
        $typeIri = $type !== null ? $this->resolveTypeMapping($type) : null;
        $hasLang = array_key_exists(Keyword::Language->value, $def);
        $lang = $hasLang && is_string($def[Keyword::Language->value]) ? $def[Keyword::Language->value] : null;
        $hasDir = array_key_exists(Keyword::Direction->value, $def);
        $dir = $hasDir && is_string($def[Keyword::Direction->value]) ? $def[Keyword::Direction->value] : null;
        $coerced = $type !== null || $hasLang || isset($def[Keyword::Container->value]);
        $baseline = $coerced ? 0 : 1;

        if ($sig['empty']) {
            return $baseline;
        }

        $langMatches = $hasLang && $lang !== null && $sig['commonLang'] !== null && strtolower($lang) === strtolower($sig['commonLang']);
        $dirMatches = $dir !== null && $sig['commonDir'] !== null && $dir === $sig['commonDir'];
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
            // A @container:@list term cannot carry the list's @index (#t0041):
            // score it below a plain term so the value falls back to an
            // explicit {@list, @index} object.
            if ($sig['listHasIndex']) {
                return 0;
            }
            // A term coercing a @direction the list's items don't all share
            // would stamp that direction onto them on round-trip (#tdi03).
            if ($dir !== null && $sig['commonDir'] !== $dir) {
                return 0;
            }
            if ($typeMatches) {
                return 6;
            }
            if ($sig['commonType'] === null && $langMatches) {
                return 6;
            }
            if ($sig['commonType'] === null && $sig['commonLang'] === null && $dirMatches) {
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

        // A non-list value never matches a @container: @list term — the list
        // wrapper would be fabricated on round-trip (#t0018).
        if ($this->defHasContainer($def, Keyword::List->value)) {
            return 0;
        }

        // A value that cannot live in the term's @language map (mismatched
        // @direction, or an @index the map would drop) must not select it
        // (#tdi07/#t0065).
        if ($this->languageMapIneligible($def, $value)) {
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

        // INVARIANT: a pure-container term (@container @set / @language with
        // no @type and no @language mapping) can never reach the typeMatches /
        // langMatches branches below — it must be scored here or it stays at
        // the coerced baseline of 0 and loses to any plain term.

        // Language-tagged value objects → a @language-CONTAINER term holds
        // every language (the map is keyed by it), so it outranks even an
        // exact @language-coerced term — §5.7's container preference checks
        // @language before @none (#t0089).
        if ($sig['commonLang'] !== null && $type === null && ! $hasLang && $this->defHasContainer($def, Keyword::Language->value)) {
            return 5;
        }

        // A MULTI-valued property of value objects → prefer a @container:@set
        // term over a plain term (§5.7: @set precedes @none in the container
        // preference order); an exact @type/@language match still wins (#t0027).
        if ($multiValued && $sig['allValueObjects'] && $type === null && ! $hasLang && $this->defHasContainer($def, Keyword::Set->value)) {
            return 2;
        }

        // A term whose @language: L mismatches the value's language would emit
        // the WRONG language on round-trip — rank it below every other
        // candidate (#t0017: the German value must not pick comment_en).
        if ($hasLang && $lang !== null && $sig['commonLang'] !== null && ! $langMatches) {
            return -1;
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
        // A @vocab-relative @type (no ':' and not a defined term) coerces to
        // @vocab + value — mirroring vocab-mode IRI expansion — so the term's
        // coercion can be matched against a value's full @type IRI (#t0021).
        if (! str_contains($type, ':')) {
            $vocab = $this->activeContext->getVocab();
            if ($vocab !== null && $vocab !== '') {
                return $vocab.$type;
            }
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
     * True when $value holds an item that cannot live in the term's
     * `@container: @language` map without loss: an entry's `@index` has no
     * home in a language map (#t0065), and a language-map string takes the
     * term's effective `@direction` on re-expansion, so an item whose own
     * direction differs — or is absent while the term coerces one — must stay
     * out (#tdi07). A term without an @language container is never ineligible.
     *
     * @param  array<array-key, mixed>  $def
     */
    private function languageMapIneligible(array $def, mixed $value): bool
    {
        if (! $this->defHasContainer($def, Keyword::Language->value)) {
            return false;
        }

        // The term's effective @direction: its own @direction coercion (an
        // explicit null opts out) or, absent one, the active default.
        $hasDir = array_key_exists(Keyword::Direction->value, $def);
        $dir = $hasDir && is_string($def[Keyword::Direction->value]) ? $def[Keyword::Direction->value] : null;
        $effectiveDir = $hasDir ? $dir : $this->activeContext->getDefaultDirection();

        $items = is_array($value) && array_is_list($value) ? $value : [$value];
        foreach ($items as $item) {
            if (! is_array($item) || ! array_key_exists(Keyword::Value->value, $item)) {
                continue;
            }
            if (isset($item[Keyword::Index->value])) {
                return true;
            }
            $itemDir = isset($item[Keyword::Direction->value]) && is_string($item[Keyword::Direction->value]) ? $item[Keyword::Direction->value] : null;
            if ($itemDir !== $effectiveDir) {
                return true;
            }
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
