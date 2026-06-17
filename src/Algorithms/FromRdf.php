<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Enums\Keyword;
use Accredify\JsonLd\Exceptions\JsonLdException;
use Accredify\JsonLd\Rdf\RdfQuad;
use Accredify\JsonLd\Rdf\RdfTerm;
use JsonException;

/**
 * Serialize RDF as JSON-LD — the `fromRdf` algorithm
 * ({@link https://www.w3.org/TR/json-ld11-api/#serialize-rdf-as-json-ld-algorithm §4.9}),
 * with RDF to Object Conversion and the linked-list conversion.
 *
 * The inverse of {@see ToRdf}: it consumes a flat list of {@see RdfQuad}
 * (parse N-Quads with `NQuadsParser` first) and produces an expanded JSON-LD
 * document.
 *
 * Blank-node identifiers from the input are preserved (not re-issued). A
 * well-formed `rdf:first`/`rdf:rest`/`rdf:nil` chain is collapsed back to an
 * `@list` only when every node in it is a blank node referenced exactly once
 * across the whole dataset (so a list head shared between graphs, or named by
 * an IRI, stays a plain node); a bare reference to `rdf:nil` becomes an empty
 * `@list`.
 */
final class FromRdf
{
    private const RDF_LIST = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#List';

    private const I18N_BASE = 'https://www.w3.org/ns/i18n#';

    /**
     * @param  bool  $useNativeTypes  Coerce xsd:boolean / xsd:integer /
     *                                xsd:double literals to native JSON values.
     * @param  bool  $useRdfType  Keep `rdf:type` as an ordinary property
     *                            (node references) instead of folding it into
     *                            `@type`.
     * @param  string|null  $rdfDirection  "i18n-datatype" reverses i18n-typed
     *                                     literals back to `@direction`
     *                                     (non-normative).
     */
    public function __construct(
        private readonly bool $useNativeTypes = false,
        private readonly bool $useRdfType = false,
        private readonly ?string $rdfDirection = null,
    ) {}

    /**
     * @param  list<RdfQuad>  $quads
     * @return list<array<string, mixed>> Expanded JSON-LD node objects.
     *
     * @throws JsonLdException on an invalid `rdf:JSON` literal.
     */
    public function fromRdf(array $quads): array
    {
        $quads = $this->dedupeQuads($quads);

        // graph name → subject id → node ('@id' + '@type' + property => values).
        /** @var array<string, array<string, array<string, mixed>>> $graphMap */
        $graphMap = ['@default' => []];
        // graph name → object id → list of {s, p, i} references (for list walking).
        /** @var array<string, array<string, list<array{s: string, p: string, i: int}>>> $usages */
        $usages = [];
        // object id → total reference count across ALL graphs (the list gate).
        /** @var array<string, int> $globalRef */
        $globalRef = [];

        foreach ($quads as $quad) {
            $graph = $quad->graph === null ? '@default' : $quad->graph->value;
            $subject = $quad->subject->value;
            $predicate = $quad->predicate->value;

            $graphMap[$graph] ??= [];
            $graphMap[$graph][$subject] ??= [Keyword::Id->value => $subject];

            // rdf:type folds into @type unless useRdfType keeps it a property.
            if ($predicate === RdfTerm::RDF_TYPE && ! $this->useRdfType && ! $quad->object->isLiteral()) {
                $types = $graphMap[$graph][$subject][Keyword::Type->value] ?? [];
                $types = is_array($types) ? $types : [];
                if (! in_array($quad->object->value, $types, true)) {
                    $types[] = $quad->object->value;
                }
                $graphMap[$graph][$subject][Keyword::Type->value] = $types;

                continue;
            }

            $value = $this->objectToValue($quad->object);
            if (! is_array($graphMap[$graph][$subject][$predicate] ?? null)) {
                $graphMap[$graph][$subject][$predicate] = [];
            }
            /** @var list<mixed> $bucket */
            $bucket = $graphMap[$graph][$subject][$predicate];
            $index = $this->appendValue($bucket, $value);
            $graphMap[$graph][$subject][$predicate] = $bucket;

            if (! $quad->object->isLiteral()) {
                $objectId = $quad->object->value;
                $graphMap[$graph][$objectId] ??= [Keyword::Id->value => $objectId];
                $usages[$graph][$objectId][] = ['s' => $subject, 'p' => $predicate, 'i' => $index];
                $globalRef[$objectId] = ($globalRef[$objectId] ?? 0) + 1;
            }
        }

        // Convert linked lists to @list, per graph.
        foreach ($graphMap as $graph => $graphObject) {
            $graphMap[$graph] = $this->convertLists($graphObject, $usages[$graph] ?? [], $globalRef);
        }

        // Fold each named graph into a @graph entry on its graph-name node.
        foreach ($graphMap as $graph => $graphObject) {
            if ($graph === '@default') {
                continue;
            }
            $graphMap['@default'][$graph] ??= [Keyword::Id->value => $graph];
            $graphMap['@default'][$graph][Keyword::Graph->value] = $this->collectNodes($graphObject);
        }

        return $this->collectNodes($graphMap['@default']);
    }

    /**
     * @param  list<RdfQuad>  $quads
     * @return list<RdfQuad>
     */
    private function dedupeQuads(array $quads): array
    {
        $seen = [];
        $unique = [];
        foreach ($quads as $quad) {
            $key = $quad->toNQuads();
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $quad;
            }
        }

        return $unique;
    }

    /**
     * RDF to Object Conversion: a node reference for an IRI / blank node, or a
     * value object for a literal.
     *
     * @return array<string, mixed>
     */
    private function objectToValue(RdfTerm $term): array
    {
        if (! $term->isLiteral()) {
            return [Keyword::Id->value => $term->value];
        }

        return $this->literalToValueObject($term);
    }

    /**
     * @return array<string, mixed>
     */
    private function literalToValueObject(RdfTerm $term): array
    {
        $value = $term->value;
        $datatype = $term->datatype;
        $language = $term->language;

        // rdf:JSON is always parsed (independent of useNativeTypes).
        if ($datatype === RdfTerm::RDF_JSON) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new JsonLdException("invalid JSON literal: {$value}");
            }

            return [Keyword::Value->value => $decoded, Keyword::Type->value => Keyword::Json->value];
        }

        // Language-tagged string.
        if ($language !== null) {
            return [Keyword::Value->value => $value, Keyword::Language->value => $language];
        }

        // i18n-datatype direction (non-normative): reverse the i18n datatype to
        // @language/@direction. Only in "i18n-datatype" mode — otherwise the
        // datatype is kept as a plain @type.
        if ($this->rdfDirection === 'i18n-datatype' && $datatype !== null && str_starts_with($datatype, self::I18N_BASE)) {
            [$lang, $direction] = array_pad(explode('_', substr($datatype, strlen(self::I18N_BASE)), 2), 2, '');
            $result = [Keyword::Value->value => $value];
            if ($lang !== '') {
                $result[Keyword::Language->value] = $lang;
            }
            if ($direction !== '') {
                $result[Keyword::Direction->value] = $direction;
            }

            return $result;
        }

        if ($this->useNativeTypes) {
            $native = $this->coerceNativeType($value, $datatype);
            if ($native !== null) {
                return $native;
            }
        }

        if ($datatype === null || $datatype === RdfTerm::XSD_STRING) {
            return [Keyword::Value->value => $value];
        }

        return [Keyword::Value->value => $value, Keyword::Type->value => $datatype];
    }

    /**
     * Native coercion for useNativeTypes: xsd:boolean / xsd:integer (canonical,
     * in PHP int range) / xsd:double. Returns null when no coercion applies
     * (the caller then keeps the typed string). xsd:decimal is never coerced.
     *
     * @return array<string, mixed>|null
     */
    private function coerceNativeType(string $value, ?string $datatype): ?array
    {
        if ($datatype === RdfTerm::XSD_BOOLEAN) {
            // The xsd:boolean lexical space is {true, false, 1, 0}.
            if ($value === 'true' || $value === '1') {
                return [Keyword::Value->value => true];
            }
            if ($value === 'false' || $value === '0') {
                return [Keyword::Value->value => false];
            }

            return null;
        }

        if ($datatype === RdfTerm::XSD_INTEGER
            && preg_match('/^[+-]?\d+$/', $value) === 1
            && $value === (string) (int) $value
        ) {
            return [Keyword::Value->value => (int) $value];
        }

        if ($datatype === RdfTerm::XSD_DOUBLE && is_numeric($value)) {
            $float = (float) $value;
            // A value that overflows to ±INF / NaN has no JSON number form, so
            // it is kept as a typed string rather than coerced (#t0027).
            if (is_finite($float)) {
                return [Keyword::Value->value => $float];
            }
        }

        return null;
    }

    /**
     * Convert this graph's linked lists to `@list`. Walks back from each
     * reference to `rdf:nil`, consuming blank-node list nodes that are
     * referenced exactly once globally and carry only `rdf:first`/`rdf:rest`
     * (and optionally `@type: rdf:List`). The starting reference's value object
     * becomes the `@list` (empty when nothing is consumed).
     *
     * @param  array<string, array<string, mixed>>  $graphObject
     * @param  array<string, list<array{s: string, p: string, i: int}>>  $usages
     * @param  array<string, int>  $globalRef
     * @return array<string, array<string, mixed>>
     */
    private function convertLists(array $graphObject, array $usages, array $globalRef): array
    {
        $nil = RdfTerm::RDF_NIL;
        if (! isset($usages[$nil])) {
            return $graphObject;
        }

        foreach ($usages[$nil] as $nilUsage) {
            $list = [];
            $listNodes = [];
            $subject = $nilUsage['s'];
            $property = $nilUsage['p'];
            $index = $nilUsage['i'];

            while (
                $property === RdfTerm::RDF_REST
                && str_starts_with($subject, '_:')
                && ($globalRef[$subject] ?? 0) === 1
                && isset($graphObject[$subject])
                && $this->isWellFormedListNode($graphObject[$subject])
            ) {
                /** @var list<mixed> $firsts */
                $firsts = $graphObject[$subject][RdfTerm::RDF_FIRST];
                $list[] = $firsts[0];
                $listNodes[] = $subject;

                $next = $usages[$subject][0];
                $subject = $next['s'];
                $property = $next['p'];
                $index = $next['i'];
            }

            // Replace the reference value with the assembled @list (the items
            // were collected tail-first, so reverse them).
            if (isset($graphObject[$subject][$property]) && is_array($graphObject[$subject][$property])) {
                /** @var array<int, mixed> $bucket */
                $bucket = $graphObject[$subject][$property];
                $bucket[$index] = [Keyword::List->value => array_reverse($list)];
                $graphObject[$subject][$property] = $bucket;
            }

            foreach ($listNodes as $node) {
                unset($graphObject[$node]);
            }
        }

        return $graphObject;
    }

    /**
     * A node usable as an intermediate RDF-list cell: it has exactly one
     * `rdf:first` and one `rdf:rest`, and no properties beyond `@id` and an
     * optional `@type` of exactly `[rdf:List]`.
     *
     * @param  array<string, mixed>  $node
     */
    private function isWellFormedListNode(array $node): bool
    {
        foreach (array_keys($node) as $key) {
            if (! in_array($key, [Keyword::Id->value, Keyword::Type->value, RdfTerm::RDF_FIRST, RdfTerm::RDF_REST], true)) {
                return false;
            }
        }
        if (isset($node[Keyword::Type->value]) && $node[Keyword::Type->value] !== [self::RDF_LIST]) {
            return false;
        }

        return is_array($node[RdfTerm::RDF_FIRST] ?? null) && count($node[RdfTerm::RDF_FIRST]) === 1
            && is_array($node[RdfTerm::RDF_REST] ?? null) && count($node[RdfTerm::RDF_REST]) === 1;
    }

    /**
     * The graph's node objects, dropping bare references (a node whose only key
     * is `@id` was merely referenced, never described).
     *
     * @param  array<string, array<string, mixed>>  $graphObject
     * @return list<array<string, mixed>>
     */
    private function collectNodes(array $graphObject): array
    {
        $nodes = [];
        foreach ($graphObject as $node) {
            if (count($node) === 1 && array_key_exists(Keyword::Id->value, $node)) {
                continue;
            }
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Append a value object to a property's value list unless an equal one is
     * already present; returns the resulting index.
     *
     * @param  list<mixed>  $values
     * @param  array<string, mixed>  $value
     */
    private function appendValue(array &$values, array $value): int
    {
        foreach ($values as $index => $existing) {
            if ($this->sameValue($existing, $value)) {
                return $index;
            }
        }
        $values[] = $value;

        return count($values) - 1;
    }

    /**
     * Deep, key-order-independent strict equality (mirrors NodeMap's, so RDF
     * values such as `1` and `true` are never wrongly merged).
     */
    private function sameValue(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (! array_key_exists($key, $b) || ! $this->sameValue($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }
}
