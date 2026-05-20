<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\Algorithms\Characterization\Support;

use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Documents\RemoteDocument;
use Accredify\JsonLd\Exceptions\DocumentLoaderException;
use JsonException;
use RuntimeException;

/**
 * DocumentLoader for characterization tests that serves a known set of
 * `@context` URLs from JSON files in tests/Algorithms/Characterization/fixtures/contexts/.
 *
 * Mirrors how VC's `JsonLdContextUrl` enum resolves URLs locally — that
 * pattern lives in VC's domain, but characterization tests need the same
 * mappings to reproduce VC's expansion output without hitting the network.
 *
 * Unknown URLs raise {@see DocumentLoaderException} (no HTTP fallback) so
 * tests fail loudly if they ever reference a context that wasn't bundled
 * for the snapshot.
 */
final class BundledContextLoader implements DocumentLoader
{
    /** @var array<string, string> Map of URL → fixture filename. */
    private const MAP = [
        'https://www.w3.org/ns/credentials/v2' => 'vc_context_v2.json',
        'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json' => 'ob_context_v3_0_3.json',
        'https://purl.imsglobal.org/spec/ob/v3p0/extensions.json' => 'ob_context_v3_extensions.json',
    ];

    private readonly string $contextsDir;

    public function __construct(?string $contextsDir = null)
    {
        $this->contextsDir = $contextsDir ?? __DIR__.'/../fixtures/contexts';

        if (! is_dir($this->contextsDir)) {
            throw new RuntimeException("Contexts directory not found: {$this->contextsDir}");
        }
    }

    public function loadDocument(string $url): RemoteDocument
    {
        if (! isset(self::MAP[$url])) {
            throw new DocumentLoaderException(
                "No bundled context for {$url}. Add the mapping to BundledContextLoader::MAP "
                .'and drop the JSON fixture under tests/Algorithms/Characterization/fixtures/contexts/.',
            );
        }

        $path = $this->contextsDir.'/'.self::MAP[$url];
        if (! is_file($path)) {
            throw new DocumentLoaderException("Bundled context file missing: {$path}");
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new DocumentLoaderException(
                "Bundled context {$path} is not valid JSON: {$e->getMessage()}",
                0,
                $e,
            );
        }

        if (! is_array($decoded)) {
            throw new DocumentLoaderException("Bundled context {$path} did not decode to an array");
        }

        return new RemoteDocument(
            document: $decoded,
            documentUrl: $url,
        );
    }
}
