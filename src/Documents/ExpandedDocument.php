<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Documents;

/**
 * The result of running the JSON-LD Expansion Algorithm over a document.
 *
 * Per {@link https://www.w3.org/TR/json-ld11-api/#expansion-algorithm §5.5}
 * the output is always an array of node objects (even when the input was a
 * single object, expansion wraps it).
 *
 * Held in a read-only wrapper rather than a bare array so that downstream
 * code (e.g. canonicalization, signature suites) can pin against this exact
 * type and not be accidentally fed a partially-expanded document.
 */
final class ExpandedDocument
{
    /**
     * @param  array<mixed>  $expanded
     */
    public function __construct(
        private readonly array $expanded
    ) {}

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->expanded;
    }
}
