<?php

declare(strict_types=1);

use Accredify\JsonLd\Algorithms\Expansion;
use Accredify\JsonLd\Algorithms\ToRdf;
use Accredify\JsonLd\Context\ContextProcessor;
use Accredify\JsonLd\Documents\RdfDataset;
use Accredify\JsonLd\Exceptions\DataLossException;
use Accredify\JsonLd\Exceptions\JsonLdException;
use Accredify\JsonLd\JsonLdOptions;
use Accredify\JsonLd\JsonLdProcessor;
use Accredify\JsonLd\Loaders\CachingDocumentLoader;
use Accredify\JsonLd\Tests\Context\Support\StubDocumentLoader;
use Accredify\JsonLd\Tests\Interop\Support\FixtureDocumentLoader;
use PHPUnit\Framework\AssertionFailedError;

/*
|--------------------------------------------------------------------------
| Safe mode (JsonLdOptions::$safe) — fail closed on silently dropped data
|--------------------------------------------------------------------------
| W3C VC-DATA-INTEGRITY 1.0 §2.4.3 "Securing Data Losslessly": processors
| feeding canonicalization MUST throw when data is dropped, "such as when an
| undefined term is detected in an input document". Every fixture asserts
| BOTH modes: the default stays silently lossy (backwards compatible, exactly
| what the W3C suites expect) while `safe: true` throws DataLossException
| carrying a jsonld.js-compatible event code.
*/

/**
 * Run $fn and assert it throws a DataLossException with $eventCode.
 */
function safeModeExpectDrop(callable $fn, string $eventCode): DataLossException
{
    try {
        $fn();
    } catch (DataLossException $e) {
        expect($e->eventCode)->toBe($eventCode);

        return $e;
    }

    throw new AssertionFailedError("Expected DataLossException with event code '{$eventCode}', but nothing was thrown");
}

function safeModeProcessor(): JsonLdProcessor
{
    return new JsonLdProcessor(new StubDocumentLoader);
}

/**
 * Fetch a nested value from expanded output; null when the path is absent.
 */
function safeModeDig(mixed $value, string|int ...$path): mixed
{
    foreach ($path as $key) {
        if (! is_array($value) || ! array_key_exists($key, $value)) {
            return null;
        }
        $value = $value[$key];
    }

    return $value;
}

/** JsonLdProcessor serving the vendored real-world contexts (VC v1/v2, …). */
function safeModeVcProcessor(): JsonLdProcessor
{
    return new JsonLdProcessor(new FixtureDocumentLoader);
}

function safeOptions(): JsonLdOptions
{
    return new JsonLdOptions(safe: true);
}

describe('signing-pipeline attack shapes (undefined-term canonicalization holes)', function () {
    it('throws on VC 1.x proof options whose terms are all undefined (empty-dataset canonicalization hole)', function () {
        // Under the VC 1.1 context, DataIntegrityProof is not a defined type,
        // so none of its type-scoped terms exist: every property silently
        // drops and the proof options canonicalize to the EMPTY dataset —
        // sha256('') — leaving proof metadata tamperable.
        $proofOptions = [
            '@context' => 'https://www.w3.org/2018/credentials/v1',
            'type' => 'DataIntegrityProof',
            'created' => '2023-03-01T21:29:24Z',
            'cryptosuite' => 'eddsa-rdfc-2022',
            'proofPurpose' => 'assertionMethod',
            'verificationMethod' => 'https://example.edu/issuers/565049#key-1',
        ];

        // Default: the total-drop case an empty-output guard can catch.
        expect(safeModeVcProcessor()->toRdf($proofOptions)->getQuads())->toBe([]);

        // Safe: fails closed at the first undefined term instead.
        $e = safeModeExpectDrop(
            fn () => safeModeVcProcessor()->toRdf($proofOptions, safeOptions()),
            'invalid property',
        );
        expect($e->details['term'])->toBe('created');
        expect($e->getMessage())->toBe("Safe mode: term 'created' does not expand to an absolute IRI or keyword (invalid property)");
    });

    it('throws on an undefined credentialSubject claim (partial drop an empty-output check can never catch)', function () {
        $credential = [
            '@context' => 'https://www.w3.org/2018/credentials/v1',
            'id' => 'urn:uuid:9f6878c8-73e1-4771-a938-9811d38983a1',
            'type' => 'VerifiableCredential',
            'issuer' => 'https://example.edu/issuers/565049',
            'issuanceDate' => '2023-03-01T21:29:24Z',
            'credentialSubject' => [
                'id' => 'did:example:ebfeb1f712ebc6f1c276e12ec21',
                'alumniOf' => 'Example University',
            ],
        ];

        // Default: the credential still yields quads — it signs and verifies
        // green — but the undefined claim is missing, so it stays
        // attacker-editable without invalidating the signature.
        $defaultQuads = safeModeVcProcessor()->toRdf($credential)->toNQuads();
        expect($defaultQuads)->not->toBe('');
        expect($defaultQuads)->not->toContain('alumniOf');

        $e = safeModeExpectDrop(
            fn () => safeModeVcProcessor()->toRdf($credential, safeOptions()),
            'invalid property',
        );
        expect($e->details['term'])->toBe('alumniOf');
    });

    it('throws on a proofPurpose value that is undefined under the VC 1.x context', function () {
        // Ed25519Signature2018's scoped context defines assertionMethod and
        // authentication as proofPurpose values; capabilityInvocation is not
        // defined, expands to a relative node reference, and its statement is
        // dropped at RDF deserialization — the purpose can be relabeled.
        $proofOptions = [
            '@context' => 'https://www.w3.org/2018/credentials/v1',
            'type' => 'Ed25519Signature2018',
            'created' => '2023-03-01T21:29:24Z',
            'proofPurpose' => 'capabilityInvocation',
            'verificationMethod' => 'https://example.edu/issuers/565049#key-1',
        ];

        // Default: other statements survive; the proofPurpose statement drops.
        $defaultQuads = safeModeVcProcessor()->toRdf($proofOptions)->toNQuads();
        expect($defaultQuads)->not->toBe('');
        expect($defaultQuads)->not->toContain('proofPurpose');

        safeModeExpectDrop(
            fn () => safeModeVcProcessor()->toRdf($proofOptions, safeOptions()),
            'relative object reference',
        );
    });

    it('accepts a fully-defined VC 1.x credential unchanged in safe mode (no false positive)', function () {
        $credential = [
            '@context' => 'https://www.w3.org/2018/credentials/v1',
            'id' => 'urn:uuid:9f6878c8-73e1-4771-a938-9811d38983a1',
            'type' => 'VerifiableCredential',
            'issuer' => 'https://example.edu/issuers/565049',
            'issuanceDate' => '2023-03-01T21:29:24Z',
            'credentialSubject' => [
                'id' => 'did:example:ebfeb1f712ebc6f1c276e12ec21',
            ],
        ];

        $default = safeModeVcProcessor()->toRdf($credential)->toNQuads();
        $safe = safeModeVcProcessor()->toRdf($credential, safeOptions())->toNQuads();

        expect($safe)->toBe($default);
        expect($safe)->not->toBe('');
    });

    it('accepts otherwise-undefined terms absorbed by an active @vocab (no drop, nothing to report)', function () {
        // With an in-scope @vocab an unknown term becomes an absolute IRI —
        // nothing is dropped, so safe mode stays quiet. (Draft-era VCDM 2.0
        // contexts shipped an "issuer-dependent" @vocab with this effect; the
        // published v2 context removed it, so undefined terms under VC 2.0
        // DO throw — see the next test.)
        $credential = [
            '@context' => ['@vocab' => 'http://example.com/vendor#'],
            '@id' => 'urn:uuid:9f6878c8-73e1-4771-a938-9811d38983a1',
            'someVendorTerm' => 'kept, not dropped',
        ];

        $safe = safeModeProcessor()->toRdf($credential, safeOptions())->toNQuads();

        expect($safe)->toContain('<http://example.com/vendor#someVendorTerm>');
    });

    it('throws on an undefined term under the published VC 2.0 context, which has no @vocab safety net', function () {
        $credential = [
            '@context' => 'https://www.w3.org/ns/credentials/v2',
            'id' => 'urn:uuid:9f6878c8-73e1-4771-a938-9811d38983a1',
            'type' => 'VerifiableCredential',
            'issuer' => 'https://example.edu/issuers/565049',
            'validFrom' => '2023-03-01T21:29:24Z',
            'credentialSubject' => [
                'id' => 'did:example:ebfeb1f712ebc6f1c276e12ec21',
                'someVendorTerm' => 'silently unprotected',
            ],
        ];

        // Default: the vendor claim vanishes from the signed bytes.
        $defaultQuads = safeModeVcProcessor()->toRdf($credential)->toNQuads();
        expect($defaultQuads)->not->toBe('');
        expect($defaultQuads)->not->toContain('someVendorTerm');

        $e = safeModeExpectDrop(
            fn () => safeModeVcProcessor()->toRdf($credential, safeOptions()),
            'invalid property',
        );
        expect($e->details['term'])->toBe('someVendorTerm');
    });
});

describe('expansion: undefined terms (§2.4.3 core)', function () {
    it('throws for a term with no definition and no @vocab', function () {
        $doc = ['@context' => ['id' => '@id'], 'id' => 'urn:x', 'alumniOf' => 'X'];

        // Default: the undefined term drops, which leaves an @id-only node
        // that is itself free-floating — the WHOLE document expands to [].
        expect(safeModeProcessor()->expand($doc)->toArray())->toBe([]);

        $e = safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'invalid property',
        );
        expect($e->details['term'])->toBe('alumniOf');
    });

    it('throws for a term explicitly mapped to null', function () {
        $doc = ['@context' => ['secret' => null], 'secret' => 'decoupled'];

        expect(safeModeProcessor()->expand($doc)->toArray())->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'invalid property',
        );
    });

    it('throws for a keyword-shaped unknown document key', function () {
        $doc = ['@context' => [], '@ignoreMe' => 'gone', '@id' => 'http://example.com/x'];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect($expanded[0] ?? [])->not->toHaveKey('@ignoreMe');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'invalid property',
        );
    });

    it('throws for a keyword that is meaningless as a node entry', function () {
        $doc = ['@context' => [], '@vocab' => 'http://example.com/', '@id' => 'http://example.com/x'];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect($expanded[0] ?? [])->not->toHaveKey('@vocab');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'invalid property',
        );
    });

    it('throws for frame keywords appearing outside a frame', function () {
        $doc = ['@context' => [], '@id' => 'http://example.com/x', '@default' => 'dropped'];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect($expanded[0] ?? [])->not->toHaveKey('@default');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'invalid property',
        );
    });

    it('throws for a non-string (numeric) property key, which jsonld.js would process', function () {
        // json_decode turns {"1": "x"} into an int key, which the property
        // loop can only drop.
        $doc = ['@context' => ['@vocab' => 'http://example.com/'], '@id' => 'http://example.com/x', '1' => 'lost'];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect($expanded[0] ?? [])->not->toHaveKey('http://example.com/1');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'invalid property',
        );
    });
});

describe('expansion: free-floating and value-object drops', function () {
    it('throws for a top-level value object', function () {
        $doc = ['@context' => [], '@value' => 'free-floating'];

        expect(safeModeProcessor()->expand($doc)->toArray())->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'object with only @value',
        );
    });

    it('throws for a top-level @list object', function () {
        $doc = ['@context' => [], '@list' => []];

        expect(safeModeProcessor()->expand($doc)->toArray())->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'object with only @list',
        );
    });

    it('throws for a top-level node carrying only @id', function () {
        $doc = ['@context' => [], '@id' => 'http://example.com/x'];

        expect(safeModeProcessor()->expand($doc)->toArray())->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'object with only @id',
        );
    });

    it('throws for a scalar directly inside @graph', function () {
        $doc = ['@context' => [], '@graph' => ['stray scalar']];

        expect(safeModeProcessor()->expand($doc)->toArray())->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'free-floating scalar',
        );
    });

    it('throws for a bare @language object with no @value', function () {
        $doc = [
            '@context' => ['label' => 'http://example.com/label'],
            '@id' => 'http://example.com/x',
            'label' => ['@language' => 'en'],
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect($expanded[0] ?? [])->not->toHaveKey('http://example.com/label');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'object with only @language',
        );
    });

    it('throws for @value: null', function () {
        $doc = [
            '@context' => ['label' => 'http://example.com/label'],
            '@id' => 'http://example.com/x',
            'label' => ['@value' => null],
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect($expanded[0] ?? [])->not->toHaveKey('http://example.com/label');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'null @value value',
        );
    });

    it('keeps @value: null for @json literals in safe mode (JSON null is real data)', function () {
        $doc = [
            '@context' => ['blob' => ['@id' => 'http://example.com/blob', '@type' => '@json']],
            '@id' => 'http://example.com/x',
            'blob' => null,
        ];

        // A @json-typed null is serialisable data, not a drop.
        $safe = safeModeProcessor()->toRdf($doc, safeOptions())->toNQuads();
        expect($safe)->toContain('"null"^^<http://www.w3.org/1999/02/22-rdf-syntax-ns#JSON>');
    });

    it('throws for a non-string @direction, which is dropped from the value object', function () {
        $doc = [
            '@context' => ['label' => 'http://example.com/label'],
            '@id' => 'http://example.com/x',
            'label' => ['@value' => 'x', '@direction' => 5],
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/label', 0))->not->toHaveKey('@direction');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'invalid @direction value',
        );
    });
});

describe('expansion: identity loss on @id', function () {
    it('throws for a keyword-shaped @id value (node silently becomes a blank node)', function () {
        $doc = ['@context' => [], '@id' => '@ignoreMe', 'http://example.com/p' => 'kept'];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect($expanded[0] ?? [])->not->toHaveKey('@id');

        $e = safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'reserved @id value',
        );
        expect($e->details['id'])->toBe('@ignoreMe');
    });

    it('throws for an @id that stays a relative IRI (no RDF statement can carry it)', function () {
        $doc = ['@context' => [], '@id' => 'relative-id', 'http://example.com/p' => 'kept'];

        // Default: expansion keeps the relative @id; toRdf then drops the
        // whole subject.
        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, '@id'))->toBe('relative-id');
        expect(safeModeProcessor()->toRdf($doc)->getQuads())->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'relative @id reference',
        );
    });

    it('does not throw for blank-node and absolute @id values', function () {
        $doc = [
            '@context' => [],
            '@id' => '_:b-local',
            'http://example.com/p' => [
                ['@id' => 'did:example:123'],
                ['@id' => 'urn:uuid:0a2e2e2e-90fd-4a30-80f4-a14a1dcf2a2e'],
            ],
        ];

        expect(safeModeProcessor()->expand($doc, safeOptions())->toArray())
            ->toBe(safeModeProcessor()->expand($doc)->toArray());
    });
});

describe('expansion: @type drops', function () {
    it('throws for a keyword-shaped @type value', function () {
        $doc = ['@context' => [], '@id' => 'http://example.com/x', '@type' => '@Bogus'];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, '@type') ?? [])->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'relative @type reference',
        );
    });

    it('throws in toRdf for a relative @type that survived expansion', function () {
        $doc = ['@context' => [], '@id' => 'http://example.com/x', '@type' => 'RelativeType'];

        // Default: expansion keeps the relative type, toRdf drops the
        // rdf:type statement.
        expect(safeModeProcessor()->toRdf($doc)->toNQuads())->not->toContain('RelativeType');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->toRdf($doc, safeOptions()),
            'relative @type reference',
        );
    });
});

describe('context processing: reserved definitions and relative @vocab', function () {
    it('throws when a context defines a keyword-shaped term', function () {
        $doc = ['@context' => ['@foo' => 'http://example.com/foo'], '@id' => 'http://example.com/x', 'http://example.com/p' => 'v'];

        // Default: the definition is created but keyword-shaped keys can
        // never be used, so any data under it is unreachable.
        expect(safeModeProcessor()->expand($doc)->toArray())->not->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'reserved term',
        );
    });

    it('throws when a term definition has a keyword-shaped @id', function () {
        $doc = ['@context' => ['term' => ['@id' => '@bar']], '@id' => 'http://example.com/x'];

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'reserved @id value',
        );
    });

    it('throws when a term definition has a keyword-shaped @reverse', function () {
        $doc = ['@context' => ['parent' => ['@reverse' => '@ignoreMe']], '@id' => 'http://example.com/x'];

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'reserved @reverse value',
        );
    });

    it('throws when @vocab does not resolve to an absolute IRI', function () {
        $doc = ['@context' => ['@vocab' => 'relative/vocab/'], '@id' => 'http://example.com/x', 'name' => 'lost'];

        // Default: every @vocab-mapped term expands relative and drops.
        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect($expanded[0] ?? [])->not->toHaveKey('relative/vocab/name');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'relative @vocab reference',
        );
    });

    it('accepts a relative @vocab that resolves against @base', function () {
        $doc = [
            '@context' => ['@base' => 'http://example.com/doc', '@vocab' => '#'],
            '@id' => 'http://example.com/x',
            'name' => 'kept',
        ];

        $safe = safeModeProcessor()->toRdf($doc, safeOptions())->toNQuads();
        expect($safe)->toContain('<http://example.com/doc#name>');
    });
});

describe('expansion: container maps', function () {
    it('throws for a numeric language-map key (PHP-only drop; jsonld.js keeps it)', function () {
        $doc = [
            '@context' => ['label' => ['@id' => 'http://example.com/label', '@container' => '@language']],
            '@id' => 'http://example.com/x',
            'label' => ['en' => 'hello', '1234' => 'lost'],
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/label'))->toHaveCount(1);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'invalid map key',
        );
    });

    it('throws for an @id-map key that does not expand (entry loses its identity)', function () {
        $doc = [
            '@context' => ['m' => ['@id' => 'http://example.com/m', '@container' => '@id']],
            '@id' => 'http://example.com/x',
            'm' => ['@bogus' => ['http://example.com/p' => 'v']],
        ];

        // Default: the entry survives as an anonymous node.
        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/m', 0))->not->toHaveKey('@id');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'reserved @id value',
        );
    });

    it('throws for a @type-map key that does not expand (entries lose their @type)', function () {
        $doc = [
            '@context' => ['m' => ['@id' => 'http://example.com/m', '@container' => '@type']],
            '@id' => 'http://example.com/x',
            'm' => ['@bogusType' => ['http://example.com/p' => 'v']],
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/m', 0))->not->toHaveKey('@type');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'relative @type reference',
        );
    });
});

describe('expansion: scoped-context machinery', function () {
    it('throws when a remote scoped context cannot be resolved (no document loader)', function () {
        $loader = new StubDocumentLoader;
        $contextProcessor = new ContextProcessor([
            '@context' => ['thing' => ['@id' => 'http://example.com/thing', '@context' => 'http://example.com/scoped']],
        ], $loader, safe: true);

        // No loader wired into Expansion: the scoped overlay silently
        // resolves to an empty term map, cascading undefined-term drops.
        $expansion = new Expansion($contextProcessor->getTermDefinitions(), documentLoader: null, safe: true);

        safeModeExpectDrop(
            fn () => $expansion->expand(['thing' => ['x' => 1]]),
            'context load failed',
        );
    });

    // Scoped @language/@direction entries are real behaviour now — see the
    // 'scoped contexts: default @language/@direction' describe. The
    // 'unsupported scoped context entry' event code is gone with them.

    it('throws when a scoped context carries a malformed term definition', function () {
        $doc = [
            '@context' => [
                'thing' => ['@id' => 'http://example.com/thing', '@context' => ['bad' => 42]],
            ],
            '@id' => 'http://example.com/x',
            'thing' => ['http://example.com/label' => 'v'],
        ];

        expect(safeModeProcessor()->expand($doc)->toArray())->not->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'invalid scoped term definition',
        );
    });
});

describe('toRdf: relative-IRI matrix and malformed literals', function () {
    it('throws for a relative subject', function () {
        $expanded = [['@id' => 'relative-subject', 'http://example.com/p' => [['@value' => 'v']]]];

        expect((new ToRdf)->toRdf($expanded))->toBe([]);

        safeModeExpectDrop(
            fn () => (new ToRdf(safe: true))->toRdf($expanded),
            'relative subject reference',
        );
    });

    it('throws for a relative predicate', function () {
        $expanded = [['@id' => 'http://example.com/s', 'rel-pred' => [['@value' => 'v']]]];

        expect((new ToRdf)->toRdf($expanded))->toBe([]);

        safeModeExpectDrop(
            fn () => (new ToRdf(safe: true))->toRdf($expanded),
            'relative predicate reference',
        );
    });

    it('throws for a relative object reference', function () {
        $expanded = [['@id' => 'http://example.com/s', 'http://example.com/p' => [['@id' => 'rel-obj']]]];

        expect((new ToRdf)->toRdf($expanded))->toBe([]);

        safeModeExpectDrop(
            fn () => (new ToRdf(safe: true))->toRdf($expanded),
            'relative object reference',
        );
    });

    it('throws for a relative named-graph holder (surfaced as its relative subject)', function () {
        // The graph's holder node also appears as a subject in the default
        // graph, so the subject check fires first; the graph-name check
        // remains as defence in depth.
        $expanded = [[
            '@id' => 'rel-graph',
            '@graph' => [['@id' => 'http://example.com/s', 'http://example.com/p' => [['@value' => 1]]]],
        ]];

        safeModeExpectDrop(
            fn () => (new ToRdf(safe: true))->toRdf($expanded),
            'relative subject reference',
        );
    });

    it('throws for a blank-node predicate unless produceGeneralizedRdf is set', function () {
        $expanded = [['@id' => 'http://example.com/s', '_:p' => [['@value' => 'v']]]];

        expect((new ToRdf)->toRdf($expanded))->toBe([]);

        safeModeExpectDrop(
            fn () => (new ToRdf(safe: true))->toRdf($expanded),
            'blank node predicate',
        );

        // Generalized RDF keeps the statement, so safe mode has no drop to
        // report (jsonld.js parity).
        $generalized = new ToRdf(produceGeneralizedRdf: true, safe: true);
        expect($generalized->toRdf($expanded))->toHaveCount(1);
    });

    it('throws for a malformed BCP47 language tag, whose whole statement is dropped', function () {
        $doc = [
            '@context' => [],
            '@id' => 'http://example.com/s',
            'http://example.com/p' => ['@value' => 'hello', '@language' => 'en gb'],
        ];

        expect(safeModeProcessor()->toRdf($doc)->getQuads())->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->toRdf($doc, safeOptions()),
            'invalid @language value',
        );
    });

    it('throws for a non-scalar @value instead of coercing it to the empty string', function () {
        $expanded = [['@id' => 'http://example.com/s', 'http://example.com/p' => [['@value' => ['not' => 'scalar']]]]];

        // Default: not even a drop — corruption. The literal becomes "".
        $default = new RdfDataset((new ToRdf)->toRdf($expanded));
        expect($default->toNQuads())->toContain('""');

        safeModeExpectDrop(
            fn () => (new ToRdf(safe: true))->toRdf($expanded),
            'invalid @value serialization',
        );
    });

    it('throws for a list member that would leave a hole (rdf:rest without rdf:first)', function () {
        $expanded = [['@id' => 'http://example.com/s', 'http://example.com/p' => [['@list' => [['@id' => 'rel-item']]]]]];

        // Default: the collection scaffolding is emitted but the member is
        // silently missing.
        $default = new RdfDataset((new ToRdf)->toRdf($expanded));
        expect($default->toNQuads())->toContain('rdf-syntax-ns#rest');
        expect($default->toNQuads())->not->toContain('rdf-syntax-ns#first');

        safeModeExpectDrop(
            fn () => (new ToRdf(safe: true))->toRdf($expanded),
            'relative object reference',
        );
    });

    it('throws when @direction is present but the rdfDirection option is not set', function () {
        $expanded = [['@id' => 'http://example.com/s', 'http://example.com/p' => [['@value' => 'x', '@language' => 'ar', '@direction' => 'rtl']]]];

        // Default: the literal is emitted without its base direction.
        $default = new RdfDataset((new ToRdf)->toRdf($expanded));
        expect($default->toNQuads())->toContain('"x"@ar');

        safeModeExpectDrop(
            fn () => (new ToRdf(safe: true))->toRdf($expanded),
            'rdfDirection not set',
        );

        // With an rdfDirection mode the direction is representable: no drop.
        $i18n = new ToRdf(rdfDirection: 'i18n-datatype', safe: true);
        $quads = new RdfDataset($i18n->toRdf($expanded));
        expect($quads->toNQuads())->toContain('https://www.w3.org/ns/i18n#ar_rtl');
    });

    it('throws for a node whose @id is not a string (relabelled as a blank node)', function () {
        $expanded = [['@id' => 12345, 'http://example.com/p' => [['@value' => 'v']]]];

        // Default: identity silently replaced with a fresh blank node.
        $default = new RdfDataset((new ToRdf)->toRdf($expanded));
        expect($default->toNQuads())->toContain('_:b0');

        safeModeExpectDrop(
            fn () => (new ToRdf(safe: true))->toRdf($expanded),
            'invalid @id value',
        );
    });
});

describe('safe mode is threaded through every entry point', function () {
    it('flatten() honours safe mode', function () {
        $doc = [
            '@context' => [],
            '@id' => 'http://example.com/x',
            'http://example.com/p' => 'kept',
            'undefinedTerm' => 'lost',
        ];

        expect(safeModeProcessor()->flatten($doc)->toArray())->not->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->flatten($doc, null, safeOptions()),
            'invalid property',
        );
    });

    it('compact() honours safe mode on its expansion side', function () {
        $doc = ['@context' => [], '@id' => 'http://example.com/x', 'undefinedTerm' => 'lost'];

        safeModeExpectDrop(
            fn () => safeModeProcessor()->compact($doc, [], safeOptions()),
            'invalid property',
        );
    });

    it('frame() honours safe mode on its document expansion', function () {
        $doc = ['@context' => [], '@id' => 'http://example.com/x', 'undefinedTerm' => 'lost'];

        safeModeExpectDrop(
            fn () => safeModeProcessor()->frame($doc, ['@context' => []], safeOptions()),
            'invalid property',
        );
    });

    it('expand() with expandContext still honours safe mode', function () {
        $doc = ['@id' => 'http://example.com/x', 'name' => 'lost'];
        $options = new JsonLdOptions(expandContext: ['known' => 'http://example.com/known'], safe: true);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, $options),
            'invalid property',
        );
    });
});

describe('caching must not swallow safe-mode errors (jsonld.js replay lesson)', function () {
    it('still throws when the offending remote context is served from the document cache', function () {
        $inner = new StubDocumentLoader;
        $inner->add('http://example.com/ctx', ['@context' => ['@foo' => 'http://example.com/foo']]);
        $caching = new CachingDocumentLoader($inner);
        $processor = new JsonLdProcessor($caching);

        $doc = ['@context' => 'http://example.com/ctx', '@id' => 'http://example.com/x'];

        safeModeExpectDrop(fn () => $processor->expand($doc, safeOptions()), 'reserved term');
        // Second pass: the remote document comes from the cache; the
        // term-definition validation must still re-run and throw. The cache
        // stores PRE-processing documents, which is the invariant this test
        // pins down for any future processed-context cache.
        safeModeExpectDrop(fn () => $processor->expand($doc, safeOptions()), 'reserved term');

        expect($inner->requestedUrls)->toBe(['http://example.com/ctx']);
    });

    it('lets the same processor serve default-mode calls after a safe-mode failure', function () {
        $inner = new StubDocumentLoader;
        $inner->add('http://example.com/ctx', ['@context' => ['@foo' => 'http://example.com/foo']]);
        $processor = new JsonLdProcessor(new CachingDocumentLoader($inner));

        $doc = ['@context' => 'http://example.com/ctx', '@id' => 'http://example.com/x', 'http://example.com/p' => 'v'];

        safeModeExpectDrop(fn () => $processor->expand($doc, safeOptions()), 'reserved term');

        // Safe mode is per-call, not sticky processor state.
        expect($processor->expand($doc)->toArray())->not->toBe([]);
    });
});

describe('toRdf: direction-tagged literal integrity', function () {
    it('canonicalizes boolean and numeric @value under @direction instead of corrupting them with a raw string cast', function () {
        $expanded = fn (mixed $value) => [[
            '@id' => 'http://example.com/s',
            'http://example.com/p' => [['@value' => $value, '@direction' => 'rtl']],
        ]];
        $toRdf = new ToRdf(rdfDirection: 'i18n-datatype', safe: true);

        // false must not become "" (and true not "1"): canonical xsd forms.
        expect((new RdfDataset($toRdf->toRdf($expanded(false))))->toNQuads())
            ->toContain('"false"^^<https://www.w3.org/ns/i18n#_rtl>');
        expect((new RdfDataset($toRdf->toRdf($expanded(true))))->toNQuads())
            ->toContain('"true"^^<https://www.w3.org/ns/i18n#_rtl>');
        expect((new RdfDataset($toRdf->toRdf($expanded(0.5))))->toNQuads())
            ->toContain('"5.0E-1"^^<https://www.w3.org/ns/i18n#_rtl>');
    });

    it('throws for a malformed BCP47 tag under @direction instead of emitting an unparseable i18n IRI', function () {
        $expanded = [[
            '@id' => 'http://example.com/s',
            'http://example.com/p' => [['@value' => 'v', '@language' => 'en gb', '@direction' => 'ltr']],
        ]];

        // Default: the statement is dropped (as without @direction), never an
        // IRIREF containing a raw space.
        $default = new RdfDataset((new ToRdf(rdfDirection: 'i18n-datatype'))->toRdf($expanded));
        expect($default->toNQuads())->not->toContain('en gb');

        safeModeExpectDrop(
            fn () => (new ToRdf(rdfDirection: 'i18n-datatype', safe: true))->toRdf($expanded),
            'invalid @language value',
        );
    });

    it('rejects an unrecognised rdfDirection value up front instead of silently dropping directions', function () {
        expect(fn () => new ToRdf(rdfDirection: 'bogus'))
            ->toThrow(JsonLdException::class, 'Invalid rdfDirection value');

        $doc = ['@context' => [], '@id' => 'http://example.com/s', 'http://example.com/p' => ['@value' => 'x', '@direction' => 'rtl']];
        expect(fn () => safeModeProcessor()->toRdf($doc, new JsonLdOptions(rdfDirection: 'i18n-datatype ')))
            ->toThrow(JsonLdException::class);
    });
});

describe('toRdf: node-map identifier edge cases', function () {
    it('treats an all-numeric @id as a relative reference instead of crashing with a TypeError', function () {
        $expanded = [['@id' => '123', 'http://example.com/p' => [['@value' => 'v']]]];

        // Default: dropped like any other relative subject — no crash.
        expect((new ToRdf)->toRdf($expanded))->toBe([]);

        safeModeExpectDrop(
            fn () => (new ToRdf(safe: true))->toRdf($expanded),
            'relative subject reference',
        );

        // Through the public pipeline (default mode) it must not crash either.
        $doc = ['@context' => [], '@id' => '123', 'http://example.com/p' => 'v'];
        expect(safeModeProcessor()->toRdf($doc)->getQuads())->toBe([]);
    });

    it('throws for a keyword-shaped non-keyword property in the node map', function () {
        $expanded = [['@id' => 'http://example.com/s', '@bogusProp' => [['@value' => 'v']]]];

        expect((new ToRdf)->toRdf($expanded))->toBe([]);

        safeModeExpectDrop(
            fn () => (new ToRdf(safe: true))->toRdf($expanded),
            'invalid property',
        );
    });
});

describe('flatten() and fromRdf() carry safe mode through every stage', function () {
    it('flatten() fails closed on a node-map drop, exactly like toRdf() on the same document', function () {
        $doc = [
            '@context' => [],
            '@id' => 'http://example.com/g',
            '@graph' => [['@value' => 'orphan']],
            'http://example.com/p' => 'v',
        ];

        // Default: the unattachable value object silently vanishes.
        $flattened = safeModeProcessor()->flatten($doc)->toArray();
        expect(json_encode($flattened))->not->toContain('orphan');

        // Safe: the SAME drop that toRdf reports must throw on flatten too.
        safeModeExpectDrop(
            fn () => safeModeProcessor()->flatten($doc, null, safeOptions()),
            'object with only @value',
        );
        safeModeExpectDrop(
            fn () => safeModeProcessor()->toRdf($doc, safeOptions()),
            'object with only @value',
        );
    });

    it('fromRdf() honours safe mode for a malformed language tag in the RDF input', function () {
        $nquads = '<http://example.com/s> <http://example.com/p> "x"@abcdefghijklm .'."\n";

        // Default: the tag flows through verbatim.
        $default = safeModeProcessor()->fromRdf($nquads)->toArray();
        expect(safeModeDig($default, 0, 'http://example.com/p', 0, '@language'))->toBe('abcdefghijklm');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->fromRdf($nquads, safeOptions()),
            'invalid @language value',
        );
    });
});

describe('expansion: §5.5 step 18 under @graph', function () {
    it('drops an @id-only node directly inside @graph (default) and throws in safe mode', function () {
        $doc = [
            '@context' => [],
            '@id' => 'http://example.com/g',
            '@graph' => [['@id' => 'http://example.com/x']],
            'http://example.com/p' => 'v',
        ];

        // Default: the reference is dropped at expansion (spec/jsonld.js
        // behaviour) instead of silently vanishing later at toRdf/flatten.
        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, '@graph'))->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'object with only @id',
        );
    });

    it('drops a value object directly inside a named graph (default) and throws at expand in safe mode', function () {
        // Previously only NodeMap caught this shape, so an expand-only safe
        // pipeline handed the orphan to an external canonicalizer that then
        // silently lost it.
        $doc = [
            '@context' => [],
            '@id' => 'http://example.com/g',
            '@graph' => [['@value' => 'orphan']],
            'http://example.com/p' => 'v',
        ];

        // Default: jsonld.js parity — the graph node survives with an empty
        // @graph; only the free-floating member is dropped.
        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect($expanded)->toBe([[
            '@graph' => [],
            '@id' => 'http://example.com/g',
            'http://example.com/p' => [['@value' => 'v']],
        ]]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'object with only @value',
        );
    });

    it('drops a value object carrying @language or @index inside @graph — @value presence is what counts', function () {
        foreach ([
            ['@value' => 'x', '@language' => 'en'],
            ['@value' => 'x', '@index' => 'i'],
        ] as $member) {
            $doc = ['@id' => 'http://example.com/g', '@graph' => [$member], 'http://example.com/p' => 'v'];

            $expanded = safeModeProcessor()->expand($doc)->toArray();
            expect(safeModeDig($expanded, 0, '@graph'))->toBe([]);

            safeModeExpectDrop(
                fn () => safeModeProcessor()->expand($doc, safeOptions()),
                'object with only @value',
            );
        }
    });

    it('drops a @list object directly inside @graph, taking node objects inside the list with it', function () {
        // Mirrors #t0047 at the named-graph level: everything inside a
        // free-floating list is removed with the list, even nodes with
        // properties that would survive on their own. The member node keeps
        // the item-level drops quiet so the safe throw pins the LIST site.
        $doc = [
            '@id' => 'http://example.com/g',
            '@graph' => [['@list' => [['@id' => 'http://example.com/n', 'http://example.com/q' => 'inside']]]],
            'http://example.com/p' => 'v',
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, '@graph'))->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'object with only @list',
        );
    });

    it('reports the item-level drop first for a free-floating @list whose members are themselves free-floating', function () {
        // jsonld.js parity: list members expand before the list object is
        // judged, so a scalar member surfaces as 'free-floating scalar' and a
        // value-object member as 'object with only @value'. Default mode ends
        // at the same place either way: the whole list vanishes.
        foreach ([
            ['x', 'free-floating scalar'],
            [['@value' => 'x'], 'object with only @value'],
        ] as [$member, $eventCode]) {
            $doc = ['@id' => 'http://example.com/g', '@graph' => [['@list' => [$member]]], 'http://example.com/p' => 'v'];

            $expanded = safeModeProcessor()->expand($doc)->toArray();
            expect(safeModeDig($expanded, 0, '@graph'))->toBe([]);

            safeModeExpectDrop(
                fn () => safeModeProcessor()->expand($doc, safeOptions()),
                $eventCode,
            );
        }
    });

    it('drops a @list object with @index inside @graph — the @index does not keep it alive', function () {
        $doc = [
            '@id' => 'http://example.com/g',
            '@graph' => [['@list' => [['@id' => 'http://example.com/n', 'http://example.com/q' => 'inside']], '@index' => 'i']],
            'http://example.com/p' => 'v',
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, '@graph'))->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'object with only @list',
        );
    });

    it('drops a value object reached through @set directly inside @graph', function () {
        // @set unwraps in place, so its members sit directly in the graph and
        // are judged with the graph's active property (jsonld.js parity).
        $doc = [
            '@id' => 'http://example.com/g',
            '@graph' => [['@set' => [['@value' => 'x']]]],
            'http://example.com/p' => 'v',
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, '@graph'))->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'object with only @value',
        );
    });

    it('drops free-floating members of a graph nested inside another graph', function () {
        $doc = [
            '@id' => 'http://example.com/outer',
            '@graph' => [[
                '@id' => 'http://example.com/inner',
                '@graph' => [['@value' => 'x']],
                'http://example.com/p' => 'v',
            ]],
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, '@graph', 0, '@graph'))->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'object with only @value',
        );
    });

    it('keeps the graph node itself when every @graph member is dropped', function () {
        // jsonld.js parity: the named graph is not re-judged as free-floating
        // after its members drop — @graph:[] plus @id is still two entries.
        $doc = ['@id' => 'http://example.com/g', '@graph' => [['@value' => 'x']]];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect($expanded)->toBe([['@graph' => [], '@id' => 'http://example.com/g']]);
    });

    it('keeps value objects and lists under real properties of graph members', function () {
        $doc = [
            '@id' => 'http://example.com/g',
            '@graph' => [[
                '@id' => 'http://example.com/n',
                'http://example.com/q' => ['@value' => 'kept'],
                'http://example.com/r' => ['@list' => ['kept too']],
            ]],
            'http://example.com/p' => 'v',
        ];

        foreach ([null, safeOptions()] as $options) {
            $expanded = safeModeProcessor()->expand($doc, $options)->toArray();
            expect(safeModeDig($expanded, 0, '@graph', 0, 'http://example.com/q', 0, '@value'))->toBe('kept')
                ->and(safeModeDig($expanded, 0, '@graph', 0, 'http://example.com/r', 0, '@list', 0, '@value'))->toBe('kept too');
        }
    });

    it('keeps free-floating value patterns when expanding a frame', function () {
        // A frame legitimately places {@value: ...} match patterns where a
        // document cannot place data; frame expansion must not drop them.
        $contextProcessor = new ContextProcessor(['@context' => []], new StubDocumentLoader);
        $expansion = new Expansion($contextProcessor->getTermDefinitions(), documentLoader: null, frameExpansion: true);

        $frame = ['@id' => 'http://example.com/g', '@graph' => [['@value' => []]]];

        expect(safeModeDig($expansion->expand($frame), 0, '@graph', 0))->toBe(['@value' => []]);
    });
});

describe('expansion: free-floating values under @graph containers (§5.5 step 18 extended, jsonld.js parity)', function () {
    // jsonld.js drops free-floating shapes under @container:@graph terms —
    // beyond the literal spec (step 18 names only active property null /
    // @graph), but semantically sound: a graph wrapper whose members carry no
    // statement would otherwise emit a dangling graph-name quad fabricated
    // from noise. This is byte-relevant for toRdf, unlike the named-graph
    // drops, where NodeMap already converged the output.
    $ctxPlain = ['input' => ['@id' => 'http://example.com/input', '@container' => '@graph']];
    $ctxIndex = ['input' => ['@id' => 'http://example.com/input', '@container' => ['@graph', '@index']]];
    $ctxId = ['input' => ['@id' => 'http://example.com/input', '@container' => ['@graph', '@id']]];

    it('drops a scalar or value object under a plain @graph container, cascading to the parent', function () use ($ctxPlain) {
        foreach (['x', ['@value' => 'x']] as $orphan) {
            $doc = ['@context' => $ctxPlain, '@id' => 'http://example.com/s', 'input' => $orphan];

            // The property is omitted, the parent becomes @id-only and drops
            // too: the whole document expands to nothing (jsonld.js parity).
            expect(safeModeProcessor()->expand($doc)->toArray())->toBe([])
                ->and(safeModeProcessor()->toRdf($doc)->toNQuads())->toBe('');

            safeModeExpectDrop(
                fn () => safeModeProcessor()->expand($doc, safeOptions()),
                'object with only @value',
            );
        }
    });

    it('drops an @id-only reference and a @list object under a plain @graph container', function () use ($ctxPlain) {
        foreach ([
            [['@id' => 'http://example.com/n'], 'object with only @id'],
            [['@list' => ['x']], 'object with only @list'],
        ] as [$orphan, $eventCode]) {
            $doc = ['@context' => $ctxPlain, '@id' => 'http://example.com/s', 'input' => $orphan];

            expect(safeModeProcessor()->expand($doc)->toArray())->toBe([]);

            safeModeExpectDrop(
                fn () => safeModeProcessor()->expand($doc, safeOptions()),
                $eventCode,
            );
        }
    });

    it('no longer emits a dangling empty-graph quad for mixed values — only real members survive', function () use ($ctxPlain) {
        $doc = [
            '@context' => $ctxPlain,
            '@id' => 'http://example.com/s',
            'input' => [['@value' => 'x'], ['http://example.com/q' => 'v']],
        ];

        // Default: the orphan is filtered, the node is wrapped — and the RDF
        // output carries exactly the two real quads, byte-identical to
        // jsonld.js (previously an extra `<s> <input> _:bN .` appeared).
        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/input'))->toHaveCount(1);

        $nquads = safeModeProcessor()->toRdf($doc)->toNQuads();
        expect(substr_count($nquads, "\n"))->toBe(2)
            ->and($nquads)->toContain('"v"');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'object with only @value',
        );
    });

    it('keeps real nodes under @graph containers untouched, in both modes', function () use ($ctxPlain) {
        $doc = ['@context' => $ctxPlain, '@id' => 'http://example.com/s', 'input' => ['http://example.com/q' => 'v']];

        foreach ([null, safeOptions()] as $options) {
            $expanded = safeModeProcessor()->expand($doc, $options)->toArray();
            expect(safeModeDig($expanded, 0, 'http://example.com/input', 0, '@graph', 0, 'http://example.com/q', 0, '@value'))->toBe('v');
        }
    });

    it('drops the JSON literal of a term combining @type: @json with a @graph container', function () {
        // A JSON literal is a value object; the graph-container wrap filters
        // it like any other free-floating member, so such a term loses every
        // value (jsonld.js parity — its wrap filter does the same).
        $doc = [
            '@context' => ['input' => ['@id' => 'http://example.com/input', '@container' => '@graph', '@type' => '@json']],
            '@id' => 'http://example.com/s',
            'input' => ['any' => 'json'],
        ];

        expect(safeModeProcessor()->expand($doc)->toArray())->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'object with only @value',
        );
    });

    it('drops free-floating OBJECT members of [@graph, @index] and [@graph, @id] maps, keeping the property as an empty list', function () use ($ctxIndex, $ctxId) {
        foreach ([
            [$ctxIndex, ['i1' => ['@value' => 'x']], 'object with only @value'],
            [$ctxIndex, ['i2' => ['@id' => 'http://example.com/n']], 'object with only @id'],
            [$ctxId, ['http://example.com/g' => ['@value' => 'x']], 'object with only @value'],
            [$ctxId, ['http://example.com/g' => ['@id' => 'http://example.com/n']], 'object with only @id'],
        ] as [$ctx, $map, $eventCode]) {
            $doc = ['@context' => $ctx, '@id' => 'http://example.com/s', 'input' => $map];

            // jsonld.js parity: unlike the plain container, the map form keeps
            // the property with an empty list when every member drops.
            $expanded = safeModeProcessor()->expand($doc)->toArray();
            expect(safeModeDig($expanded, 0, 'http://example.com/input'))->toBe([])
                ->and(safeModeProcessor()->toRdf($doc)->toNQuads())->toBe('');

            safeModeExpectDrop(
                fn () => safeModeProcessor()->expand($doc, safeOptions()),
                $eventCode,
            );
        }
    });

    it('keeps raw SCALAR members of graph maps as wrapped value objects — the jsonld.js asymmetry, byte-for-byte', function () use ($ctxIndex, $ctxId) {
        // jsonld.js's member-level drop only sees objects; scalars are built
        // into value objects by VALUE expansion afterwards and survive to be
        // wrapped, so its toRdf emits a graph-name quad naming an empty graph.
        // Byte parity means emitting the same quad in default mode.
        $indexDoc = ['@context' => $ctxIndex, '@id' => 'http://example.com/s', 'input' => ['i1' => 'x']];
        $expanded = safeModeProcessor()->expand($indexDoc)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/input', 0, '@graph', 0, '@value'))->toBe('x')
            ->and(safeModeDig($expanded, 0, 'http://example.com/input', 0, '@index'))->toBe('i1');
        expect(safeModeProcessor()->toRdf($indexDoc)->toNQuads())
            ->toBe("<http://example.com/s> <http://example.com/input> _:b0 .\n");

        $idDoc = ['@context' => $ctxId, '@id' => 'http://example.com/s', 'input' => ['http://example.com/g1' => 'x']];
        expect(safeModeProcessor()->toRdf($idDoc)->toNQuads())
            ->toBe("<http://example.com/s> <http://example.com/input> <http://example.com/g1> .\n");

        // Safe expansion accepts them (jsonld.js parity) …
        expect(safeModeProcessor()->expand($indexDoc, safeOptions())->toArray())->not->toBe([]);

        // … but safe toRdf still fails closed on the value the node map then
        // silently discards — DELIBERATELY stricter than jsonld.js, whose
        // safe mode emits the dangling quad without any event.
        safeModeExpectDrop(
            fn () => safeModeProcessor()->toRdf($indexDoc, safeOptions()),
            'object with only @value',
        );
    });

    it('keeps the scalar and drops the object in a mixed graph-map entry', function () use ($ctxIndex) {
        $doc = [
            '@context' => $ctxIndex,
            '@id' => 'http://example.com/s',
            'input' => ['i6' => ['x', ['@id' => 'http://example.com/n']]],
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/input'))->toHaveCount(1)
            ->and(safeModeDig($expanded, 0, 'http://example.com/input', 0, '@graph', 0, '@value'))->toBe('x');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'object with only @id',
        );
    });

    it('omits the property for an empty plain-container value but keeps an empty graph map', function () use ($ctxPlain, $ctxIndex) {
        // Plain container + empty value: key omitted, parent drops — jsonld.js
        // parity for its raw-[] flavour. (PHP cannot distinguish a raw {} from
        // [], where jsonld.js would say 'empty object'; both fail closed.)
        $plain = ['@context' => $ctxPlain, '@id' => 'http://example.com/s', 'input' => []];
        expect(safeModeProcessor()->expand($plain)->toArray())->toBe([]);
        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($plain, safeOptions()),
            'object with only @id',
        );

        // Map container + empty value: property kept as an empty list.
        $map = ['@context' => $ctxIndex, '@id' => 'http://example.com/s', 'input' => []];
        foreach ([null, safeOptions()] as $options) {
            $expanded = safeModeProcessor()->expand($map, $options)->toArray();
            expect(safeModeDig($expanded, 0, 'http://example.com/input'))->toBe([]);
        }
    });

    it('keeps free-floating patterns under @graph containers when expanding a frame', function () {
        $contextProcessor = new ContextProcessor(
            ['@context' => ['input' => ['@id' => 'http://example.com/input', '@container' => '@graph']]],
            new StubDocumentLoader,
        );
        $expansion = new Expansion($contextProcessor->getTermDefinitions(), documentLoader: null, frameExpansion: true);

        $frame = ['@id' => 'http://example.com/s', 'input' => ['@value' => []]];

        // The wrap still happens; the free-floating filter does not.
        expect(safeModeDig($expansion->expand($frame), 0, 'http://example.com/input', 0, '@graph', 0))->toBe(['@value' => []]);
    });
});

describe('expansion: relative identifiers that only fail later', function () {
    it('throws at safe expansion for a relative @type, which no external canonicalizer could reject', function () {
        $doc = ['@context' => [], '@id' => 'http://example.com/x', '@type' => 'RelativeType'];

        // Default: expansion keeps the relative type verbatim (spec).
        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, '@type'))->toBe(['RelativeType']);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'relative @type reference',
        );
    });

    it('throws for an @id-container map key that expands to a relative IRI, like the direct @id branch', function () {
        $doc = [
            '@context' => ['m' => ['@id' => 'http://example.com/m', '@container' => '@id']],
            '@id' => 'http://example.com/x',
            'm' => ['relative-key' => ['http://example.com/p' => 'v']],
        ];

        // Default: the relative identity survives expansion (spec).
        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/m', 0, '@id'))->toBe('relative-key');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'relative @id reference',
        );
    });
});

describe('expansion: BCP47 well-formedness (jsonld.js safe-expansion parity)', function () {
    it('throws at safe expansion for a malformed @language tag', function () {
        $doc = [
            '@context' => [],
            '@id' => 'http://example.com/x',
            'http://example.com/p' => ['@value' => 'x', '@language' => 'en gb'],
        ];

        // Default: kept at expansion (dropped only at toRdf).
        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/p', 0, '@language'))->toBe('en gb');

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'invalid @language value',
        );
    });

    it('throws at safe expansion for a malformed language-map key', function () {
        $doc = [
            '@context' => ['label' => ['@id' => 'http://example.com/label', '@container' => '@language']],
            '@id' => 'http://example.com/x',
            'label' => ['en gb' => 'hello'],
        ];

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'invalid @language value',
        );
    });
});

describe('framing: safe mode must not reject legitimate frame patterns', function () {
    it('frames with an @language match pattern under safe mode', function () {
        $doc = [
            '@context' => ['label' => 'http://example.com/label'],
            '@id' => 'http://example.com/x',
            'label' => ['@value' => 'hi', '@language' => 'en'],
        ];
        $frame = ['@context' => ['label' => 'http://example.com/label'], 'label' => ['@language' => 'en']];

        $framed = safeModeProcessor()->frame($doc, $frame, safeOptions())->toArray();
        expect(json_encode($framed))->toContain('hi');
    });

    it('frames with a non-string @direction pattern and a top-level wildcard under safe mode', function () {
        $doc = [
            '@context' => ['label' => 'http://example.com/label'],
            '@id' => 'http://example.com/x',
            'label' => 'hi',
        ];
        $frame = ['@context' => ['label' => 'http://example.com/label'], 'label' => ['@value' => [], '@direction' => []]];

        // Must not abort with a DataLossException on the frame's own patterns.
        $framed = safeModeProcessor()->frame($doc, $frame, safeOptions())->toArray();
        expect($framed)->toBeArray();
    });
});

describe('scoped contexts: parity with the document-level write path', function () {
    it('accepts the standard scoped @language: null reset in safe mode', function () {
        $doc = [
            '@context' => [
                '@language' => 'en',
                'thing' => ['@id' => 'http://example.com/thing', '@context' => ['@language' => null]],
            ],
            '@id' => 'http://example.com/x',
            'thing' => ['http://example.com/label' => 'plain'],
        ];

        $safe = safeModeProcessor()->expand($doc, safeOptions())->toArray();
        expect($safe)->toBe(safeModeProcessor()->expand($doc)->toArray());
        expect(safeModeDig($safe, 0, 'http://example.com/thing', 0, 'http://example.com/label', 0))
            ->not->toHaveKey('@language');
    });

    it('throws the root-cause relative @vocab error for a scoped relative @vocab', function () {
        $doc = [
            '@context' => ['t' => ['@id' => 'http://example.com/t', '@context' => ['@vocab' => 'rel/']]],
            '@id' => 'http://example.com/x',
            't' => ['name' => 'v'],
        ];

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'relative @vocab reference',
        );
    });

    it('resolves a scoped relative @vocab against the active base, like the document level', function () {
        $doc = [
            '@context' => [
                '@base' => 'http://example.com/doc/',
                't' => ['@id' => 'http://example.com/t', '@context' => ['@vocab' => '#']],
            ],
            '@id' => 'http://example.com/x',
            't' => ['name' => 'kept'],
        ];

        $safe = safeModeProcessor()->toRdf($doc, safeOptions())->toNQuads();
        expect($safe)->toContain('<http://example.com/doc/#name>');
    });

    it('throws reserved term for a keyword-shaped term in an INLINE scoped context, like a remote one', function () {
        $doc = [
            '@context' => ['thing' => ['@id' => 'http://example.com/thing', '@context' => ['@foo' => 'http://example.com/f']]],
            '@id' => 'http://example.com/x',
            'thing' => ['http://example.com/p' => 'v'],
        ];

        expect(safeModeProcessor()->expand($doc)->toArray())->not->toBe([]);

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'reserved term',
        );
    });

    it('throws reserved @id value for a keyword-shaped @id in an inline scoped context', function () {
        $doc = [
            '@context' => ['thing' => ['@id' => 'http://example.com/thing', '@context' => ['bad' => ['@id' => '@keywordish']]]],
            '@id' => 'http://example.com/x',
            'thing' => ['http://example.com/p' => 'v'],
        ];

        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'reserved @id value',
        );
    });
});

describe('scoped contexts: default @language/@direction (inherit, set, reset)', function () {
    // jsonld.js (the byte-parity reference for signing pipelines) carries the
    // default @language into every scoped-context activation via its
    // active-context clone, so plain strings in scope keep their tag; scoped
    // @language/@direction entries set or (null) reset the default exactly
    // like the document-level entries. Previously any scoped activation
    // silently shed the parent's default — "hello" instead of "hello"@en —
    // different N-Quads, different canonical hashes.

    it('inherits the default @language into a property-scoped context — value tagged, toRdf byte parity', function () {
        $doc = [
            '@context' => ['@language' => 'en', 'thing' => ['@id' => 'http://example.com/thing', '@context' => ['other' => 'http://example.com/other']]],
            '@id' => 'http://example.com/x',
            'thing' => ['http://example.com/label' => 'hello'],
        ];

        foreach ([null, safeOptions()] as $options) {
            $expanded = safeModeProcessor()->expand($doc, $options)->toArray();
            expect(safeModeDig($expanded, 0, 'http://example.com/thing', 0, 'http://example.com/label', 0, '@language'))->toBe('en');

            expect(safeModeProcessor()->toRdf($doc, $options)->toNQuads())->toContain('"hello"@en');
        }
    });

    it('inherits the default @language into type-scoped and embedded node contexts', function () {
        $typeScoped = [
            '@context' => ['@language' => 'en', 'Thing' => ['@id' => 'http://example.com/Thing', '@context' => ['other' => 'http://example.com/other']]],
            '@id' => 'http://example.com/x', '@type' => 'Thing', 'http://example.com/label' => 'hello',
        ];
        $expanded = safeModeProcessor()->expand($typeScoped)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/label', 0, '@language'))->toBe('en');

        $embedded = [
            '@context' => ['@language' => 'en', 'nested' => 'http://example.com/nested'],
            '@id' => 'http://example.com/x',
            'nested' => ['@context' => ['other' => 'http://example.com/other'], 'http://example.com/label' => 'hello'],
        ];
        $expanded = safeModeProcessor()->expand($embedded)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/nested', 0, 'http://example.com/label', 0, '@language'))->toBe('en');
    });

    it('applies a scoped @language override inside the scope only, without a safe-mode throw', function () {
        $doc = [
            '@context' => ['@language' => 'en', 'thing' => ['@id' => 'http://example.com/thing', '@context' => ['@language' => 'fr']]],
            '@id' => 'http://example.com/x',
            'http://example.com/out' => 'outside',
            'thing' => ['http://example.com/label' => 'inside'],
        ];

        foreach ([null, safeOptions()] as $options) {
            $expanded = safeModeProcessor()->expand($doc, $options)->toArray();
            expect(safeModeDig($expanded, 0, 'http://example.com/out', 0, '@language'))->toBe('en')
                ->and(safeModeDig($expanded, 0, 'http://example.com/thing', 0, 'http://example.com/label', 0, '@language'))->toBe('fr');
        }
    });

    it('applies a scoped @direction set and null reset', function () {
        $set = [
            '@context' => ['@direction' => 'rtl', 'thing' => ['@id' => 'http://example.com/thing', '@context' => ['@direction' => 'ltr']]],
            '@id' => 'http://example.com/x', 'thing' => ['http://example.com/label' => 'hello'],
        ];
        $expanded = safeModeProcessor()->expand($set)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/thing', 0, 'http://example.com/label', 0, '@direction'))->toBe('ltr');

        $reset = [
            '@context' => ['@direction' => 'rtl', 'thing' => ['@id' => 'http://example.com/thing', '@context' => ['@direction' => null]]],
            '@id' => 'http://example.com/x', 'thing' => ['http://example.com/label' => 'hello'],
        ];
        $expanded = safeModeProcessor()->expand($reset)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/thing', 0, 'http://example.com/label', 0))->toBe(['@value' => 'hello']);
    });

    it('does NOT inherit the default @direction into a scope — jsonld.js parity over spec purity', function () {
        // jsonld.js's _cloneActiveContext copies @base/@vocab/@language but
        // omits @direction (an upstream deviation from §4.1 context copying,
        // reported as https://github.com/digitalbazaar/jsonld.js/issues/586);
        // matching the reference implementation's N-Quads wins for signing
        // pipelines. Revisit when upstream fixes the clone.
        $doc = [
            '@context' => ['@direction' => 'rtl', 'thing' => ['@id' => 'http://example.com/thing', '@context' => ['other' => 'http://example.com/other']]],
            '@id' => 'http://example.com/x',
            'http://example.com/out' => 'outside',
            'thing' => ['http://example.com/label' => 'hello'],
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/out', 0, '@direction'))->toBe('rtl')
            ->and(safeModeDig($expanded, 0, 'http://example.com/thing', 0, 'http://example.com/label', 0))->toBe(['@value' => 'hello']);
    });

    it('keeps the innermost override through chained scopes', function () {
        $doc = [
            '@context' => ['@language' => 'en',
                'a' => ['@id' => 'http://example.com/a', '@context' => ['@language' => 'fr', 'b' => ['@id' => 'http://example.com/b', '@context' => ['other' => 'http://example.com/other']]]]],
            '@id' => 'http://example.com/x',
            'a' => ['b' => ['http://example.com/label' => 'hello']],
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/a', 0, 'http://example.com/b', 0, 'http://example.com/label', 0, '@language'))->toBe('fr');
    });

    it('clears inherited defaults on a scoped @context: null reset', function () {
        $doc = [
            '@context' => ['@language' => 'en', 'thing' => ['@id' => 'http://example.com/thing', '@context' => null]],
            '@id' => 'http://example.com/x',
            'http://example.com/out' => 'outside',
            'thing' => ['http://example.com/label' => 'inside'],
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/out', 0, '@language'))->toBe('en')
            ->and(safeModeDig($expanded, 0, 'http://example.com/thing', 0, 'http://example.com/label', 0))->toBe(['@value' => 'inside']);
    });

    it('lets a term-level @language mapping win over the inherited default', function () {
        $doc = [
            '@context' => ['@language' => 'en',
                'thing' => ['@id' => 'http://example.com/thing', '@context' => ['label' => ['@id' => 'http://example.com/label', '@language' => 'de']]]],
            '@id' => 'http://example.com/x',
            'thing' => ['label' => 'hallo'],
        ];

        $expanded = safeModeProcessor()->expand($doc)->toArray();
        expect(safeModeDig($expanded, 0, 'http://example.com/thing', 0, 'http://example.com/label', 0, '@language'))->toBe('de');
    });

    it('applies a remote scoped context @language: set, explicit null reset, and absent (inherit)', function () {
        $loader = new StubDocumentLoader;
        $loader->add('http://example.com/remote-fr', ['@context' => ['@language' => 'fr', 'other' => 'http://example.com/other']]);
        $loader->add('http://example.com/remote-null', ['@context' => ['@language' => null, 'other' => 'http://example.com/other']]);
        $loader->add('http://example.com/remote-absent', ['@context' => ['other' => 'http://example.com/other']]);
        $processor = new JsonLdProcessor($loader);

        $docFor = fn (string $remote) => [
            '@context' => ['@language' => 'en', 'thing' => ['@id' => 'http://example.com/thing', '@context' => $remote]],
            '@id' => 'http://example.com/x',
            'thing' => ['http://example.com/label' => 'inside'],
        ];
        $langOf = fn (array $expanded) => safeModeDig($expanded, 0, 'http://example.com/thing', 0, 'http://example.com/label', 0, '@language');

        foreach ([null, safeOptions()] as $options) {
            expect($langOf($processor->expand($docFor('http://example.com/remote-fr'), $options)->toArray()))->toBe('fr')
                ->and($langOf($processor->expand($docFor('http://example.com/remote-null'), $options)->toArray()))->toBeNull()
                ->and($langOf($processor->expand($docFor('http://example.com/remote-absent'), $options)->toArray()))->toBe('en');
        }
    });

    it('rejects an invalid scoped @language or @direction in BOTH modes, like the document level', function () {
        $badLanguage = [
            '@context' => ['thing' => ['@id' => 'http://example.com/thing', '@context' => ['@language' => 123]]],
            '@id' => 'http://example.com/x', 'thing' => ['http://example.com/label' => 'v'],
        ];
        $badDirection = [
            '@context' => ['thing' => ['@id' => 'http://example.com/thing', '@context' => ['@direction' => 'foo']]],
            '@id' => 'http://example.com/x', 'thing' => ['http://example.com/label' => 'v'],
        ];

        foreach ([null, safeOptions()] as $options) {
            foreach ([[$badLanguage, 'Invalid @language value'], [$badDirection, 'Invalid @direction value']] as [$doc, $message]) {
                try {
                    safeModeProcessor()->expand($doc, $options);
                    throw new AssertionFailedError("Expected a JsonLdException containing '{$message}'");
                } catch (JsonLdException $e) {
                    expect($e)->not->toBeInstanceOf(DataLossException::class);
                    expect($e->getMessage())->toContain($message);
                }
            }
        }
    });
});

describe('@included and @null: spec errors are not mislabelled as data loss', function () {
    it('reports a stray @included value as the Invalid @included value spec error in BOTH modes', function () {
        $doc = ['@context' => [], '@id' => 'http://example.com/s', '@included' => 'stray'];

        foreach ([null, safeOptions()] as $options) {
            try {
                safeModeProcessor()->expand($doc, $options);
                throw new AssertionFailedError('Expected a JsonLdException for the invalid @included value');
            } catch (JsonLdException $e) {
                expect($e)->not->toBeInstanceOf(DataLossException::class);
                expect($e->getMessage())->toContain('Invalid @included value');
            }
        }
    });

    it('keeps valid @included node objects in safe mode', function () {
        $doc = [
            '@context' => [],
            '@id' => 'http://example.com/s',
            'http://example.com/p' => 'v',
            '@included' => [['@id' => 'http://example.com/t', 'http://example.com/q' => 'w']],
        ];

        $safe = safeModeProcessor()->expand($doc, safeOptions())->toArray();
        expect(safeModeDig($safe, 0, '@included', 0, '@id'))->toBe('http://example.com/t');
    });

    it('treats @null as a reserved (framing-output) token, not a JSON-LD keyword', function () {
        // As a term name: safe mode fails closed like any keyword-shaped term.
        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand(
                ['@context' => ['@null' => 'http://example.com/null'], '@id' => 'http://example.com/x'],
                safeOptions(),
            ),
            'reserved term',
        );

        // As an @id mapping: it must not pass as a keyword alias.
        safeModeExpectDrop(
            fn () => safeModeProcessor()->expand(
                ['@context' => ['x' => ['@id' => '@null']], '@id' => 'http://example.com/x'],
                safeOptions(),
            ),
            'reserved @id value',
        );
    });
});

describe('JsonLdOptions plumbing', function () {
    it('defaults safe to false and round-trips through with()', function () {
        $options = new JsonLdOptions;
        expect($options->safe)->toBeFalse();

        $safe = $options->with(safe: true);
        expect($safe->safe)->toBeTrue();
        expect($options->safe)->toBeFalse();

        // Unrelated with() calls preserve the flag.
        expect($safe->with(base: 'http://example.com/')->safe)->toBeTrue();
    });

    it('exposes the event code and details on the exception', function () {
        $doc = ['@context' => [], '@id' => 'http://example.com/x', 'alumniOf' => 'X'];

        $e = safeModeExpectDrop(
            fn () => safeModeProcessor()->expand($doc, safeOptions()),
            'invalid property',
        );

        expect($e)->toBeInstanceOf(DataLossException::class);
        expect($e->details)->toBe(['term' => 'alumniOf', 'expanded' => 'alumniOf']);
        expect($e->getMessage())->toContain('Safe mode:');
        expect($e->getMessage())->toContain('(invalid property)');
    });
});
