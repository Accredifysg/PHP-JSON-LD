<?php

declare(strict_types=1);

use Accredify\JsonLd\Documents\ExpandedDocument;

describe('ExpandedDocument', function () {
    it('returns the expanded array as-is via toArray', function () {
        $expanded = [
            [
                '@id' => 'https://example.com/credential/123',
                '@type' => ['https://www.w3.org/2018/credentials#VerifiableCredential'],
            ],
        ];

        $doc = new ExpandedDocument($expanded);

        expect($doc->toArray())->toBe($expanded);
    });

    it('accepts an empty array', function () {
        expect((new ExpandedDocument([]))->toArray())->toBe([]);
    });

    it('preserves the exact ordering of keys', function () {
        // RDFC-10 canonicalization is sensitive to key ordering; ExpandedDocument
        // must not mutate it on the way through.
        $expanded = [['@type' => ['Foo'], '@id' => 'urn:1', '@value' => 'x']];

        $doc = new ExpandedDocument($expanded);
        $first = $doc->toArray()[0];
        expect($first)->toBeArray();
        /** @var array<string, mixed> $first */
        expect(array_keys($first))->toBe(['@type', '@id', '@value']);
    });
});
