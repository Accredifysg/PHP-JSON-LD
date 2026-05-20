<?php

declare(strict_types=1);

use Accredify\JsonLd\Exceptions\DocumentLoaderException;
use Accredify\JsonLd\Loaders\HttpDocumentLoader;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

afterEach(function () {
    Mockery::close();
});

function makeLoader(ClientInterface $client): HttpDocumentLoader
{
    return new HttpDocumentLoader($client, new HttpFactory);
}

describe('HttpDocumentLoader::loadDocument', function () {
    it('returns a RemoteDocument on a 200 with valid JSON-LD body', function () {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('sendRequest')
            ->once()
            ->andReturnUsing(function (RequestInterface $request) {
                expect($request->getMethod())->toBe('GET');
                expect((string) $request->getUri())->toBe('https://example.com/ctx.jsonld');
                expect($request->getHeaderLine('Accept'))->toContain('application/ld+json');

                return new Response(200, [], json_encode(['@context' => ['id' => '@id']]));
            });

        $doc = makeLoader($client)->loadDocument('https://example.com/ctx.jsonld');

        expect($doc->document)->toBe(['@context' => ['id' => '@id']]);
        expect($doc->documentUrl)->toBe('https://example.com/ctx.jsonld');
        expect($doc->contextUrl)->toBeNull();
    });

    it('throws when the underlying client throws a ClientExceptionInterface', function () {
        $client = Mockery::mock(ClientInterface::class);
        $networkError = new class('network down') extends RuntimeException implements ClientExceptionInterface {};
        $client->shouldReceive('sendRequest')->once()->andThrow($networkError);

        expect(fn () => makeLoader($client)->loadDocument('https://example.com/ctx.jsonld'))
            ->toThrow(DocumentLoaderException::class, 'Failed to fetch document at https://example.com/ctx.jsonld: network down');
    });

    it('throws on a non-2xx response', function () {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('sendRequest')->once()->andReturn(new Response(404, [], ''));

        expect(fn () => makeLoader($client)->loadDocument('https://example.com/missing.jsonld'))
            ->toThrow(DocumentLoaderException::class, 'HTTP 404 fetching document at https://example.com/missing.jsonld');
    });

    it('throws when the body is not valid JSON', function () {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('sendRequest')->once()->andReturn(new Response(200, [], 'not-json{'));

        expect(fn () => makeLoader($client)->loadDocument('https://example.com/broken.jsonld'))
            ->toThrow(DocumentLoaderException::class, 'is not valid JSON');
    });

    it('throws when the body decodes to a scalar', function () {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('sendRequest')->once()->andReturn(new Response(200, [], '"just a string"'));

        expect(fn () => makeLoader($client)->loadDocument('https://example.com/scalar.jsonld'))
            ->toThrow(DocumentLoaderException::class, 'did not decode to a JSON object/array');
    });
});
