<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Documents;

use Accredify\JsonLd\JsonLdProcessor;

/**
 * The result of the `fromRdf` algorithm — an RDF dataset deserialised to an
 * expanded JSON-LD document
 * ({@link https://www.w3.org/TR/json-ld11-api/#serialize-rdf-as-json-ld-algorithm §4.9}).
 *
 * The output is an array of node objects in expanded form; pair it with
 * {@see JsonLdProcessor::compact()} or `flatten()` to reshape
 * it. Held in a read-only wrapper (like {@see ExpandedDocument}).
 */
final class FromRdfDocument
{
    /**
     * @param  array<mixed>  $nodes
     */
    public function __construct(
        private readonly array $nodes
    ) {}

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->nodes;
    }
}
