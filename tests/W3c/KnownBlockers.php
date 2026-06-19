<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\W3c;

/**
 * The W3C conformance tests we knowingly do not pass, as an explicit
 * expected-failure (xfail) allowlist keyed by manifest id.
 *
 * The harness treats these as expected: a listed test that still fails is
 * SKIPPED (keeping the suite green), but a listed test that starts PASSING
 * fails the suite — so the list cannot silently rot, and any *unlisted*
 * regression fails the suite as normal.
 *
 * These are residual environment / spec-accommodation limits and a few minor
 * validation gaps, not core capability gaps. Each entry says why we fail it.
 * Remove an entry the moment its test conforms.
 */
final class KnownBlockers
{
    /** @var array<string, string> expand-manifest id => reason */
    public const EXPAND = [
        '#t0122' => 'positive: a property whose IRI has keyword (@-) form is emitted rather than dropped (spec MUST-ignores such IRIs).',
        '#t0128' => 'positive: two scoped contexts sharing one referenced context trip our offline circular-reference guard.',
        '#tc031' => 'positive: a relative @context URL (RFC 3986-resolved) points outside the offline W3C fixture base, so the test loader cannot serve it.',
        '#tc032' => 'negative: an embedded context that is never used is not evaluated, so its error is not raised.',
        '#tc033' => 'negative: an unused context carrying an embedded-context error is not evaluated, so its error is not raised.',
        '#ter56' => 'negative: redefining the @context keyword is not rejected.',
        '#tin06' => 'positive: the json.api @included-blocks example expands @id/@type in a different shape.',
    ];

    /** @var array<string, string> toRdf-manifest id => reason */
    public const TO_RDF = [
        '#tc031' => 'positive: a relative @context URL (RFC 3986-resolved) points outside the offline W3C fixture base, so the test loader cannot serve it.',
        '#tc032' => 'negative: an embedded context that is never used is not evaluated, so its error is not raised.',
        '#tc033' => 'negative: an unused context carrying an embedded-context error is not evaluated, so its error is not raised.',
        '#te128' => 'positive: two scoped contexts sharing one referenced context trip our offline circular-reference guard (toRdf counterpart of expand #t0128).',
        '#ter56' => 'negative: redefining the @context keyword is not rejected.',
        '#tin06' => 'positive: the json.api @included-blocks example serialises to a different N-Quads set.',
        '#tjs10' => 'positive: JSON-literal structural canonicalization differs (PHP json_decode cannot distinguish {} from []).',
    ];

    /** @var array<string, string> flatten-manifest id => reason */
    public const FLATTEN = [
        '#tin06' => 'positive: the json.api @included-blocks example expands @id/@type in a different shape (same upstream expansion blocker as EXPAND/TO_RDF #tin06).',
    ];

    /** @var array<string, string> fromRdf-manifest id => reason */
    public const FROM_RDF = [
        '#t0008' => 'positive (json-ld-1.0): list-of-lists uses the 1.0 (non-recursive) conversion shape; single-level list conversion is fully supported.',
        '#tli03' => 'positive: nested list-of-lists conversion requires inner lists to collapse before the outer chain consumes their heads; single-level lists are fully supported.',
        '#tdi11' => 'positive (non-normative): rdfDirection=compound-literal blank-node folding is not implemented.',
        '#tdi12' => 'positive (non-normative): rdfDirection=compound-literal blank-node folding is not implemented.',
    ];

    /** @var array<string, string> frame-manifest id => reason */
    public const FRAME = [
        '#t0010' => 'positive: compaction safe-mode rejects "dcterms:creator" as an IRI confused with the declared "dcterms" prefix (jsonld errors here too); a pre-existing compaction strictness, not a framing gap.',
        '#t0045' => 'positive: a value-pattern keeps the matched value verbatim with @language "R", but the fixture lower-cases it to "r"; expansion intentionally preserves @language case (the toRdf bytes VC signs depend on it).',
        '#t0059' => 'positive: @embed:@last (the legacy 1.0 last-embed-wins mode) is not implemented; the default @once is.',
    ];
}
