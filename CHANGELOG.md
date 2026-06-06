# Changelog

All notable changes to `accredifysg/php-json-ld` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.13.0] - 2026-06-06

Adds the **toRdf** algorithm (§7) — the third v1.0 pillar — and fixes the
expansion gaps it surfaced.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.12.0:      177       95        0      272
v0.13.0:      191       95      234      520   (+14 expand, +234 toRdf)
```

### Added

- **Deserialize JSON-LD to RDF (§7).** A new `JsonLdProcessor::toRdf()`
  returning an `RdfDataset` value object that serialises to canonical
  N-Quads. Implements Node Map Generation (§7.2), Object to RDF Conversion
  (§7.3), and List Conversion (§7.4):
  - IRI / blank-node / literal terms (`src/Rdf/RdfTerm`, `RdfQuad`).
  - Deterministic blank-node identifiers (`_:b0`, `_:b1`, …) via a shared
    issuer threaded through node-map generation and list conversion.
  - Canonical literal forms: `xsd:integer`, `xsd:double` (e.g. `5.3E0`),
    `xsd:boolean`; explicit datatypes; language tags; `@list` → RDF
    collections; named graphs in the fourth quad position.
  - A missing `@context` is tolerated (documents may address predicates
    with full IRIs).
  - Basic `@json` literal support (sorted-key JSON; full JCS — Unicode
    normalisation, ECMAScript number formatting — is not yet implemented).
  - Not yet handled: `rdfDirection`, `produceGeneralizedRdf`.

### Fixed

These expansion fixes were surfaced by the toRdf work and lift both the
expand and toRdf scores:

- **`@list` / `@set` contents now expand under the active property**, so
  scalar list items value-expand instead of being dropped (they were
  expanded with a null active property). Items are expanded individually so
  a `@container: @list` term doesn't double-wrap them.
- **Compact IRIs whose prefix comes from a term's `@id`** (e.g. a term
  mapping to `"rdfs:label"`) are now fully expanded via their prefix rather
  than being returned as a pseudo-absolute IRI.
- **Top-level `@graph` unwrap**: a result that is a single map containing
  only `@graph` is replaced by the `@graph` contents (the default graph),
  instead of becoming a free-floating named graph.
- **Embedded `@context` inside nested objects** (inline term maps) is now
  processed before the object's properties are expanded, regardless of key
  order. (Remote string contexts nested in a document are still not
  resolved.)
- **`@included`** is now expanded (it was silently dropped).
- **Aliased `@nest`** (a term mapping to `@nest`) is now treated as a nest
  container, merging its values as direct properties.

### Consumer impact

Additive. Expansion of the VC/OBv3/IDVC characterization fixtures remains
byte-identical, the unit suite stays green (158 tests), and compaction is
unchanged. VC stays pinned at `^0.1.1`. The new `toRdf()` is available for a
future VC migration off its hand-rolled `JsonLdToQuadsProcessor`.

## [0.12.0] - 2026-06-06

Drops unmapped relative terms during expansion (§5.5 step 13). Found by
the VC drop-in corpus check.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   total
v0.11.0:      175       95       270
v0.12.0:      177       95       272   (+2 expand)
```

### Fixed

- **Unmapped relative property keys are now dropped (§5.5 step 13).**
  A property key that, after IRI expansion, is neither a JSON-LD keyword
  nor contains a colon is an *unmapped relative term*. The spec requires
  dropping it — previously it was retained and emitted with a bare
  relative predicate (e.g. `"relativeProp": [...]`). Relative predicates
  are not valid RDF and would silently corrupt downstream toRdf /
  canonicalization output.

### Consumer impact

This is the principal interop fix surfaced by the VC drop-in corpus
check: VC's RDFC-10 path does not validate predicate IRIs, so a leaked
relative predicate would have produced relative-predicate quads and
caused cross-implementation signature verification to fail. With this
fix, expansion no longer emits them.

Expansion of the VC/OBv3/IDVC **characterization fixtures is
byte-identical** to v0.11.0 (those documents contain no unmapped
relative terms), and compaction is unchanged. VC stays pinned at
`^0.1.1`.

Note on `@type`: relative `@type` *values* are intentionally **not**
dropped during expansion — IRI expansion returns relative values as-is,
and they are filtered later at the toRdf stage (a relative IRI is not a
valid `rdf:type` object). This asymmetry (drop relative property keys,
keep relative `@type` values) matches the spec and jsonld.js.

## [0.11.0] - 2026-05-13

Adds `@direction` and default `@language` to value expansion.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   total
v0.10.0:      170       95       265
v0.11.0:      175       95       270   (+5 expand)
```

### Added

- **Context-level default `@language` / `@direction`.** A context's
  top-level `@language` / `@direction` are stored on the active context
  and applied to plain string values during value expansion.
- **Per-term `@language` / `@direction` coercion**, which overrides the
  default. An explicit `null` mapping on a term suppresses the default
  entirely (the value carries no language/direction). Non-string values
  never receive language/direction tags.
- `@direction` context validation (`ltr` / `rtl` / null).

### Consumer impact

None. Expansion of the VC/OBv3/IDVC characterization fixtures is
byte-identical to v0.10.0 (those contexts don't shift plain-string
output under this change), and compaction is unchanged. VC stays pinned
at `^0.1.1`.

## [0.10.0] - 2026-05-13

Adds container-map compaction (`@language` / `@index` / `@id` / `@type`).

W3C JSON-LD 1.1 test suite:

```
            expand   compact   total
v0.9.0:       170       72       242
v0.10.0:      170       95       265   (+23 compact)
```

### Added

- **Container-map compaction (§5.6).** A property whose term has
  `@container: @language | @index | @id | @type` now compacts its
  expanded array into a *map* instead of a list:
  - `@language` → `{ lang: value | [values] }` (the bare `@value` per
    language; repeated languages arrayify, e.g. `de: ["…", "…"]`).
  - `@index` → `{ index: node }` with `@index` stripped from each entry.
  - `@id` → `{ compactedId: node }` with `@id` stripped.
  - `@type` → `{ compactedFirstType: node }` with the first `@type`
    removed (remaining types kept).
  All four route through a shared `mapContainerType` lookup +
  `compactContainerMap` builder.

### Deferred

- `@graph` container maps (`@graph+@index`, `@graph+@id`) — graph
  framing during compaction is a separate feature.

### Consumer impact

None. Expansion output unchanged (170, no regression); characterization
fixtures byte-identical to v0.9.0. VC stays pinned at `^0.1.1`.

## [0.9.0] - 2026-05-13

Allows compact-IRI / IRI-like term keys in a context — a single shared
fix that lifts both expansion and compaction.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   total
v0.8.0:       163       67       230
v0.9.0:       170       72       242   (+7 expand, +5 compact)
```

### Changed

- `TermDefinitions::validateTermSyntax` no longer rejects terms containing
  `:` or `/`. A context may legitimately define a compact-IRI term
  (`"ex:date": {...}`, `"rdfs:subClassOf": ...`) to attach `@type`/`@container`
  coercion to a compact-IRI property; the IRI-expansion algorithm already
  resolves these, so storing them is correct. Only JSON-LD keywords remain
  rejected as term keys. This shared context-layer fix flips ~7 expansion
  and ~5 compaction tests.

### Investigated and deliberately NOT changed (measured net-negative or no-op)

A sequencing analysis proposed several other "cheap" fixes; each was
measured in isolation against the W3C suite and rejected:

- **Value-object `@type`-as-array** — *no such bug*. Value objects already
  emit `@type` as a scalar string; "fixing" it is a no-op.
- **Tolerating a missing `@context`** (expand context-less docs instead of
  throwing) — measured **−17** on the suite. It removes ~17 negative-test
  passes (documents that the suite expects to error, which currently throw
  "Missing @context"). Reverted. Revisit only alongside real
  JsonLdErrorCode detection.
- **Accepting array `@container`** (`["@graph", "@id"]`) — gated: the ~23
  affected tests then need `@graph`/`@id`/`@type` *map expansion* (medium
  behavioral work), so the validation relaxation alone yields ~0 and risks
  the negative-test trap. Deferred to the container-map PR.
- **`@direction` / default `@language`** — needs context-level
  language/direction defaults threaded through value expansion; a medium
  Value-Expansion enhancement, not a cheap fix.

### Consumer impact

None. Characterization fixtures byte-identical to v0.8.0. VC stays pinned
at `^0.1.1`.

## [0.8.0] - 2026-05-13

Adds a first-pass **Compaction** algorithm (§5.6) — the first algorithm
beyond expansion. This is the release that unblocks compaction-dependent
consumers (e.g. VC's ecdsa-sd-2023 selective-disclosure path, which calls
`$processor->compact()`).

W3C JSON-LD 1.1 test suite:

```
            expand   compact
v0.7.0:       163        —    (compaction not implemented; 713 skipped)
v0.8.0:       163       67    (compaction live; 467 toRdf still skipped)
```

Expansion is unchanged (163, no regression).

### Added

- `Accredify\JsonLd\Algorithms\Compaction` — IRI compaction (§5.7),
  value compaction (§5.9), `@list` / `@set` container coercion, and the
  core compaction recursion (§5.6). The inverse context keys on
  fully-expanded IRIs, so terms whose `@id` is itself a compact IRI
  (`ex:term1`) resolve correctly.
- `Accredify\JsonLd\Documents\CompactedDocument` — read-only wrapper for
  compacted output (mirrors `ExpandedDocument`).
- `Processor::compact()` / `JsonLdProcessor::compact(array $expanded,
  array|string $context)` — compacts an expanded document against a
  context and prepends that context to the result.

### Deferred

- Container *map* forms (`@language` / `@index` / `@id` / `@type` /
  `@graph` maps), `@reverse`, and scoped contexts during compaction.
- `compactArrays` / `ordered` options beyond the defaults.

### Consumer impact

None. `compact()` is purely additive — expansion output is byte-identical
to v0.7.0 (characterization fixtures unchanged). VC stays pinned at
`^0.1.1`; when it later adopts `^0.8`, its `SkolemizationFunctions`
compaction call can migrate off the in-repo processor.

## [0.7.0] - 2026-05-13

Adds `@base` and document-relative IRI resolution (RFC 3986 §5).

W3C JSON-LD 1.1 expand test suite progress:

```
v0.1.1 baseline:  69 passed
v0.2.0:          113 passed   (+44)  Expansion rewrite
v0.3.0:          129 passed   (+16)  Container handling
v0.4.0:          126 passed   (-3)   Scope activation
v0.5.0:          139 passed   (+13)  Value objects + @json
v0.6.0:          147 passed   (+8)   @reverse
v0.7.0:          163 passed   (+16)  @base + relative IRIs
```

### Added

- **RFC 3986 IRI resolution** (`Accredify\JsonLd\Internal\IriResolver`):
  full Transform-References + Remove-Dot-Segments + Merge-Paths.
- **Document-relative IRI expansion.** Relative `@id` and `@type` values
  resolve against the active base IRI. `@type` expansion now runs in both
  vocab and document-relative modes (§5.5): `@vocab` wins if set, else the
  value resolves against `@base`.
- **`@base` in contexts.** A context `@base` overrides the document base;
  relative `@base` resolves against the current base; `@base: null` resets it.
- **`Processor::expand()` / `JsonLdProcessor::expand()` gain an optional
  `?string $base` parameter** — the initial base IRI (typically the
  document URL). Backward-compatible: existing single-argument calls are
  unaffected (VC's calls keep working).

### Changed

- `@base` context validation relaxed to accept any string (relative,
  compact, empty) or null, now that the value is resolved. `@vocab` keeps
  the stricter absolute-IRI check pending its own resolution PR.

### Consumer impact

None. The characterization fixtures are byte-identical to v0.6.0 — VC's
documents carry absolute `@id`s, so document-relative resolution is a
no-op for them. The new `$base` parameter is optional. VC stays pinned
at `^0.1.1`.

## [0.6.0] - 2026-05-13

Adds `@reverse` expansion (both forms) with reverse-value error detection.

W3C JSON-LD 1.1 expand test suite progress:

```
v0.1.1 baseline:  69 passed
v0.2.0:          113 passed   (+44)  Expansion rewrite
v0.3.0:          129 passed   (+16)  Container handling
v0.4.0:          126 passed   (-3)   Scope activation
v0.5.0:          139 passed   (+13)  Value objects + @json
v0.6.0:          147 passed   (+8)   @reverse
```

### Added

- **`@reverse` keyword.** `{"@reverse": {prop: node, …}}` expands its map
  and folds the entries into the node's `@reverse` map. A nested
  `@reverse` (double reverse) folds back into forward properties.
- **Reverse-property terms.** A term defined as `{"@reverse": "…iri"}`
  routes its values into the `@reverse` map under the reverse IRI.
- **Reverse-value validation.** Reverse properties may only reference
  nodes; a `@value` or `@list` reverse value raises
  `invalid reverse property value`.

### Consumer impact

None. Characterization fixtures are byte-identical to v0.5.0; VC stays
pinned at `^0.1.1`.

## [0.5.0] - 2026-05-13

Spec-correct value objects + `@json` literals, with value-object error
detection. This is the first PR where validation tightening and the
matching semantics land together, so the W3C count moves up for
legitimate reasons rather than via accidental over-throwing.

W3C JSON-LD 1.1 expand test suite progress:

```
v0.1.1 baseline:  69 passed
v0.2.0:          113 passed   (+44)  Expansion rewrite
v0.3.0:          129 passed   (+16)  Container handling
v0.4.0:          126 passed   (-3)   Scope activation
v0.5.0:          139 passed   (+13)  Value objects + @json
```

### Added

- **Value-object finalisation (§5.5 step 15).** A value object (one with
  `@value`) is validated and normalised:
  - Unexpected sibling keys → `invalid value object` error.
  - `@type` cannot coexist with `@language` / `@direction` → error.
  - `@value: null` drops the value object.
  - A language-tagged value requires a string `@value` → error otherwise.
  - Non-`@json` value objects require a scalar `@value` → error otherwise.
- **`@json` typed literals.** A term coerced with `@type: @json` preserves
  its value verbatim (scalar, array, or object) as
  `{@value: …, @type: @json}`.
- **Bare `{@language}` / `{@direction}` objects are dropped.** A value
  object with a language/direction tag but no `@value` is a free-floating
  tag with nothing to attach to, and is removed during expansion (matches
  W3C `#t0008`).

### Why the count went up (and v0.4.0's went down)

v0.4.0 dipped because tightening scope removed accidental negative-test
passes without adding positives. v0.5.0 avoids that trap: the value-object
error checks make the relevant negative tests pass *for the right reason*
(we throw on genuinely-invalid value objects), while `@language` /
`@direction` / `@json` positive tests convert. Net +13.

### Consumer impact

None. The `sample_obv3` / `minimal_vcv2` / `inline_context` characterization
fixtures produce byte-identical expanded output to v0.4.0 — VC's signature
pipeline is unaffected. VC consumers remain pinned at `^0.1.1`.

## [0.4.0] - 2026-05-13

Type-scoped and property-scoped context activation. Each object now
computes its own active context (instead of relying on the document-
level flat term map being recursively searched).

W3C JSON-LD 1.1 expand test suite progress:

```
v0.1.1 baseline:  69 passed
v0.2.0:          113 passed   (+44)  Expansion rewrite
v0.3.0:          129 passed   (+16)  Container handling
v0.4.0:          126 passed   (-3)   Scope activation
```

**The pass count slipped by 3 on the W3C suite** because v0.3.0's
`TermDefinitions::getTermDefinition` did recursive search through
nested `@context` entries, which accidentally helped some tests by
making scoped terms findable as if they were unscoped. The new
spec-correct lookup is strictly top-level; scoped terms become
available only when their scope activates. Several scope-specific
tests that v0.3.0 failed now pass (e.g. `#tc009`, `#tc010`, `#tc011`),
while a few tests that depended on the leaky lookup now fail. Net
direction is positive (more spec-correct), even though the absolute
count dipped.

VC consumers see zero regression: the characterization fixtures
produce byte-identical expanded output to v0.3.0 for the
`sample_obv3` / `minimal_vcv2` / `inline_context` inputs.

### Added

- **Type-scoped context activation.** When expanding a node object,
  the algorithm collects its `@type` values (including aliased
  `type` forms), looks up each type's term definition for a nested
  `@context`, and overlays those terms onto a fresh active context
  derived from `documentBase`. Per §5.5 step 12 of the spec.
- **Property-scoped context activation.** When expanding the value
  of a property whose term definition carries a nested `@context`,
  that context is layered on top of the current active context for
  the value's expansion. This is what unlocks correct handling of,
  e.g., the VC v2 `proofPurpose` term whose property-scoped context
  defines `assertionMethod` etc.
- `Expansion` now tracks a `$documentBase` separately from the
  mutable `$termDefinitions`. Each nested `expandObject` resets to
  `$documentBase` before computing its own type-scoped overlay, so
  the parent's scope doesn't leak.

### Changed

- **`TermDefinitions::getTermDefinition()` no longer searches
  recursively into nested `@context` entries.** Term lookup is
  strictly top-level on the active context. The recursive helper
  `findExactTermInDefinition()` is deleted.

### Migration notes for consumers

- VC consumers: the `sample_obv3` characterization fixture is
  byte-identical to v0.3.0 — no signature pipeline impact.
- Downstream that relied on the old recursive lookup (which leaked
  scoped terms into unscoped contexts) will see different output.
  This was a bug; the new behaviour is spec-correct.

### Known gaps

- `@propagate: true` is not implemented; scoped contexts always
  reset at nested object boundaries.
- The `@import`, `@protected`, and `@base` keywords inside scoped
  contexts are not yet honoured.
- Property-scoped contexts ARE applied for the value's expansion,
  but they don't propagate deeper (when the value is itself an
  object). Full propagation handling is a future PR.

## [0.3.0] - 2026-05-13

**Breaking.** Adds container handling to Expansion. Properties whose
term definitions have a `@container` mapping now reshape their values
during expansion.

W3C JSON-LD 1.1 expand test suite progress:

```
v0.1.1 baseline:  69 passed
v0.2.0:          113 passed   (+44)
v0.3.0:          129 passed   (+16)
```

### Added

- `@language` container — `{en: "hi", fr: "salut"}` expands to a list
  of value objects with `@language` set.
- `@index` container — `{first: …, second: …}` expands to a list of
  expanded values with `@index` set on each.
- `@id` container — `{"urn:1": {…}, "urn:2": {…}}` expands to a list
  of node objects with `@id` set.
- `@type` container — `{Person: {…}, Animal: {…}}` expands to a list
  of node objects with `@type` set.
- `@graph` container — value wraps in a `@graph` object.
- `@nest` — keys inside a `@nest` value are treated as direct
  properties of the parent.

### Migration notes for consumers

`@graph` container handling materially changes the expanded shape of
anything using it — most notably the VC v2 `proof` term, which is
declared with `@type: @id` + `@container: @graph`. Documents with
proof blocks expand to a structure that has an extra `@graph` wrapper
around the proof's properties. This is spec-correct; the v0.1.x /
v0.2.x output omitted the wrapper.

VC consumers should stay pinned at `^0.1.1` until coordinated.

## [0.2.0] - 2026-05-13

**Breaking.** First Phase 4 release: the lifted-and-shifted v0.1.x
expander is replaced with a from-scratch implementation of the
JSON-LD 1.1 Expansion / Value Expansion / IRI Expansion algorithms.

W3C JSON-LD 1.1 expand test suite progress:

```
v0.1.1 baseline:   69 passed / 316 failed
v0.2.0:           113 passed / 272 failed   (+44 passes)
```

### Changed

- `Accredify\JsonLd\Algorithms\Expansion` rewritten from scratch to
  follow §5.2 / §5.4 / §5.5 of the JSON-LD 1.1 API spec.
  - **Free-floating nodes are dropped** during expansion (§5.5 step 14).
    A document like `{"@id": "urn:x"}` now expands to `[]` instead of
    throwing.
  - **`@value` objects are preserved as value-object leaves** rather
    than recursed into as node objects. Fixes the v0.1.x behaviour
    where `{"@value": "v", "@type": "t"}` lost its `@value`.
  - **IRI Expansion handles `did:`, `urn:`, `mailto:`, blank-node
    `_:…`, and any scheme-prefixed value** instead of relying on
    `FILTER_VALIDATE_URL`.
  - **Compact IRI expansion is single-pass** (`prefix:suffix` → the
    prefix's IRI mapping concatenated with `suffix`) instead of
    iterating until no `:` remains, which the v0.1.x code did
    aggressively.
  - **`@type` values appear in input document order**, not
    alphabetically sorted (the v0.1.x `sort($types)` is removed).
  - **`id` / `type` keyword aliases are NOT applied implicitly** — a
    context must explicitly map `"id": "@id"` / `"type": "@type"` per
    the spec. (The v0.1.x expander treated `id` / `type` as builtins.)
  - Term-definition `@id` chains (e.g. OBv3's `AchievementCredential
    → OpenBadgeCredential → https://…/OpenBadgeCredential`) are now
    iteratively resolved, with a cycle guard.

### Added

- `@list` and `@set` container handling.

### Removed

- The lift-and-shifted Expansion class. Its `escapeString`, `ksort`,
  `sort` quirks no longer apply to expanded output.

### Migration notes for consumers

Downstream consumers that depended on v0.1.x's quirky byte-equivalent-
to-VC output **will see different bytes from this release**. For VC's
`EddsaRdfc2022CryptoSuite` specifically, the new expand chain produces
different RDFC-10 input and therefore different signatures — so the
v0.2.x line is intentionally NOT a drop-in upgrade from v0.1.1.
Consumers should either pin `^0.1.1` or coordinate the upgrade with
matching downstream changes (regenerate signed-credential test
fixtures, or migrate RDFC-10 to consume the new expand output).

The characterization fixture `sample_obv3_expected.json` is regenerated
against the new expander and now reflects the spec-compliant output
shape.

## [0.1.1] - 2026-05-13

Syncs the lifted expander + context processor with current
`accredifysg/verifiable-credentials-php` master. v0.1.0 was extracted from
an older worktree; this release closes that gap so the package is once
again byte-equivalent to its upstream reference (proven by the
characterization tests, regenerated against current VC main).

### Added

- `TermDefinitions::pushVocab()` / `popVocab()` / `getVocab()` / `setVocab()`
  — a stack of active `@vocab` IRIs participates in IRI expansion as a
  fallback for undefined terms.
- `Expansion::createScopedTermDefinitions()` — type-scoped contexts now
  activate when an object has an `@type` whose term definition contains a
  nested `@context`.
- `Expansion::expandPropertyWithScope()` — properties resolve against the
  scoped term definitions first, then the base map.

### Changed

- **Expansion now wraps single-object results in an outer array** per
  the JSON-LD 1.1 spec (`[{…}]` instead of `{…}`).
- `id` / `@id` / `type` / `@type` keys are handled as built-in keywords in
  `expandObjectNode`, without requiring a term-definition alias in the
  active context.
- IRI expansion in `vocab` mode falls back to the active `@vocab` if
  neither a term definition nor a compact-IRI prefix matches.
- Compact IRI expansion (`prefix:path`) preserves a trailing `#` or `/`
  on the prefix IRI instead of always inserting `/` (fixes malformed
  IRIs like `https://w3id.org/security#/proof`).
- `ContextProcessor` handles a `{ "@context": { … } }` wrapper at the
  top level of a context-object response and recurses into it.
- `ContextProcessor::mergeContexts()` pushes each context's `@vocab` onto
  the term definitions' vocab stack.
- Characterization fixtures regenerated against current VC main.

### Fixed

- `EddsaRdfc2022CryptoSuite` consumers in VC can now use this package as
  a drop-in replacement without changing signed-credential output.

## [0.1.0] - 2026-05-13

Initial release. Extracts the JSON-LD context loader + expander out of
[accredifysg/verifiable-credentials-php](https://github.com/accredifysg/verifiable-credentials-php)
into a standalone package. Behaviourally identical to that repo's
pre-extraction implementation — see the
[characterization tests](tests/Algorithms/Characterization/) for the
byte-equal proof.

This release is **not spec-compliant with JSON-LD 1.1**. It is shipped
so the VC repo can consume the package in Phase 3 without behaviour
change. Spec-compliance work lands incrementally in Phase 4.

### Added

- `Accredify\JsonLd\JsonLdProcessor` — top-level processor, exposes `expand()`.
- `Accredify\JsonLd\Contracts\Processor` — public interface.
- `Accredify\JsonLd\Contracts\DocumentLoader` — pluggable `@context` URL resolver.
- `Accredify\JsonLd\Loaders\HttpDocumentLoader` — default PSR-18 + PSR-17
  loader. Throws `DocumentLoaderException` on fetch/parse failure.
- `Accredify\JsonLd\Loaders\CachingDocumentLoader` — in-process cache
  decorator.
- `Accredify\JsonLd\Context\ContextProcessor` — flattens layered
  `@context` declarations into a single term map.
- `Accredify\JsonLd\Context\TermDefinitions` — term → definition value
  object.
- `Accredify\JsonLd\Algorithms\Expansion` — expansion routine (partial
  JSON-LD 1.1; see characterization tests for current behaviour).
- `Accredify\JsonLd\Documents\ExpandedDocument` — read-only wrapper for
  expanded output.
- `Accredify\JsonLd\Documents\RemoteDocument` — DTO for fetched documents.
- `Accredify\JsonLd\Exceptions\JsonLdException` — base exception.
- `Accredify\JsonLd\Exceptions\DocumentLoaderException` — loader failure.
- `Accredify\JsonLd\Enums\Keyword`, `Accredify\JsonLd\Enums\ContainerType`
  — JSON-LD 1.1 keyword + container enums.
- W3C JSON-LD 1.1 test suite vendored as a git submodule under
  `tests/w3c/`, with a Pest-driven harness at `tests/W3c/` that runs
  every manifest as `composer test:w3c`. Currently 1098 tests skipped
  (baseline); Phase 4 lifts that count.

### Known limitations (see [the plan](https://github.com/accredifysg/php-json-ld) for the roadmap)

- IRI expansion rejects valid IRIs like `did:`, `urn:`, blank node `_:`.
- No container handling: `@list`, `@set`, `@language`, `@index`,
  `@graph`, `@id`, `@type`, `@nest`, `@included`.
- No `@reverse`, `@json`, language-tagged or direction-tagged strings.
- No `@import`, `@propagate`, type-/property-scoped contexts.
- `@base`, `@vocab` stored but not applied during IRI expansion.
- `@protected` validated as boolean but not enforced.
- Hardcoded xsd:string collapse.
- Only `expand` is implemented; `compact` and `toRdf` land in Phase 4.

[Unreleased]: https://github.com/accredifysg/php-json-ld/compare/v0.11.0...HEAD
[0.11.0]: https://github.com/accredifysg/php-json-ld/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/accredifysg/php-json-ld/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/accredifysg/php-json-ld/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/accredifysg/php-json-ld/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/accredifysg/php-json-ld/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/accredifysg/php-json-ld/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/accredifysg/php-json-ld/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/accredifysg/php-json-ld/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/accredifysg/php-json-ld/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/accredifysg/php-json-ld/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/accredifysg/php-json-ld/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/accredifysg/php-json-ld/releases/tag/v0.1.0
