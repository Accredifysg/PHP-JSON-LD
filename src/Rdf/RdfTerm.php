<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Rdf;

use Accredify\JsonLd\Algorithms\ToRdf;

/**
 * An RDF term: an IRI, a blank node identifier, or a literal.
 *
 * Produced by the toRdf algorithm ({@see ToRdf})
 * and serialised to N-Quads via {@see toNQuads()}.
 */
final class RdfTerm
{
    public const IRI = 'IRI';

    public const BLANK_NODE = 'blank node';

    public const LITERAL = 'literal';

    // Common datatype / vocabulary IRIs used by the conversion algorithm.
    public const XSD_STRING = 'http://www.w3.org/2001/XMLSchema#string';

    public const XSD_BOOLEAN = 'http://www.w3.org/2001/XMLSchema#boolean';

    public const XSD_INTEGER = 'http://www.w3.org/2001/XMLSchema#integer';

    public const XSD_DOUBLE = 'http://www.w3.org/2001/XMLSchema#double';

    public const RDF_TYPE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';

    public const RDF_FIRST = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#first';

    public const RDF_REST = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#rest';

    public const RDF_NIL = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#nil';

    public const RDF_LANG_STRING = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#langString';

    public const RDF_JSON = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#JSON';

    public const RDF_DIRECTION = 'https://www.w3.org/ns/i18n#';

    private function __construct(
        public readonly string $kind,
        public readonly string $value,
        public readonly ?string $datatype = null,
        public readonly ?string $language = null,
    ) {}

    public static function iri(string $value): self
    {
        return new self(self::IRI, $value);
    }

    public static function blankNode(string $value): self
    {
        return new self(self::BLANK_NODE, $value);
    }

    public static function literal(string $value, ?string $datatype = null, ?string $language = null): self
    {
        return new self(
            self::LITERAL,
            $value,
            $datatype ?? ($language !== null ? self::RDF_LANG_STRING : self::XSD_STRING),
            $language,
        );
    }

    public function isLiteral(): bool
    {
        return $this->kind === self::LITERAL;
    }

    /**
     * Serialise this term to its N-Quads lexical form.
     */
    public function toNQuads(): string
    {
        return match ($this->kind) {
            self::IRI => '<'.self::escapeIri($this->value).'>',
            self::BLANK_NODE => $this->value,
            default => $this->literalToNQuads(),
        };
    }

    private function literalToNQuads(): string
    {
        $quoted = '"'.self::escapeLiteral($this->value).'"';

        if ($this->language !== null) {
            return $quoted.'@'.$this->language;
        }

        // xsd:string is the implicit datatype in canonical N-Triples/N-Quads
        // and is omitted; every other datatype is written explicitly.
        if ($this->datatype === null || $this->datatype === self::XSD_STRING) {
            return $quoted;
        }

        return $quoted.'^^<'.self::escapeIri($this->datatype).'>';
    }

    private static function escapeLiteral(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);
    }

    private static function escapeIri(string $value): string
    {
        // Backslashes and the small set of characters disallowed inside an
        // IRIREF are escaped; everything else is emitted verbatim.
        return strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);
    }
}
