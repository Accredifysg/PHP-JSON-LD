<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Enums\Keyword;
use Accredify\JsonLd\Internal\BlankNodeIssuer;
use Accredify\JsonLd\Rdf\RdfQuad;
use Accredify\JsonLd\Rdf\RdfTerm;

/**
 * Deserialize JSON-LD to RDF
 * ({@link https://www.w3.org/TR/json-ld11-api/#deserialize-json-ld-to-rdf-algorithm §7.1}),
 * with Object to RDF Conversion (§7.3) and List Conversion (§7.4).
 *
 * Consumes an expanded JSON-LD document (produced by {@see Expansion}), runs
 * {@see NodeMap} generation, and emits a flat list of {@see RdfQuad}.
 *
 * Not yet handled (these remain a small minority of the W3C toRdf suite):
 * `@json` literals via the JSON Canonicalization Scheme, the `rdfDirection`
 * option, and `produceGeneralizedRdf`.
 */
final class ToRdf
{
    /**
     * @param  array<mixed>  $expanded
     * @return list<RdfQuad>
     */
    public function toRdf(array $expanded): array
    {
        $issuer = new BlankNodeIssuer;
        $nodeMap = (new NodeMap($issuer))->generate($expanded);

        $quads = [];

        foreach ($nodeMap as $graphName => $graph) {
            $graphTerm = $this->graphTerm($graphName);
            if ($graphName !== '@default' && $graphTerm === null) {
                continue; // graph name was not a well-formed IRI / blank node
            }

            foreach ($graph as $subject => $node) {
                $subjectTerm = $this->nodeTerm($subject);
                if ($subjectTerm === null) {
                    continue; // relative IRI subject — skip
                }

                foreach ($node as $property => $values) {
                    if ($property === Keyword::Type->value) {
                        foreach ($this->asList($values) as $type) {
                            if (! is_string($type)) {
                                continue;
                            }
                            $object = $this->nodeTerm($type);
                            if ($object !== null) {
                                $quads[] = new RdfQuad($subjectTerm, RdfTerm::iri(RdfTerm::RDF_TYPE), $object, $graphTerm);
                            }
                        }

                        continue;
                    }

                    if (str_starts_with($property, '@')) {
                        continue; // other keywords carry no RDF statement
                    }

                    // Blank-node predicates are dropped (no generalized RDF);
                    // relative-IRI predicates are not valid RDF.
                    if (str_starts_with($property, '_:') || ! $this->isAbsoluteIri($property)) {
                        continue;
                    }

                    $predicate = RdfTerm::iri($property);

                    foreach ($this->asList($values) as $item) {
                        $listQuads = [];
                        $object = $this->objectToRdf($item, $listQuads, $issuer);
                        if ($object !== null) {
                            $quads[] = new RdfQuad($subjectTerm, $predicate, $object, $graphTerm);
                        }
                        foreach ($listQuads as $listQuad) {
                            // Re-stamp list triples with the active graph.
                            $quads[] = new RdfQuad($listQuad->subject, $listQuad->predicate, $listQuad->object, $graphTerm);
                        }
                    }
                }
            }
        }

        return $quads;
    }

    /**
     * Object to RDF Conversion (§7.3). Appends any RDF-list triples to
     * $listQuads (graph stamping is applied by the caller).
     *
     * @param  list<RdfQuad>  $listQuads
     */
    private function objectToRdf(mixed $item, array &$listQuads, BlankNodeIssuer $issuer): ?RdfTerm
    {
        if (! is_array($item)) {
            return null;
        }

        // Node reference.
        if (array_key_exists(Keyword::Id->value, $item) && ! array_key_exists(Keyword::Value->value, $item)) {
            $id = $item[Keyword::Id->value];

            return is_string($id) ? $this->nodeTerm($id) : null;
        }

        // List object.
        if (array_key_exists(Keyword::List->value, $item)) {
            $list = $item[Keyword::List->value];

            return $this->listToRdf(is_array($list) ? array_values($list) : [], $listQuads, $issuer);
        }

        // Value object.
        if (array_key_exists(Keyword::Value->value, $item)) {
            /** @var array<string, mixed> $item */
            return $this->valueToLiteral($item);
        }

        return null;
    }

    /**
     * List Conversion (§7.4): build an RDF collection (rdf:first / rdf:rest /
     * rdf:nil) and return its head.
     *
     * @param  list<mixed>  $list
     * @param  list<RdfQuad>  $listQuads
     */
    private function listToRdf(array $list, array &$listQuads, BlankNodeIssuer $issuer): RdfTerm
    {
        if ($list === []) {
            return RdfTerm::iri(RdfTerm::RDF_NIL);
        }

        $bnodes = [];
        foreach ($list as $i => $_) {
            $bnodes[$i] = RdfTerm::blankNode($issuer->getId());
        }

        foreach ($list as $i => $item) {
            $subject = $bnodes[$i];
            $object = $this->objectToRdf($item, $listQuads, $issuer);
            if ($object !== null) {
                $listQuads[] = new RdfQuad($subject, RdfTerm::iri(RdfTerm::RDF_FIRST), $object);
            }
            $rest = isset($bnodes[$i + 1]) ? $bnodes[$i + 1] : RdfTerm::iri(RdfTerm::RDF_NIL);
            $listQuads[] = new RdfQuad($subject, RdfTerm::iri(RdfTerm::RDF_REST), $rest);
        }

        return $bnodes[0];
    }

    /**
     * @param  array<string, mixed>  $item  A JSON-LD value object.
     */
    private function valueToLiteral(array $item): RdfTerm
    {
        $value = $item[Keyword::Value->value];

        // A value object's @type is a single datatype IRI, but expansion
        // normalises it to a list; unwrap it.
        $rawType = $item[Keyword::Type->value] ?? null;
        if (is_array($rawType)) {
            $rawType = $rawType[0] ?? null;
        }
        $datatype = is_string($rawType) ? $rawType : null;

        $language = isset($item[Keyword::Language->value]) && is_string($item[Keyword::Language->value])
            ? $item[Keyword::Language->value]
            : null;

        // @json literal: serialised with the JSON Canonicalization Scheme and
        // typed as rdf:JSON.
        if ($datatype === Keyword::Json->value) {
            return RdfTerm::literal($this->canonicalJson($value), RdfTerm::RDF_JSON);
        }

        if (is_bool($value)) {
            return RdfTerm::literal($value ? 'true' : 'false', $datatype ?? RdfTerm::XSD_BOOLEAN);
        }

        if (is_float($value) || (is_int($value) && $datatype === RdfTerm::XSD_DOUBLE)) {
            $isIntegerValued = is_finite((float) $value) && floor((float) $value) === (float) $value;
            if (! $isIntegerValued || $datatype === RdfTerm::XSD_DOUBLE) {
                return RdfTerm::literal($this->canonicalDouble((float) $value), $datatype ?? RdfTerm::XSD_DOUBLE);
            }

            return RdfTerm::literal($this->canonicalInteger((float) $value), $datatype ?? RdfTerm::XSD_INTEGER);
        }

        if (is_int($value)) {
            return RdfTerm::literal((string) $value, $datatype ?? RdfTerm::XSD_INTEGER);
        }

        // String value.
        $stringValue = is_scalar($value) ? (string) $value : '';

        return RdfTerm::literal($stringValue, $datatype, $language);
    }

    /**
     * XSD canonical lexical form of a double, e.g. 5.3 → "5.3E0", 1e-7 → "1.0E-7".
     */
    private function canonicalDouble(float $value): string
    {
        if ($value == 0.0) {
            return '0.0E0';
        }

        $formatted = sprintf('%1.15e', $value);

        // Trim the trailing zeros of the mantissa (keeping at least one digit)
        // and normalise the exponent marker, matching the reference shortest
        // canonical form.
        return (string) preg_replace('/(\d)0*e\+?/', '$1E', $formatted);
    }

    private function canonicalInteger(float $value): string
    {
        return number_format($value, 0, '.', '');
    }

    /**
     * Serialise a value to JSON for an rdf:JSON literal. Object keys are sorted
     * recursively, slashes and Unicode are left unescaped. This covers the
     * straightforward @json cases; the full JSON Canonicalization Scheme
     * (Unicode normalisation, ECMAScript number formatting, UTF-16 key
     * ordering) is not yet implemented.
     */
    private function canonicalJson(mixed $value): string
    {
        $sorted = $this->sortJsonKeys($value);

        return (string) json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sortJsonKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $v): mixed => $this->sortJsonKeys($v), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $v): mixed => $this->sortJsonKeys($v), $value);
    }

    private function graphTerm(string $graphName): ?RdfTerm
    {
        if ($graphName === '@default') {
            return null;
        }

        return $this->nodeTerm($graphName);
    }

    /**
     * Convert a subject / object / graph node identifier to an RDF term,
     * returning null for a relative IRI (which has no RDF representation).
     */
    private function nodeTerm(string $value): ?RdfTerm
    {
        if (str_starts_with($value, '_:')) {
            return RdfTerm::blankNode($value);
        }

        return $this->isAbsoluteIri($value) ? RdfTerm::iri($value) : null;
    }

    private function isAbsoluteIri(string $value): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $value) === 1;
    }

    /**
     * @return list<mixed>
     */
    private function asList(mixed $values): array
    {
        if (is_array($values) && array_is_list($values)) {
            return $values;
        }

        return [$values];
    }
}
