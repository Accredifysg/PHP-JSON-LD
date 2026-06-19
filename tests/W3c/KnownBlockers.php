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
        '#t0122' => 'positive (non-normative): a keyword-form (@-) IRI as a node @id is dropped along with its node, but the fixture keeps the property with `{"@id": null}` (itself invalid JSON-LD per the test note). Low value.',
        '#t0128' => 'positive: a shared context referenced by a RELATIVE URL from two scoped contexts is rejected with "Remote context must be a valid URL" — relative @context-URL resolution against the document base is not implemented (same root as #tc031, NOT a circular-reference guard).',
        '#tc031' => 'positive: a relative @context URL is not resolved against the document base (RFC 3986) before loading, so the offline fixture cannot be served ("Remote context must be a valid URL").',
        '#tc032' => 'negative: an embedded context that is never used is not evaluated, so its "invalid scoped context" error is not raised (lazy scoped-context evaluation).',
        '#tc033' => 'negative: an unused context carrying an embedded-context error is not evaluated, so its error is not raised (lazy scoped-context evaluation).',
        '#ter56' => 'negative: defining a term named @context is not rejected as keyword redefinition. A naive throw conflates with a remote context DOCUMENT ({"@context": …}); the fix must unwrap remote contexts first (breaks #t0127/#te127 otherwise), so it is not a one-liner.',
        '#tin06' => 'positive: the json.api example expands an `id` alias used inside an `@nest` block to an `@id` ARRAY (`["…"]`) instead of a scalar — a bug in the @nest + keyword-alias expansion path.',
    ];

    /** @var array<string, string> toRdf-manifest id => reason */
    public const TO_RDF = [
        '#tc031' => 'positive: a relative @context URL is not resolved against the document base (RFC 3986) before loading ("Remote context must be a valid URL").',
        '#tc032' => 'negative: an embedded context that is never used is not evaluated, so its "invalid scoped context" error is not raised (lazy scoped-context evaluation).',
        '#tc033' => 'negative: an unused context carrying an embedded-context error is not evaluated, so its error is not raised (lazy scoped-context evaluation).',
        '#te128' => 'positive: a shared context referenced by a RELATIVE URL from two scoped contexts is rejected with "Remote context must be a valid URL" — relative @context-URL resolution is not implemented (toRdf counterpart of #t0128; same root as #tc031, NOT a circular-reference guard).',
        '#ter56' => 'negative: defining a term named @context is not rejected as keyword redefinition. The fix must first unwrap remote context DOCUMENTS ({"@context": …}) or it breaks #t0127/#te127, so it is not a one-liner.',
        '#tin06' => 'positive: the json.api example expands an `id` alias used inside an `@nest` block to an `@id` ARRAY instead of a scalar, so the N-Quads differ — a bug in the @nest + keyword-alias expansion path.',
        '#tjs10' => 'positive: JSON-literal structural canonicalization differs (PHP json_decode cannot distinguish {} from []).',
    ];

    /** @var array<string, string> flatten-manifest id => reason */
    public const FLATTEN = [
        '#tin06' => 'positive: the same upstream expansion bug as EXPAND/TO_RDF #tin06 — an `id` alias inside an `@nest` block expands to an `@id` ARRAY instead of a scalar.',
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
