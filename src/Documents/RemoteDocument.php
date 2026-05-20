<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Documents;

use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Loaders\HttpDocumentLoader;

/**
 * A document returned by a {@see DocumentLoader}.
 *
 * Mirrors the
 * {@link https://www.w3.org/TR/json-ld11-api/#remotedocument RemoteDocument}
 * structure from the JSON-LD 1.1 API specification.
 *
 * `documentUrl` is the *final* URL after any HTTP redirects — relative IRIs
 * inside the document are resolved against it, not against the URL
 * originally requested.
 *
 * `contextUrl` is populated only when the resource served was NOT a JSON-LD
 * document but linked an alternate one via the HTTP `Link: rel="http://www.w3.org/ns/json-ld#context"`
 * header. For JSON-LD responses it remains null. (Most loaders won't
 * implement this — the default {@see HttpDocumentLoader}
 * does not.)
 */
final class RemoteDocument
{
    /**
     * @param  array<mixed>  $document  The parsed JSON document.
     */
    public function __construct(
        public readonly array $document,
        public readonly string $documentUrl,
        public readonly ?string $contextUrl = null,
    ) {}
}
