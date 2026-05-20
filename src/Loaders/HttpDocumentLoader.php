<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Loaders;

use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Documents\RemoteDocument;
use Accredify\JsonLd\Exceptions\DocumentLoaderException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Default loader: GETs an IRI via a PSR-18 HTTP client and JSON-decodes the
 * response body.
 *
 * Deliberately PSR-18 / PSR-17 rather than a specific client (e.g. Guzzle)
 * so callers can plug in any compliant implementation. PSR-18 implementations
 * available on Packagist include guzzlehttp/guzzle, symfony/http-client,
 * php-http/curl-client, and others.
 *
 * Does NOT follow `Link: rel="http://www.w3.org/ns/json-ld#context"` alternate
 * context headers (a JSON-LD 1.1 spec feature for non-JSON-LD resources). That
 * can be added in a later PR if any real consumer needs it; verifiable
 * credential and Open Badges contexts always serve JSON-LD directly.
 */
final class HttpDocumentLoader implements DocumentLoader
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {}

    public function loadDocument(string $url): RemoteDocument
    {
        $request = $this->requestFactory
            ->createRequest('GET', $url)
            ->withHeader('Accept', 'application/ld+json, application/json;q=0.9');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new DocumentLoaderException(
                "Failed to fetch document at {$url}: {$e->getMessage()}",
                0,
                $e,
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new DocumentLoaderException("HTTP {$status} fetching document at {$url}");
        }

        $body = (string) $response->getBody();

        try {
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DocumentLoaderException(
                "Document at {$url} is not valid JSON: {$e->getMessage()}",
                0,
                $e,
            );
        }

        if (! is_array($decoded)) {
            throw new DocumentLoaderException(
                "Document at {$url} did not decode to a JSON object/array (got ".gettype($decoded).')',
            );
        }

        return new RemoteDocument(
            document: $decoded,
            documentUrl: $url,
            contextUrl: null,
        );
    }
}
