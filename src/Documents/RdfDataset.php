<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Documents;

use Accredify\JsonLd\Rdf\RdfQuad;

/**
 * The result of running the Deserialize JSON-LD to RDF Algorithm
 * ({@link https://www.w3.org/TR/json-ld11-api/#deserialize-json-ld-to-rdf-algorithm §7})
 * over a document: an RDF dataset, modelled as a list of {@see RdfQuad}.
 *
 * An RDF dataset is unordered; {@see toNQuads()} produces a deterministic
 * serialisation by de-duplicating and lexically sorting the quad lines, which
 * is the canonical shape the W3C toRdf fixtures are compared against.
 */
final class RdfDataset
{
    /**
     * @param  list<RdfQuad>  $quads
     */
    public function __construct(
        private readonly array $quads,
    ) {}

    /**
     * @return list<RdfQuad>
     */
    public function getQuads(): array
    {
        return $this->quads;
    }

    /**
     * Canonical N-Quads serialisation: each quad on its own line, terminated
     * by a newline, with duplicate lines removed and the whole sorted
     * lexically (a dataset has no inherent order).
     */
    public function toNQuads(): string
    {
        $lines = [];
        foreach ($this->quads as $quad) {
            $lines[$quad->toNQuads()] = true;
        }

        $unique = array_keys($lines);
        sort($unique, SORT_STRING);

        if ($unique === []) {
            return '';
        }

        return implode("\n", $unique)."\n";
    }
}
