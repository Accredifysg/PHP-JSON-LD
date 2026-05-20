<?php

declare(strict_types=1);

use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Documents\RemoteDocument;
use Accredify\JsonLd\Exceptions\DocumentLoaderException;
use Accredify\JsonLd\Loaders\CachingDocumentLoader;

afterEach(function () {
    Mockery::close();
});

describe('CachingDocumentLoader', function () {
    it('delegates to the inner loader on first request', function () {
        $inner = Mockery::mock(DocumentLoader::class);
        $expected = new RemoteDocument(['x' => 1], 'https://example.com/ctx');

        $inner->shouldReceive('loadDocument')
            ->once()
            ->with('https://example.com/ctx')
            ->andReturn($expected);

        $loader = new CachingDocumentLoader($inner);

        expect($loader->loadDocument('https://example.com/ctx'))->toBe($expected);
    });

    it('serves cached responses on subsequent requests for the same URL', function () {
        $inner = Mockery::mock(DocumentLoader::class);
        $expected = new RemoteDocument(['x' => 1], 'https://example.com/ctx');

        $inner->shouldReceive('loadDocument')
            ->once() // CRITICAL: the inner loader is hit exactly once
            ->andReturn($expected);

        $loader = new CachingDocumentLoader($inner);
        $first = $loader->loadDocument('https://example.com/ctx');
        $second = $loader->loadDocument('https://example.com/ctx');

        expect($first)->toBe($expected);
        expect($second)->toBe($expected);
    });

    it('caches distinct URLs separately', function () {
        $inner = Mockery::mock(DocumentLoader::class);
        $a = new RemoteDocument(['a' => 1], 'https://example.com/a');
        $b = new RemoteDocument(['b' => 2], 'https://example.com/b');

        $inner->shouldReceive('loadDocument')->with('https://example.com/a')->once()->andReturn($a);
        $inner->shouldReceive('loadDocument')->with('https://example.com/b')->once()->andReturn($b);

        $loader = new CachingDocumentLoader($inner);

        expect($loader->loadDocument('https://example.com/a'))->toBe($a);
        expect($loader->loadDocument('https://example.com/b'))->toBe($b);
        // hit cache for both
        expect($loader->loadDocument('https://example.com/a'))->toBe($a);
        expect($loader->loadDocument('https://example.com/b'))->toBe($b);
    });

    it('does not cache failures', function () {
        $inner = Mockery::mock(DocumentLoader::class);
        // Two distinct exceptions on two distinct calls: if the loader cached
        // the first failure, the second call would never reach the inner.
        $inner->shouldReceive('loadDocument')
            ->with('https://example.com/x')
            ->twice()
            ->andThrow(new DocumentLoaderException('boom'));

        $loader = new CachingDocumentLoader($inner);

        expect(fn () => $loader->loadDocument('https://example.com/x'))
            ->toThrow(DocumentLoaderException::class);
        expect(fn () => $loader->loadDocument('https://example.com/x'))
            ->toThrow(DocumentLoaderException::class);
    });
});
