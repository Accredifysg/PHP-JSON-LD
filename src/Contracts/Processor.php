<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Contracts;

use Accredify\JsonLd\Documents\CompactedDocument;
use Accredify\JsonLd\Documents\ExpandedDocument;
use Accredify\JsonLd\Documents\RdfDataset;
use Accredify\JsonLd\Exceptions\JsonLdException;

/**
 * Public entry point for the package's algorithms.
 *
 * `expand`, `compact`, and `toRdf` are implemented.
 *
 * Kept as a separate interface from {@see DocumentLoader} so that consumers
 * can mock just the processor in tests without worrying about loader
 * concerns.
 */
interface Processor
{
    /**
     * @param  array<array-key, mixed>  $document  A JSON-LD document with at
     *                                             least an `@context` key.
     * @param  string|null  $base  Initial base IRI for document-relative
     *                             `@id` / `@type` resolution (typically the
     *                             document's URL). `@base` in the context
     *                             overrides it. Null disables document-relative
     *                             resolution.
     *
     * @throws JsonLdException When `@context` is missing or any sub-algorithm
     *                         raises.
     */
    public function expand(array $document, ?string $base = null): ExpandedDocument;

    /**
     * Compact an expanded JSON-LD document against the given context.
     *
     * @param  array<array-key, mixed>  $expanded  An expanded JSON-LD document
     *                                             (array of node objects, or a
     *                                             single node object).
     * @param  array<array-key, mixed>|string  $context  The context to compact
     *                                                   against (a context map,
     *                                                   a `{@context: …}` wrapper,
     *                                                   or a context URL).
     *
     * @throws JsonLdException
     */
    public function compact(array $expanded, array|string $context): CompactedDocument;

    /**
     * Deserialize a JSON-LD document to an RDF dataset (the toRdf algorithm).
     *
     * Unlike {@see expand()}, a missing `@context` is tolerated (the document
     * is expanded against an empty active context) since many RDF-bearing
     * documents address their predicates with full IRIs.
     *
     * @param  array<array-key, mixed>  $document  A JSON-LD document.
     * @param  string|null  $base  Initial base IRI for document-relative
     *                             resolution (typically the document's URL).
     *
     * @throws JsonLdException
     */
    public function toRdf(array $document, ?string $base = null): RdfDataset;
}
