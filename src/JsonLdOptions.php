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
 *
 * Reserved for forthcoming work (accepted but not yet acted upon):
 *  - {@see $rdfDirection} — "i18n-datatype" / "compound-literal" for toRdf.
 *  - {@see $produceGeneralizedRdf} — emit generalized RDF (blank-node
 *    predicates) from toRdf.
 *
 * Immutable: use {@see with()} to derive a copy with one field changed.
 */
final class JsonLdOptions
{
    public function __construct(
        public readonly ?string $base = null,
        public readonly ?string $processingMode = null,
        public readonly ?string $rdfDirection = null,
        public readonly bool $produceGeneralizedRdf = false,
    ) {}

    /**
     * Derive a copy with selected fields overridden.
     */
    public function with(
        ?string $base = null,
        ?string $processingMode = null,
        ?string $rdfDirection = null,
        ?bool $produceGeneralizedRdf = null,
    ): self {
        return new self(
            $base ?? $this->base,
            $processingMode ?? $this->processingMode,
            $rdfDirection ?? $this->rdfDirection,
            $produceGeneralizedRdf ?? $this->produceGeneralizedRdf,
        );
    }
}
