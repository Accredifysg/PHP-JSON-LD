<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Documents;

/**
 * The result of the JSON-LD 1.1 Framing Algorithm
 * ({@link https://www.w3.org/TR/json-ld11-framing/}): the input reshaped to
 * match a frame, compacted against the frame's `@context` and (unless
 * `omitGraph`) wrapped in `@graph`.
 *
 * Held in a read-only wrapper (like {@see CompactedDocument}).
 */
final class FramedDocument
{
    /**
     * @param  array<string, mixed>  $framed
     */
    public function __construct(
        private readonly array $framed
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->framed;
    }
}
