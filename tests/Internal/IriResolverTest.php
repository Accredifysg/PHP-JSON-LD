<?php

declare(strict_types=1);

use Accredify\JsonLd\Internal\IriResolver;

describe('IriResolver::resolve', function () {
    it('resolves RFC 3986 relative references against a base', function (string $ref, string $expected) {
        $base = 'http://example.com/some/deep/directory/and/file#with-a-fragment';
        expect(IriResolver::resolve($base, $ref))->toBe($expected);
    })->with([
        'simple segment' => ['link', 'http://example.com/some/deep/directory/and/link'],
        'fragment only' => ['#frag', 'http://example.com/some/deep/directory/and/file#frag'],
        'query only' => ['?q=1', 'http://example.com/some/deep/directory/and/file?q=1'],
        'dot slash' => ['./', 'http://example.com/some/deep/directory/and/'],
        'dot dot slash' => ['../', 'http://example.com/some/deep/directory/'],
        'parent segment' => ['../parent', 'http://example.com/some/deep/directory/parent'],
        'over-popped to root' => ['../../../../../still-root', 'http://example.com/still-root'],
        'absolute path' => ['/absolute', 'http://example.com/absolute'],
        'scheme-relative' => ['//example.org/x', 'http://example.org/x'],
        'scheme-relative dots' => ['//example.org/../x', 'http://example.org/x'],
    ]);

    it('returns an absolute reference unchanged (dot-normalised)', function () {
        expect(IriResolver::resolve('http://base.example/x', 'https://other.example/y'))
            ->toBe('https://other.example/y');
        expect(IriResolver::resolve('http://base.example/x', 'urn:uuid:1234'))
            ->toBe('urn:uuid:1234');
    });

    it('returns the reference unchanged when there is no usable base', function () {
        expect(IriResolver::resolve(null, 'relative'))->toBe('relative');
        expect(IriResolver::resolve('', 'relative'))->toBe('relative');
        expect(IriResolver::resolve('not-absolute', 'relative'))->toBe('relative');
    });

    it('handles an empty reference (returns base sans fragment)', function () {
        expect(IriResolver::resolve('http://example/base/', ''))
            ->toBe('http://example/base/');
    });
});
