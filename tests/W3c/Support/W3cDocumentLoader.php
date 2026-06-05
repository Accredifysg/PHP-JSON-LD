<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\W3c\Support;

use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Documents\RemoteDocument;
use Accredify\JsonLd\Exceptions\DocumentLoaderException;
use JsonException;

/**
 * DocumentLoader used by the W3C harness.
 *
 * Maps the W3C JSON-LD test suite's base URL
 * (`https://w3c.github.io/json-ld-api/tests/`) to local fixture files
 * under `tests/w3c/tests/`. Any URL outside that prefix throws — the
 * harness must run offline, deterministically, with no network calls.
 */
final class W3cDocumentLoader implements DocumentLoader
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $baseDir,
    ) {}

    public static function default(): self
    {
        return new self(
            baseUrl: 'https://w3c.github.io/json-ld-api/tests/',
            baseDir: __DIR__.'/../../w3c/tests',
        );
    }

    public function loadDocument(string $url): RemoteDocument
    {
        if (! str_starts_with($url, $this->baseUrl)) {
            throw new DocumentLoaderException("URL outside W3C test base: {$url}");
        }

        $relative = substr($url, strlen($this->baseUrl));
        $localPath = $this->baseDir.'/'.$relative;

        if (! is_file($localPath)) {
            throw new DocumentLoaderException("No local fixture for {$url} (expected at {$localPath})");
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($localPath),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new DocumentLoaderException("Fixture {$localPath} is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        if (! is_array($decoded)) {
            throw new DocumentLoaderException("Fixture {$localPath} did not decode to a JSON object/array");
        }

        return new RemoteDocument(
            document: $decoded,
            documentUrl: $url,
        );
    }
}
