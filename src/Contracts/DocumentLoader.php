<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Contracts;

use Accredify\JsonLd\Documents\RemoteDocument;
use Accredify\JsonLd\Exceptions\DocumentLoaderException;
use Accredify\JsonLd\Loaders\HttpDocumentLoader;

/**
 * Resolves an IRI to a parsed JSON document.
 *
 * Implementations are the package's seam for `@context` URL resolution: the
 * default {@see HttpDocumentLoader} fetches over
 * PSR-18, but consumers (e.g. a Verifiable Credentials library that ships
 * bundled VC v2 / Open Badges v3 contexts) can supply a loader that serves
 * known URLs from local resources.
 *
 * Implementations MUST throw {@see DocumentLoaderException} on failure —
 * never return null. This lets the spec's "loading document failed" error
 * code propagate cleanly up the call stack.
 *
 * @link https://www.w3.org/TR/json-ld11-api/#loaddocumentcallback
 */
interface DocumentLoader
{
    /**
     * @throws DocumentLoaderException When the document cannot be fetched or
     *                                 parsed. The exception message should include the URL.
     */
    public function loadDocument(string $url): RemoteDocument;
}
