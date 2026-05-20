<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Contracts;

use Accredify\JsonLd\Documents\ExpandedDocument;
use Accredify\JsonLd\Exceptions\JsonLdException;

/**
 * Public entry point for the package's algorithms.
 *
 * Only `expand` is defined in v0.1; `compact` and `toRdf` join in Phase 4.
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
     *
     * @throws JsonLdException When `@context` is missing or any sub-algorithm
     *                         raises.
     */
    public function expand(array $document): ExpandedDocument;
}
