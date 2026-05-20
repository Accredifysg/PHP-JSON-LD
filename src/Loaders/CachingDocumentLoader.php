<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Loaders;

use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Documents\RemoteDocument;

/**
 * In-process LRU-ish cache wrapping another {@see DocumentLoader}.
 *
 * `@context` URLs are stable, content-addressed-ish identifiers — once
 * resolved within a single process, refetching them is wasted work. The
 * cache is keyed by the *requested* URL (not the post-redirect document
 * URL), so two requests for the same logical context hit the cache even if
 * the underlying loader follows redirects.
 *
 * Failures are NOT cached — every loadDocument that throws is retried on
 * the next request.
 *
 * This is deliberately a simple unbounded cache (no eviction). A process
 * resolves at most a handful of distinct context URLs over its lifetime, so
 * the memory cost is negligible. If a long-running process needs eviction,
 * wrap with a different decorator.
 */
final class CachingDocumentLoader implements DocumentLoader
{
    /** @var array<string, RemoteDocument> */
    private array $cache = [];

    public function __construct(
        private readonly DocumentLoader $inner,
    ) {}

    public function loadDocument(string $url): RemoteDocument
    {
        if (! isset($this->cache[$url])) {
            $this->cache[$url] = $this->inner->loadDocument($url);
        }

        return $this->cache[$url];
    }
}
