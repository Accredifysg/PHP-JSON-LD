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

    public function __construct(private readonly TermDefinitions $activeContext)
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
        foreach ($this->activeContext->termDefinitions as $term => $def) {
            $definition = is_string($def) ? ['@id' => $def] : $def;
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

            if ($this->isKeyword($key)) {
                // Other keywords pass through with a compacted key.
                $result[$this->compactIri($key, vocab: true)] = $value;

                continue;
            }

            // Regular property: compact the key to a term, then compact the
            // value using THAT term as the active property — so the value's
            // container/type coercion resolves against the right definition.
            $compactedKey = $this->compactIri($key, vocab: true);

            // Determine the compacted value: an empty array stays []; a
            // container-map term builds a map; otherwise recurse normally.
            if (is_array($value) && array_is_list($value) && $value === []) {
                $compactedValue = [];
            } else {
                $mapType = $this->mapContainerType($compactedKey);
                $compactedValue = ($mapType !== null && is_array($value) && array_is_list($value))
                    ? $this->compactContainerMap($value, $mapType)
                    : $this->compactElement($value, $compactedKey);
            }

            // §5.6: a property whose term defines @nest is placed under the
            // (aliased) nest term, grouping it with sibling @nest properties.
            $nestTerm = $this->nestTermFor($compactedKey);
            if ($nestTerm !== null) {
                if (! isset($result[$nestTerm]) || ! is_array($result[$nestTerm])) {
                    $result[$nestTerm] = [];
                }
                $result[$nestTerm][$compactedKey] = $compactedValue;
            } else {
                $result[$compactedKey] = $compactedValue;
            }
        }

        return $result;
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
    private function compactIri(string $iri, bool $vocab): string
    {
        // A keyword compacts to a keyword alias when the active context
        // defines one (a term whose @id maps to the keyword, e.g. "id": "@id"
        // or "type": "@type"); otherwise it compacts to itself.
        if ($this->isKeyword($iri)) {
            if ($vocab && isset($this->inverse[$iri])) {
                return $this->selectTerm($this->inverse[$iri]);
            }

            return $iri;
        }

        // Exact term match. Prefer a term whose coercion is least surprising:
        // a plain term wins over one with @type/@container so round-tripping
        // is stable, but any exact match beats a compact IRI.
        if ($vocab && isset($this->inverse[$iri])) {
            return $this->selectTerm($this->inverse[$iri]);
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
     * Pick the best term among candidates mapping to the same IRI. Prefers a
     * term with no `@type`/`@container` coercion (so it round-trips), then
     * the shortest term name for determinism.
     *
     * @param  list<array{term: string, def: array<array-key, mixed>}>  $candidates
     */
    private function selectTerm(array $candidates): string
    {
        usort($candidates, function (array $a, array $b) {
            $aPlain = ! isset($a['def']['@type']) && ! isset($a['def']['@container']);
            $bPlain = ! isset($b['def']['@type']) && ! isset($b['def']['@container']);
            if ($aPlain !== $bPlain) {
                return $aPlain ? -1 : 1;
            }
            $lenCmp = strlen($a['term']) <=> strlen($b['term']);

            return $lenCmp !== 0 ? $lenCmp : strcmp($a['term'], $b['term']);
        });

        return $candidates[0]['term'];
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

        // The term's @type may be a compact IRI ("ex:datatype") or a term;
        // resolve it to a full IRI before comparing.
        return $this->expandTermIri($termType) === $valueType;
    }

    private function isKeyword(string $value): bool
    {
        return Keyword::contains($value);
    }
}
