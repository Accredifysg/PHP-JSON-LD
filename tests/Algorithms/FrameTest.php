<?php

declare(strict_types=1);

use Accredify\JsonLd\Algorithms\Expansion;
use Accredify\JsonLd\JsonLdOptions;
use Accredify\JsonLd\JsonLdProcessor;
use Accredify\JsonLd\Tests\Context\Support\StubDocumentLoader;

/*
|--------------------------------------------------------------------------
| Unit smoke tests for the framing algorithm
|--------------------------------------------------------------------------
| Full conformance lives in tests/W3c/Algorithms/FrameTest.php (the W3C
| json-ld-framing suite). These pin the core shapes — @type/@id matching,
| embedding (@once default vs @never), @explicit, @default injection, and
| @graph wrapping (omitGraph) — against the public frame() API.
|
| A frame's `{}` wildcard cannot be written as a PHP array (it would be the
| same as []), so where a wildcard is needed it is passed as the documented
| Expansion::FRAME_WILDCARD sentinel, exactly as the W3C harness decodes one.
*/

/**
 * @param  array<string, mixed>  $document
 * @param  array<string, mixed>  $frame
 * @return array<string, mixed>
 */
function frameDoc(array $document, array $frame, ?JsonLdOptions $options = null): array
{
    return (new JsonLdProcessor(new StubDocumentLoader))
        ->frame($document, $frame, $options)
        ->toArray();
}

$VOCAB = ['@context' => ['@vocab' => 'http://ex/']];

it('matches by @type and embeds referenced nodes once', function () use ($VOCAB) {
    $input = $VOCAB + [
        '@id' => 'http://ex/lib',
        '@type' => 'Library',
        'contains' => [
            '@id' => 'http://ex/book',
            '@type' => 'Book',
            'title' => 'The Republic',
        ],
    ];
    $frame = $VOCAB + ['@type' => 'Library'];

    expect(frameDoc($input, $frame))->toEqual($VOCAB + [
        '@id' => 'http://ex/lib',
        '@type' => 'Library',
        'contains' => [
            '@id' => 'http://ex/book',
            '@type' => 'Book',
            'title' => 'The Republic',
        ],
    ]);
});

it('drops unframed properties when @explicit is set', function () use ($VOCAB) {
    $input = $VOCAB + [
        '@id' => 'http://ex/s',
        '@type' => 'Thing',
        'keep' => 'yes',
        'drop' => 'no',
    ];
    // The framed property must carry a (wildcard) sub-frame to be kept.
    $frame = $VOCAB + [
        '@type' => 'Thing',
        'keep' => [Expansion::FRAME_WILDCARD => true],
    ];

    $result = frameDoc($input, $frame, new JsonLdOptions(explicit: true));

    expect($result)->toHaveKey('keep')
        ->and($result)->not->toHaveKey('drop');
});

it('injects an explicit default for a missing property', function () use ($VOCAB) {
    $input = $VOCAB + ['@id' => 'http://ex/s', '@type' => 'Thing'];
    $frame = $VOCAB + [
        '@type' => 'Thing',
        'label' => ['@default' => 'untitled'],
    ];

    expect(frameDoc($input, $frame)['label'])->toBe('untitled');
});

it('emits a bare reference when @embed is @never', function () use ($VOCAB) {
    $input = $VOCAB + [
        '@id' => 'http://ex/lib',
        '@type' => 'Library',
        'contains' => ['@id' => 'http://ex/book', '@type' => 'Book', 'title' => 'T'],
    ];
    $frame = $VOCAB + ['@type' => 'Library'];

    $result = frameDoc($input, $frame, new JsonLdOptions(embed: '@never'));

    expect($result['contains'])->toEqual(['@id' => 'http://ex/book']);
});

it('selects a single subject by @id', function () use ($VOCAB) {
    $input = $VOCAB + ['@graph' => [
        ['@id' => 'http://ex/a', 'http://ex/p' => 'A'],
        ['@id' => 'http://ex/b', 'http://ex/p' => 'B'],
    ]];
    $frame = $VOCAB + ['@id' => 'http://ex/b'];

    $result = frameDoc($input, $frame);

    expect($result['@id'])->toBe('http://ex/b')
        ->and($result['p'])->toBe('B');
});

it('wraps results in @graph when omitGraph is false', function () use ($VOCAB) {
    $input = $VOCAB + ['@id' => 'http://ex/s', '@type' => 'Thing'];
    $frame = $VOCAB + ['@type' => 'Thing'];

    $result = frameDoc($input, $frame, new JsonLdOptions(omitGraph: false));

    expect($result)->toHaveKey('@graph')
        ->and($result['@graph'])->toEqual([['@id' => 'http://ex/s', '@type' => 'Thing']]);
});
