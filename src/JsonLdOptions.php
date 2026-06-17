<?php

declare(strict_types=1);

namespace Accredify\JsonLd;

/**
 * The options recognised by the JSON-LD API algorithms, mirroring the
 * `JsonLdOptions` interface of the JSON-LD 1.1 API spec (§10.3).
 *
 * Passed (optionally) to {@see Contracts\Processor::expand()},
 * {@see Contracts\Processor::compact()}, and
 * {@see Contracts\Processor::toRdf()}. Every field is nullable; a null field
 * means "use the algorithm default".
 *
 * Currently wired:
 *  - {@see $base} — the initial base IRI for document-relative resolution.
 *  - {@see $processingMode} — "json-ld-1.0" / "json-ld-1.1" (default 1.1).
 *  - {@see $compactArrays} — when true (the default), compaction unwraps
 *    single-element arrays and drops `@graph`/`@set` array wrappers where a
 *    scalar/object suffices; false keeps arrays verbatim.
 *  - {@see $expandContext} — a context applied BEFORE the document's own
 *    `@context` when expanding (it initialises the active context). May be a
 *    context map (optionally wrapped in `{"@context": …}`), an array of
 *    contexts, or a remote context IRI.
 *
 *  - {@see $rdfDirection} — "i18n-datatype" / "compound-literal" for toRdf,
 *    and reversing i18n-typed literals for fromRdf.
 *  - {@see $produceGeneralizedRdf} — emit generalized RDF (blank-node
 *    predicates) from toRdf.
 *  - {@see $useNativeTypes} — fromRdf: coerce xsd:boolean/integer/double
 *    literals to native JSON values.
 *  - {@see $useRdfType} — fromRdf: keep `rdf:type` as a property instead of
 *    folding it into `@type`.
 *
 * Immutable: use {@see with()} to derive a copy with one field changed.
 */
final class JsonLdOptions
{
    /**
     * @param  array<array-key, mixed>|string|null  $expandContext
     */
    public function __construct(
        public readonly ?string $base = null,
        public readonly ?string $processingMode = null,
        public readonly ?string $rdfDirection = null,
        public readonly bool $produceGeneralizedRdf = false,
        public readonly bool $compactArrays = true,
        public readonly array|string|null $expandContext = null,
        public readonly bool $useNativeTypes = false,
        public readonly bool $useRdfType = false,
    ) {}

    /**
     * Derive a copy with selected fields overridden.
     *
     * @param  array<array-key, mixed>|string|null  $expandContext
     */
    public function with(
        ?string $base = null,
        ?string $processingMode = null,
        ?string $rdfDirection = null,
        ?bool $produceGeneralizedRdf = null,
        ?bool $compactArrays = null,
        array|string|null $expandContext = null,
        ?bool $useNativeTypes = null,
        ?bool $useRdfType = null,
    ): self {
        return new self(
            $base ?? $this->base,
            $processingMode ?? $this->processingMode,
            $rdfDirection ?? $this->rdfDirection,
            $produceGeneralizedRdf ?? $this->produceGeneralizedRdf,
            $compactArrays ?? $this->compactArrays,
            $expandContext ?? $this->expandContext,
            $useNativeTypes ?? $this->useNativeTypes,
            $useRdfType ?? $this->useRdfType,
        );
    }
}
