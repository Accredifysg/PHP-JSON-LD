<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\Context\Support;

use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Documents\RemoteDocument;
use Accredify\JsonLd\Exceptions\DocumentLoaderException;

/**
 * In-memory DocumentLoader for tests.
 *
 * Pre-load it with a URL → RemoteDocument map; loadDocument() returns the
 * matching entry, throws DocumentLoaderException otherwise. Records every
 * call so tests can assert which URLs were requested.
 */
final class StubDocumentLoader implements DocumentLoader
{
    /** @var array<string, RemoteDocument> */
    private array $documents = [];

    /** @var list<string> */
    public array $requestedUrls = [];

    /**
     * @param  array<mixed>  $document
     */
    public function add(string $url, array $document): void
    {
        $this->documents[$url] = new RemoteDocument(
            document: $document,
            documentUrl: $url,
        );
    }

    public function loadDocument(string $url): RemoteDocument
    {
        $this->requestedUrls[] = $url;

        if (! isset($this->documents[$url])) {
            throw new DocumentLoaderException("No stub registered for {$url}");
        }

        return $this->documents[$url];
    }
}
