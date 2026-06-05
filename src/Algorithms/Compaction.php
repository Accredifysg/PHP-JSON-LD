<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Context\TermDefinitions;
use Accredify\JsonLd\Enums\Keyword;

/**
 * JSON-LD 1.1 Compaction Algorithm (§5.6) — first-pass implementation.
 *
 * Compacts an *expanded* JSON-LD document against an active context,
 * applying term definitions, compact IRIs, `@vocab`, and container
 * coercions to produce the most concise applicable form.
 *
 * Implemented:
 *  - IRI compaction (§5.7): exact term match (preferring type/@id-coercion
 *    matches), compact-IRI (`prefix:suffix`), `@vocab` stripping.
 *  - Value compaction (§5.9): dropping coerced `@type`, `@language`
 *    defaults, `@type: @id` node references, `@value`-only scalars.
 *  - `@list` / `@set` container coercion; array-vs-single normalisation.
 *  - `@id` / `@type` keyword compaction.
 *
 * Deferred:
 *  - `@language` / `@index` / `@id` / `@type` / `@graph` container maps
 *    (the inverse-container map forms).
 *  - `@reverse`, `@nested`, property-scoped + type-scoped contexts during
 *    compaction.
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

        // The top level is always a node object (or empty object) per the
        // common compaction contract — unwrap a single-element list.
        if (is_array($result) && array_is_list($result)) {
            if (count($result) === 1 && is_array($result[0])) {
                return $result[0];
            }
            if ($result === []) {
                return [];
            }
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

            if ($this->isKeyword($key)) {
                // Other keywords pass through with a compacted key.
                $result[$this->compactIri($key, vocab: true)] = $value;

                continue;
            }

            // Regular property: compact the key to a term, then compact the
            // value using THAT term as the active property — so the value's
            // container/type coercion resolves against the right definition.
            $compactedKey = $this->compactIri($key, vocab: true);

            // An empty array value must still produce the property (with []).
            if (is_array($value) && array_is_list($value) && $value === []) {
                $result[$compactedKey] = [];

                continue;
            }

            $result[$compactedKey] = $this->compactElement($value, $compactedKey);
        }

        return $result;
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
        $hasOther = (bool) array_diff(array_keys($value), [Keyword::Value->value, Keyword::Type->value, Keyword::Language->value]);

        // If the term coerces to the value's @type, drop @type.
        if ($valueType !== null && $typeMapping !== null && $this->expandedEquals($typeMapping, $valueType) && ! $hasOther && $valueLang === null) {
            return $raw;
        }

        // Plain @value with no @type / @language → the scalar itself.
        if ($valueType === null && $valueLang === null && ! $hasOther) {
            return $raw;
        }

        // Otherwise rebuild the value object with compacted keys + @type.
        $out = [$this->compactIri(Keyword::Value->value, vocab: true) => $raw];
        if ($valueType !== null) {
            $out[$this->compactIri(Keyword::Type->value, vocab: true)] = $this->compactIri($valueType, vocab: true);
        }
        if ($valueLang !== null) {
            $out[$this->compactIri(Keyword::Language->value, vocab: true)] = $valueLang;
        }

        return $out;
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
        // Keywords compact to themselves (alias support is a later refinement).
        if ($this->isKeyword($iri)) {
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
