<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Contracts;

use Accredify\JsonLd\Documents\CompactedDocument;
use Accredify\JsonLd\Documents\ExpandedDocument;
use Accredify\JsonLd\Documents\FlattenedDocument;
use Accredify\JsonLd\Documents\FramedDocument;
use Accredify\JsonLd\Documents\FromRdfDocument;
use Accredify\JsonLd\Documents\RdfDataset;
use Accredify\JsonLd\Exceptions\JsonLdException;
use Accredify\JsonLd\JsonLdOptions;

/**
 * Public entry point for the package's algorithms.
 *
 * `expand`, `compact`, `flatten`, `toRdf`, `fromRdf`, and `frame` are implemented.
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
     * @param  JsonLdOptions|null  $options  API options (base IRI, processing
     *                                       mode, …). Null uses the defaults
     *                                       (no base, JSON-LD 1.1).
     *
     * @throws JsonLdException When `@context` is missing or any sub-algorithm
     *                         raises.
     */
    public function expand(array $document, ?JsonLdOptions $options = null): ExpandedDocument;

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
     * @param  JsonLdOptions|null  $options  API options (processing mode, …).
     *                                       Null uses the defaults.
     *
     * @throws JsonLdException
     */
    public function compact(array $expanded, array|string $context, ?JsonLdOptions $options = null): CompactedDocument;

    /**
     * Flatten a JSON-LD document (the Flattening algorithm,
     * {@link https://www.w3.org/TR/json-ld11-api/#flattening-algorithm §4.6}):
     * collect every node object into a single flat array in the default graph,
     * labelling blank nodes deterministically.
     *
     * As with {@see toRdf()}, a missing `@context` is tolerated.
     *
     * @param  array<array-key, mixed>  $document  A JSON-LD document.
     * @param  array<array-key, mixed>|string|null  $context  When non-null, the
     *                                                        flattened output is
     *                                                        compacted against it
     *                                                        and wrapped in
     *                                                        `@graph`; when null
     *                                                        the expanded-flattened
     *                                                        form is returned.
     * @param  JsonLdOptions|null  $options  API options. Null uses the defaults.
     *
     * @throws JsonLdException
     */
    public function flatten(array $document, array|string|null $context = null, ?JsonLdOptions $options = null): FlattenedDocument;

    /**
     * Deserialize a JSON-LD document to an RDF dataset (the toRdf algorithm).
     *
     * Unlike {@see expand()}, a missing `@context` is tolerated (the document
     * is expanded against an empty active context) since many RDF-bearing
     * documents address their predicates with full IRIs.
     *
     * @param  array<array-key, mixed>  $document  A JSON-LD document.
     * @param  JsonLdOptions|null  $options  API options (base IRI, processing
     *                                       mode, …). Null uses the defaults.
     *
     * @throws JsonLdException
     */
    public function toRdf(array $document, ?JsonLdOptions $options = null): RdfDataset;

    /**
     * Deserialize an RDF dataset to an expanded JSON-LD document (the `fromRdf`
     * algorithm,
     * {@link https://www.w3.org/TR/json-ld11-api/#serialize-rdf-as-json-ld-algorithm §4.9}).
     *
     * @param  RdfDataset|string  $input  An {@see RdfDataset}, or an N-Quads
     *                                    string (parsed before deserialisation).
     * @param  JsonLdOptions|null  $options  API options — `useNativeTypes`,
     *                                       `useRdfType`, `rdfDirection`. Null
     *                                       uses the defaults.
     *
     * @throws JsonLdException
     */
    public function fromRdf(RdfDataset|string $input, ?JsonLdOptions $options = null): FromRdfDocument;

    /**
     * Reshape a document to match a frame (the JSON-LD 1.1 Framing algorithm,
     * {@link https://www.w3.org/TR/json-ld11-framing/}): match nodes against
     * the frame, embed referenced nodes inline, and compact the result against
     * the frame's `@context`.
     *
     * @param  array<array-key, mixed>  $document  A JSON-LD document.
     * @param  array<array-key, mixed>  $frame  A JSON-LD frame.
     * @param  JsonLdOptions|null  $options  API options (`embed`, `explicit`,
     *                                       `requireAll`, `omitGraph`, …).
     *
     * @throws JsonLdException
     */
    public function frame(array $document, array $frame, ?JsonLdOptions $options = null): FramedDocument;
}
