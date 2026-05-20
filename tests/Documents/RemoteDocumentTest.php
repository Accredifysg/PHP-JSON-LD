<?php

declare(strict_types=1);

use Accredify\JsonLd\Documents\RemoteDocument;

describe('RemoteDocument', function () {
    it('exposes document, documentUrl, and contextUrl as readonly properties', function () {
        $doc = new RemoteDocument(
            document: ['@context' => ['id' => '@id']],
            documentUrl: 'https://example.com/ctx.jsonld',
            contextUrl: 'https://example.com/alt.jsonld',
        );

        expect($doc->document)->toBe(['@context' => ['id' => '@id']]);
        expect($doc->documentUrl)->toBe('https://example.com/ctx.jsonld');
        expect($doc->contextUrl)->toBe('https://example.com/alt.jsonld');
    });

    it('contextUrl defaults to null', function () {
        $doc = new RemoteDocument(
            document: ['@context' => []],
            documentUrl: 'https://example.com/ctx.jsonld',
        );

        expect($doc->contextUrl)->toBeNull();
    });
});
