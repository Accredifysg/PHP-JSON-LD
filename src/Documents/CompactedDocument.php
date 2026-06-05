<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Documents;

/**
 * The result of running the JSON-LD Compaction Algorithm over a document.
 *
 * Per {@link https://www.w3.org/TR/json-ld11-api/#compaction-algorithm §5.6}
 * the output is a single node object (with the supplied `@context` prepended),
 * not the array-of-nodes that expansion produces.
 *
 * Held in a read-only wrapper for the same reason as
 * {@see ExpandedDocument}: downstream code can pin against this exact type.
 */
final class CompactedDocument
{
    /**
     * @param  array<string, mixed>  $compacted
     */
    public function __construct(
        private readonly array $compacted
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->compacted;
    }
}
