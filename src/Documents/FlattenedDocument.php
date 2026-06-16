<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Documents;

/**
 * The result of running the JSON-LD Flattening Algorithm over a document.
 *
 * Per {@link https://www.w3.org/TR/json-ld11-api/#flattening-algorithm §4.6}
 * the output is, without a supplied context, an array of node objects in the
 * default graph (named graphs folded in as `@graph` entries). When a context
 * is supplied the output is compacted and wrapped in a `{@context, @graph}`
 * map.
 *
 * Held in a read-only wrapper (like {@see ExpandedDocument}) so downstream
 * code can pin against this exact type rather than a bare array.
 */
final class FlattenedDocument
{
    /**
     * @param  array<mixed>  $flattened
     */
    public function __construct(
        private readonly array $flattened
    ) {}

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->flattened;
    }
}
