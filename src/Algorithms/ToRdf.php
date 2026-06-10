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
 * `@json` literals via the JSON Canonicalization Scheme and the `rdfDirection`
 * option's full surface.
 */
final class ToRdf
{
    private const I18N_BASE = 'https://www.w3.org/ns/i18n#';

    private const RDF_VALUE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#value';

    private const RDF_LANGUAGE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#language';

    private const RDF_DIRECTION = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#direction';

    /**
     * @param  string|null  $rdfDirection  The `rdfDirection` option:
     *                                     "i18n-datatype" or "compound-literal".
     *                                     Null leaves base-direction information
     *                                     out of the RDF (the default).
     * @param  bool  $produceGeneralizedRdf  When true (§7.1), a blank-node
     *                                       predicate is kept (generalized RDF)
     *                                       instead of dropping the statement.
     */
    public function __construct(
        private readonly ?string $rdfDirection = null,
        private readonly bool $produceGeneralizedRdf = false,
    ) {}

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

                    // A blank-node predicate is dropped UNLESS produceGeneralizedRdf
                    // is set (§7.1), in which case it is emitted as a generalized
                    // RDF predicate (#t0118/#te075). A relative-IRI predicate is
                    // never valid RDF.
                    $isBlankPredicate = str_starts_with($property, '_:');
                    if ($isBlankPredicate) {
                        if (! $this->produceGeneralizedRdf) {
                            continue;
                        }
                        $predicate = RdfTerm::blankNode($property);
                    } else {
                        if (! $this->isAbsoluteIri($property)) {
                            continue;
                        }
                        $predicate = RdfTerm::iri($property);
                    }

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
            return $this->valueToLiteral($item, $listQuads, $issuer);
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
     * @param  list<RdfQuad>  $listQuads  Receives any auxiliary triples (used
     *                                    by the compound-literal rdfDirection
     *                                    mode).
     * @return RdfTerm|null Null when the value carries a malformed language
     *                      tag and the statement must be dropped (#twf05).
     */
    private function valueToLiteral(array $item, array &$listQuads, BlankNodeIssuer $issuer): ?RdfTerm
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

        $direction = isset($item[Keyword::Direction->value]) && is_string($item[Keyword::Direction->value])
            ? $item[Keyword::Direction->value]
            : null;

        // A base-direction-tagged string under an `rdfDirection` mode (§9):
        // either an i18n datatype IRI or a compound literal (blank node with
        // rdf:value / rdf:language / rdf:direction). Only applies to plain
        // strings (no explicit datatype).
        if ($direction !== null && $this->rdfDirection !== null && $datatype === null) {
            $stringValue = is_scalar($value) ? (string) $value : '';

            if ($this->rdfDirection === 'i18n-datatype') {
                return RdfTerm::literal($stringValue, self::I18N_BASE.strtolower($language ?? '').'_'.$direction);
            }

            if ($this->rdfDirection === 'compound-literal') {
                $node = RdfTerm::blankNode($issuer->getId());
                $listQuads[] = new RdfQuad($node, RdfTerm::iri(self::RDF_VALUE), RdfTerm::literal($stringValue));
                if ($language !== null) {
                    $listQuads[] = new RdfQuad($node, RdfTerm::iri(self::RDF_LANGUAGE), RdfTerm::literal(strtolower($language)));
                }
                $listQuads[] = new RdfQuad($node, RdfTerm::iri(self::RDF_DIRECTION), RdfTerm::literal($direction));

                return $node;
            }
        }

        // @json literal: serialised with the JSON Canonicalization Scheme and
        // typed as rdf:JSON.
        if ($datatype === Keyword::Json->value) {
            return RdfTerm::literal($this->canonicalJson($value), RdfTerm::RDF_JSON);
        }

        if (is_bool($value)) {
            return RdfTerm::literal($value ? 'true' : 'false', $datatype ?? RdfTerm::XSD_BOOLEAN);
        }

        if (is_float($value) || (is_int($value) && $datatype === RdfTerm::XSD_DOUBLE)) {
            // A JSON number with no fractional part is xsd:integer only when its
            // magnitude is below 1e21; at or above that it serialises as an
            // xsd:double in canonical form, e.g. 1.0e21 → "1.0E21" (#trt01).
            $isIntegerValued = is_finite((float) $value)
                && floor((float) $value) === (float) $value
                && abs((float) $value) < 1.0e21;
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

        // A language-tagged literal whose tag is not a well-formed BCP47
        // language tag carries no valid RDF language: the statement is dropped
        // (#twf05). A well-formed tag is ALPHA{1,8} (-(ALPHANUM){1,8})*.
        if ($language !== null && preg_match('/^[a-zA-Z]{1,8}(-[a-zA-Z0-9]{1,8})*$/', $language) !== 1) {
            return null;
        }

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
     * Serialise a value to JSON for an rdf:JSON literal, following the JSON
     * Canonicalization Scheme (RFC 8785): object keys sorted recursively,
     * slashes and Unicode left unescaped, and numbers formatted per the
     * ECMAScript Number-to-String algorithm. Strings and structure are
     * delegated to {@see json_encode} (so escaping matches exactly); only
     * numbers get bespoke formatting.
     */
    private function canonicalJson(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return $this->jcsNumber($value);
        }
        if (is_string($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(fn (mixed $v): string => $this->canonicalJson($v), $value)).']';
            }
            ksort($value, SORT_STRING);
            $members = [];
            foreach ($value as $key => $member) {
                $members[] = (string) json_encode((string) $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    .':'.$this->canonicalJson($member);
            }

            return '{'.implode(',', $members).'}';
        }

        return 'null';
    }

    /**
     * Format a float per the ECMAScript Number-to-String algorithm (RFC 8785
     * §3.2.2.3): the shortest round-tripping decimal, with an exponential
     * mantissa that drops a redundant ".0" (so 1e30 → "1e+30", not "1.0e+30").
     */
    private function jcsNumber(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return 'null'; // JSON has no NaN / Infinity
        }

        // PHP's shortest round-trip representation (serialize_precision = -1).
        $s = (string) json_encode($value);

        $ePos = stripos($s, 'e');
        if ($ePos === false) {
            return $s; // plain decimal already matches ECMAScript here
        }

        $mantissa = substr($s, 0, $ePos);
        $exponent = substr($s, $ePos + 1);
        if (str_contains($mantissa, '.')) {
            $mantissa = rtrim(rtrim($mantissa, '0'), '.');
        }
        if ($exponent !== '' && $exponent[0] !== '+' && $exponent[0] !== '-') {
            $exponent = '+'.$exponent;
        }

        return $mantissa.'e'.$exponent;
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
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $value) !== 1) {
            return false;
        }

        // An IRI has at most ONE fragment: a value with two "#" (e.g. a term
        // appended to a `#`-terminated relative @vocab → "…/rel2##fragment")
        // is not a well-formed IRI, so its statement is dropped (#te111/#te112).
        if (substr_count($value, '#') > 1) {
            return false;
        }

        // Reject characters that are not permitted in an IRIREF (spaces,
        // control characters, and the delimiter set), so a malformed IRI such
        // as "http://example.com/a b" produces no RDF term and its statement
        // is dropped.
        return preg_match('/[\x00-\x20"<>{}|\^`\\\\]/', $value) !== 1;
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
