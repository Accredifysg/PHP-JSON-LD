<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\Interop\Support;

use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Documents\RemoteDocument;
use Accredify\JsonLd\Exceptions\DocumentLoaderException;

/**
 * Serves the vendored interop contexts (fixtures/contexts/index.json) and
 * NOTHING else. Throwing on any unmapped URL is what makes the corpus
 * offline-deterministic: both this loader and the jsonld.js golden
 * generator resolve contexts from the same vendored files, so a comparison
 * failure can only mean the two processors disagree.
 */
final class FixtureDocumentLoader implements DocumentLoader
{
    /** @var array<string, string> URL => vendored filename */
    private readonly array $index;

    private readonly string $contextsDir;

    public function __construct()
    {
        $this->contextsDir = __DIR__.'/../fixtures/contexts';
        $raw = file_get_contents($this->contextsDir.'/index.json');
        if ($raw === false) {
            throw new \RuntimeException('interop fixtures: cannot read contexts/index.json');
        }
        /** @var array<string, string> $index */
        $index = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->index = $index;
    }

    public function loadDocument(string $url): RemoteDocument
    {
        $file = $this->index[$url] ?? null;
        if ($file === null) {
            throw new DocumentLoaderException(
                "interop fixtures are offline-only: no vendored context for {$url} (add it to fixtures/contexts/index.json)",
            );
        }

        $raw = file_get_contents($this->contextsDir.'/'.$file);
        if ($raw === false) {
            throw new DocumentLoaderException("interop fixtures: cannot read vendored context {$file} for {$url}");
        }

        /** @var array<array-key, mixed> $document */
        $document = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return new RemoteDocument(document: $document, documentUrl: $url);
    }
}
