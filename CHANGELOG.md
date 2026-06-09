# Changelog

All notable changes to `accredifysg/php-json-ld` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.44.0] - 2026-06-09

Four compaction container/value features, designed via a workflow and each
landed measured-in-isolation against the full suite.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.43.0:      352      178      423
v0.44.0:      352      193      423   (+15 compact)
```

### Added / Fixed (compaction, §5.6)

- **Property-valued `@index`** (`#tpi01`–`#tpi04`, `#t0112`, `#t0113`): a term
  `{@container:@index, @index:"prop"}` now keys the index map by the node's
  index-property VALUE (compacted), removing that property — except when the
  value is a node reference (not a string), where the key stays `@none` and the
  property is kept (`#tpi06` preserved).
- **`@type`-map sole-`@id` entries** (`#tm020`–`#tm023`): a node left with only
  `@id` (after its `@type` became the map key) compacts to the bare, compacted
  `@id` string — vocab-compacted for a `@type:@vocab` container term, otherwise
  document-relative.
- **Nested `@list`** (`#tli01`–`#tli03`): a coerced `@list` whose items are
  themselves `@list` objects now compacts to a nested array (e.g. `[[]]`)
  instead of being flattened, by compacting list items individually.
- **`@graph` arrays** (`#t0039`, `#t0016`): a node-level `@graph` value stays an
  array even for a single member (only `@included` unwraps a single member).

### Deferred

- Explicit `@graph` wrapping when the target term is not `@container:@graph`
  (`#t0090`/`#t0092`/`#t0094`/`#t0083`) and the `compactArrays:false` variants
  (`#t0091`/`#t0093`) — the latter need a `compactArrays` option that is not yet
  threaded through `JsonLdOptions`.

## [0.43.0] - 2026-06-09

Compaction term-selection / value-coercion fixes — part of the cluster the
v0.42.0 gate correction exposed.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.42.0:      352      174      423
v0.43.0:      352      178      423   (+4 compact)
```

### Fixed (compaction, §5.6.2 / §5.6.3)

- `@vocab`-stripping no longer produces a name that collides with a defined
  term mapping to a *different* IRI — using it would not round-trip, so the
  full IRI is emitted instead (`#t0043`/`#tc011`).
- A term's `@vocab`-relative `@type` coercion (a value with no `:` that is not
  itself a defined term) is now expanded via `@vocab` before being compared to
  a value's `@type`, so value compaction can match and drop the coerced `@type`
  (`#t0021`).
- A value-destroying term is no longer selected during IRI compaction: when the
  best candidate term's `@type` coercion cannot represent the value (e.g. a
  `@type: @id` term for a plain-string value), `selectTerm` declines and
  compaction falls through to a compact IRI / `@vocab` / full IRI (`#t0006`).
  Carve-outs (`@type: @none`, `@container` terms, `@list` values, and `@id`/
  `@vocab` terms for all-node-ref values) keep legitimate coercions intact.

### Deferred (higher-risk-low-gain, separate gated work)

- `@vocab`-vs-compact-IRI precedence (`#t0023`), scoped-term override
  (`#tc003`), `@type:@vocab` reverse-map (`#t0044`) — reordering compaction's
  term-preference is the highest-blast-radius change (shared by all passing
  tests) for ~1 test each.
- Type-scoped `@vocab` applied during compaction (`#tc016`/`#tc017`/`#tm007`) —
  couples `activateScopedContext` with `@type`-value compaction ordering;
  a prior naive attempt regressed, so it needs its own isolated measurement.

## [0.42.0] - 2026-06-09

**Conformance-gate correction** + lexicographic container-map ordering in
expansion. This release changes how the W3C harness compares results, which
recalibrates the reported numbers (see below) — the recalibration is a
measurement fix, not a code regression.

### Changed (test harness — measurement methodology)

- The expand and compact W3C suites now compare with `toEqual` (PHPUnit
  `assertEquals`) instead of `toEqualCanonicalizing`. JSON-LD output compares
  with **object-key order insignificant but array order significant** (the
  algorithms produce arrays in a deterministic order); `toEqual` has exactly
  those semantics. `toEqualCanonicalizing` was wrong in both directions: it
  sorted arrays (masking real ordering bugs) yet compared object keys strictly
  (failing correct output that differed only in key order, e.g. our `ksort`ed
  value objects vs the suite's `@value`-first ordering). toRdf is unaffected
  (it compares canonicalised N-Quads).
- Net effect of the gate correction alone: it correctly PASSES ~14 expand
  tests previously failed on key order, and correctly FAILS ~26 expand + ~10
  compact tests previously passed by array-sorting that masked ordering /
  term-selection bugs.

### Fixed (expansion — lexicographic ordering, §5.5)

- Container maps (`@type`, `@index`, `@id`, `@language`) now emit their entries
  in lexicographic (code-point) order of the raw map key, independent of input
  order (`#tm004/#tm009/#tm010`, `#tpi06`–`#tpi09`, `#tdi04`–`#tdi07`,
  `#t0030`). `ksort($map, SORT_STRING)` per map.
- `@nest` values are now merged in a second pass, AFTER all base properties, so
  a property contributed by both reads `[base, nested]` rather than
  `[nested, base]` (`#tn003/#tn005/#tn006/#tn007`).

W3C JSON-LD 1.1 test suite (numbers under the corrected `toEqual` gate; the
v0.41.0 column is the old `toEqualCanonicalizing` gate and is not directly
comparable):

```
            expand   compact   toRdf
v0.41.0:      340      183      423    (old gate — inflated)
v0.42.0:      352      174      423    (corrected gate)
```

Expansion is a genuine improvement (+18 from ordering, net higher than the old
inflated 340). Compaction's honest count under the corrected gate is 174: the
old 183 included ~10 tests that only passed because the key-blind comparison
hid wrong term names. Those ~10 are real term-selection / scoped-`@vocab`
compaction bugs (e.g. `#t0006`, `#tc016`, `#tm007`) — queued as the next
cluster, NOT list-ordering.

## [0.41.0] - 2026-06-09

`@direction` in `@language` container maps during expansion.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.40.0:      337      183      423      943
v0.41.0:      340      183      423      946   (+3 expand)
```

### Fixed (expansion)

- Values produced from a `@language` container map now carry the effective
  base `@direction` (`#tdi04` / `#tdi05` / `#tdi06`): the term's own
  `@direction` (an explicit `null` suppresses it) takes precedence over the
  active context's default `@direction`, matching how plain string values are
  already handled.

### Notes

- The `[@graph, @id]` container expansion tests (`#t0085` / `#t0086` /
  `#t0087`) were investigated and found to already pass the (key-blind) W3C
  gate — their only difference from the expected output is `@id`/`@graph` key
  order, which `toEqualCanonicalizing` discards. No change needed.

## [0.40.0] - 2026-06-09

Term `@id` materialisation — the follow-up flagged in v0.39.0 (`#tc010`),
which also resolved `#tc005` and `#tc035` (same root cause).

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.39.0:      336      183      420      939
v0.40.0:      337      183      423      943   (+1 expand, +3 toRdf)
```

### Fixed (context processing)

- A term defined without an explicit `@id` (and not via `@reverse`), that is
  neither IRI-shaped nor a keyword, now materialises its IRI mapping from the
  active `@vocab` AT DEFINITION TIME (§4.2.2) rather than relying on an
  `@vocab` fallback at use time. So a term whose definition carries only an
  `@context` keeps its original IRI even when a nested/embedded context later
  changes `@vocab` (`#tc010` / `#tc005` / `#tc035`, expand + toRdf twins).
  Expansion output is unchanged for documents whose `@vocab` does not change
  between a term's definition and use (VC characterization fixtures intact).

## [0.39.0] - 2026-06-09

Scoped-context expansion battery — the core type-/property-scoped context
machinery in the Expansion algorithm. Three independent root causes, each
designed from a spec + code + per-fixture investigation and landed/measured
one at a time (every fix gains the expand test and its toRdf twin, since
toRdf reuses expansion).

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.38.0:      330      183      413      926
v0.39.0:      336      183      420      939   (+6 expand, +7 toRdf)
```

### Fixed (expansion — scoped contexts)

- `@type` VALUES are now IRI-expanded against the active context as it stood
  after the node's embedded `@context` but BEFORE the per-type type-scoped
  contexts are applied (`#tc014` / `#tc016` / `#tc018`). A type-scoped
  `@vocab`, `null` reset, or term change no longer leaks into how the type
  that introduced it is itself expressed.
- A non-propagating (type-scoped) context is now reverted on entering a nested
  object only when no key of that object EXPANDS to `@value` (previously it
  tested for a literal `@value` key). So a type-scoped term aliasing `@value`
  (e.g. `value: "@value"`) correctly keeps the nested object a value object
  (`#tc020` / `#tc021`).
- A `@base` entry in a type- or property-scoped `@context` is now applied to
  `@id` resolution within its scope (`#tc015` type-scoped, `#tc024`
  property-scoped). Type-scoped `@base` does not propagate into nested node
  objects (it reverts with the rest of the type-scoped context), while a bare
  `@id` reference keeps it.

### Known remaining

- `#tc010` (scoped context layers on intermediate contexts) is still failing:
  a term defined with only an `@context` (no explicit `@id`) does not get its
  `@id` materialised from `@vocab` at definition time, so a later `@type`
  referencing it falls back to the active `@vocab`. This is a Create Term
  Definition (§4.2.2) `@id`-defaulting gap, separate from this cluster.

## [0.38.0] - 2026-06-08

RDF value-canonicalization fixes (toRdf + the expansion they depend on),
found by auditing the genuinely-failing toRdf tests under a blank-node-
isomorphism-aware gate (most apparent `@json` failures were only
canonical-blank-node-label differences and already passed).

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.37.0:      329      183      408      920
v0.38.0:      330      183      413      926   (+1 expand, +5 toRdf)
```

### Fixed (toRdf / node map)

- Node-map duplicate detection now uses strict, deep, key-order-independent
  equality instead of PHP's loose `==` (`#te061`/`#te018`/`#te088`): loose
  comparison wrongly collapsed distinct native values such as `1` and `true`
  (since `1 == true`) into a single value, dropping triples.
- A JSON number with no fractional part but magnitude ≥ 1e21 now serialises
  as an `xsd:double` in canonical form (e.g. `1.0e21` → `"1.0E21"`) rather
  than an `xsd:integer` with the digits expanded (`#trt01`).

### Fixed (expansion)

- A `@json` value object whose `@value` is `null` is preserved (and serialises
  to the JSON literal `"null"`) instead of being dropped like an ordinary
  null value (`#tjs22`, expand + toRdf).

### Notes

- `#tjs10` (structural `@json` canonicalization) is not addressed: its input
  uses empty JSON objects (`{}`) which PHP's `json_decode($x, true)` collapses
  to empty arrays, losing the object/array distinction the expected output
  needs. The full JSON Canonicalization Scheme (ECMAScript number formatting,
  `#tjs12`) is likewise deferred.

## [0.37.0] - 2026-06-08

Cross-suite expansion fixes — each gains the expand test and its toRdf twin
(toRdf reuses the expansion algorithm). Designed from a parallel
spec + code + per-fixture investigation, gated per-test against the full
suite. (The tc016/tc017 compaction "cluster" was investigated and dropped:
both already pass the key-blind W3C compaction gate — their output differs
from expected only in term names, which `toEqualCanonicalizing` discards.)

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.36.0:      325      183      404      912
v0.37.0:      329      183      408      920   (+4 expand, +4 toRdf)
```

### Fixed (expansion)

- `@type: @none` no longer annotates values (`#ttn02`): a term coerced to
  `@type: @none` suppresses type annotation, so scalar values expand to bare
  `{@value}` objects rather than `{@value, @type: @none}`. (`#te...`/toRdf twin.)
- `@type: @vocab` value coercion now falls back to document-relative
  resolution against `@base` when the value is neither a defined term nor
  `@vocab`-resolvable (`#t0057`/`#te057`) — mirroring `@id` coercion.
- A nested `@vocab: null` now RESETS the active vocabulary instead of
  inheriting the parent's (`#t0059`/`#te059`): a relative `@type` then
  expands document-relative and unmapped terms are dropped.

### Added (error conditions)

- Cyclic IRI mapping (`#ter10`/`#te...`): a term whose `@id` is a compact IRI
  whose prefix is the term itself (e.g. `"term": {"@id": "term:term"}`) is
  rejected at term-definition time. Absolute IRIs, blank nodes (`_:`) and
  keyword `@id`s are exempt.

### Deferred

- `#ter56` (reject `@context` defined as a term): blocked on the
  `{"@context": {…}}` unwrapping accommodation in `ContextProcessor`
  (a VC drop-in compatibility path) — making it throw risks the
  byte-identical characterization fixtures; needs separate sign-off.

## [0.36.0] - 2026-06-08

Two remaining non-crypto conformance items, shipped as a small measured
release: protected `@type` keyword redefinition enforcement on the
context-merge hot path, and scoped-`@base` relativisation during compaction.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.35.0:      324      181      403      908
v0.36.0:      325      183      404      912   (+1 expand, +2 compact, +1 toRdf)
```

### Added (error conditions)

- Protected `@type` keyword redefinition (`#tpr32`): a map-valued `@type`
  context entry is now stored protected-aware during context merging, so a
  later context layer that redefines a protected `@type` differently is
  rejected as a protected term redefinition. (`@type` was previously skipped
  with the other keywords in the merge term-loop and never tracked.)

### Fixed (compaction)

- Scoped-context `@base` is now applied during compaction (`#tc015`
  type-scoped base, `#tc024` property-scoped base): a `@base` carried by a
  type- or property-scoped `@context` relativises document-relative `@id`
  values (and node references) against it. `@vocab`/`@language` are
  deliberately not applied here — a scoped `@vocab` must not affect
  compaction of the `@type` values that triggered it (`#tc016`), which is a
  deeper `@type`-compaction-ordering change left for a follow-up.

## [0.35.0] - 2026-06-08

Final conformance clusters: IRI-shaped-term consistency, protected-term
override enforcement, and property/type-scoped context propagation in
compaction. Designed from a spec + current-code + per-fixture workflow pass.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.34.0:      315      178      394      887
v0.35.0:      324      181      403      908   (+9 expand, +3 compact, +9 toRdf)
```

### Added (error conditions)

- IRI-shaped-term consistency (§4.2.2): when a term contains a colon
  (other than first/last) or a slash and its `@id` differs from the term,
  the term's IRI expansion must equal its `@id` mapping — else an invalid
  IRI mapping. Subsumes the previous keyword-only check (`#ter43`/`#ter44`/
  `#ter48`). New `localExpandIri()` helper (prefix + vocab expansion).
- Protected-term override enforcement:
  - `@prefix` may not be set on a keyword-alias term (`#tpr33`).
  - a `null` term defined inside a `@protected` context retains protection,
    so a later non-override redefinition is rejected (`#tpr28`).
  - a type-scoped `@context` (override-protected = false), including its
    list/`null` layers, may not clear/redefine protected terms — a `null`
    layer over protected terms is an invalid context nullification
    (`#tpr17`/`#tpr18`/`#tpr20`/`#tpr21`; also fixes `#tc017`).

### Fixed (compaction)

- Property-scoped contexts now propagate into nested node objects even when
  a type-scoped context is active (the type-scoped non-propagation rollback
  no longer discards the property-scoped overlay), while `@propagate:false`
  still confines a property-scoped context and `@propagate:true` lets a
  type-scoped one flow in (`#tc013`/`#tc019`/`#tc026`).

### Consumer impact

Additive. Characterization fixtures byte-identical, unit suite green (230).
VC stays pinned at `^0.1.1`.

### Deferred

`#tpr32` (protected `@type` keyword redefinition) needs un-skipping `@type`
in `ContextProcessor::mergeContexts` — a hot-path structural change, separate
commit. `#tc024` needs `@base`/`@vocab` application in scoped contexts during
compaction. The VC RDFC-10 / ecdsa-sd migration (PR 3.4) remains an
owner-sign-off, crypto-signature-regenerating change.

## [0.34.0] - 2026-06-08

Expansion / term-definition validation gates (the shared expand+toRdf tail).

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.33.0:      310      178      390      878
v0.34.0:      315      178      394      887   (+5 expand, +4 toRdf)
```

### Added (error conditions)

- A term `@language` coercion must be a string or null (`#ter22`).
- `@container` may not combine `@list` with another container — `@list` is
  exclusive (`#tes02`).
- `@type: @none` is a JSON-LD 1.1 feature — invalid in 1.0 (`#ttn01`).
- A `@reverse` map may only contain reverse properties (and a nested
  `@reverse`); any other keyword key (e.g. `@id`) is an invalid reverse
  property map (`#ter25`).
- A `@list` object may only carry `@list` and `@index`; any other entry
  (e.g. `@id`) is an invalid set or list object (`#ter41`).

### Consumer impact

Additive (error conditions only on already-invalid input). Characterization
fixtures byte-identical, unit suite green (226). VC stays pinned at `^0.1.1`.

### Deferred

The IRI-shaped-term consistency check (`#ter44`/`#ter48` — a colon/slash term's
IRI expansion must equal its `@id`) needs full term-IRI expansion in
`TermDefinitions`; the type-scoped *expansion* (`tc*`) and protected-override
(`tpr*`) clusters remain.

## [0.33.0] - 2026-06-08

`rdfDirection` toRdf serialisation modes.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.32.0:      310      178      386      874
v0.33.0:      310      178      390      878   (+4 toRdf)
```

### Added

- `toRdf` honours the `rdfDirection` option (threaded via `JsonLdOptions`)
  for base-direction-tagged strings (§9):
  - `"i18n-datatype"` → a literal typed with
    `https://www.w3.org/ns/i18n#{language}_{direction}` (`#tdi09`, `#tdi10`).
  - `"compound-literal"` → a blank node with `rdf:value`, `rdf:direction`,
    and (when present) `rdf:language` (`#tdi11`, `#tdi12`).
  With no `rdfDirection` option the base direction is omitted, as before.

### Consumer impact

Additive (toRdf-only, opt-in via the option). Expansion and compaction
unchanged. Characterization fixtures byte-identical, unit suite green (221).
VC stays pinned at `^0.1.1`.

## [0.32.0] - 2026-06-08

Compaction algorithm buildout, phase 5: type-scoped contexts (with
non-propagation).

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.31.0:      310      172      386      868
v0.32.0:      310      178      386      874   (+6 compact)
```

### Added (compaction)

- Type-scoped `@context`: each of a node's `@type` values is compacted to a
  term and, if that term carries an inline `@context`, it is activated (in
  lexicographic order of the type IRI) before the node's keys/values compact,
  then rolled back. This lets a type's context nullify/redefine terms for
  just that node (`#tc006`–`#tc008`, `#tc010`, `#tc012`, `#tc020`–`#tc023`,
  `#tc025`, `#tpr05`).
- Non-propagation: a type-scoped context does NOT flow into nested node
  objects (a new node object rolls back to the pre-activation context, via a
  `previousContext` snapshot), while value objects and bare `@id` references
  keep it (`#tc009`).

### Consumer impact

Additive (compaction-only). Expansion and toRdf unchanged. Characterization
fixtures byte-identical, unit suite green (219). VC stays pinned at `^0.1.1`.

### Deferred

The combined type-scoped + property-scoped cases (`#tc013`/`#tc019`/`#tc026`)
need property-scoped contexts to propagate *through* the type-scoped
non-propagation rollback — the full §5.5 propagate / previous-context
mechanism — a following release.

## [0.31.0] - 2026-06-08

`JsonLdOptions` value object (PR 4.5) — the canonical JSON-LD 1.1 API options
shape, replacing the per-method `base` / `processingMode` scalar parameters.

### Added

- `Accredify\JsonLd\JsonLdOptions` — an immutable value object holding the
  API options (`base`, `processingMode`, and the reserved-but-not-yet-wired
  `rdfDirection`, `produceGeneralizedRdf`), with a `with()` deriver. It
  mirrors the spec's `JsonLdOptions` interface and gives a single home for
  forthcoming options.

### Changed

- `Processor::expand()` / `compact()` / `toRdf()` (and `JsonLdProcessor`) now
  take an optional `?JsonLdOptions $options` instead of positional
  `?string $base` / `?string $processingMode`. Callers passing no options are
  unaffected; the W3C harness adapter builds a `JsonLdOptions` from each
  manifest entry. Pure refactor — W3C scores unchanged (expand 310,
  compact 172, toRdf 386).

### Consumer impact

The package is pre-1.0; VC pins `^0.1.1` and calls `expand($document)` with no
options, so it is unaffected. Characterization fixtures byte-identical, unit
suite green (217).

### Note on PR 3.4 (delete duplicated JSON-LD code in the VC repo)

Investigated and intentionally NOT performed: the VC repo's
`Canonicalization\RDFC10\JsonLdToQuadsProcessor` (the RDFC-10 /
canonicalization consumer) still uses the original
`Processors\JsonLdProcessor`, and four unit tests still exercise the original
classes. Deleting them would break a crypto-critical consumer; migrating
RDFC-10 to the (now spec-compliant, different-output) package expander is a
deliberate, signature-fixture-regenerating change, not a mechanical cleanup.
Tracked for a dedicated, risk-managed VC-repo PR.

## [0.30.0] - 2026-06-08

toRdf tail: RFC-3986 base handling and a blank-node-isomorphism-aware
N-Quads comparison in the conformance harness.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.29.0:      310      172      376      858
v0.30.0:      310      172      386      868   (+10 toRdf)
```

### Fixed

- An absolute `@base` is now stored verbatim (RFC 3986 §5.1 parses the base
  as-is, without dot-segment removal), so document-relative resolution
  against it matches the RFC reference-resolution vectors — e.g. a
  query-/fragment-only reference keeps the base path's `./` segment
  (`#t0122`–`#t0125`). New `IriResolver::establishBase()`.

### Changed (test harness)

- The toRdf N-Quads comparison now canonicalises blank-node labels
  (relabelling both sides deterministically by first appearance in the
  structurally-sorted quads, masking blank-node labels for ordering and
  preserving literal content). RDF datasets are equal up to blank-node
  isomorphism, but the W3C fixtures use canonical `_:c14n0` labels while the
  processor emits `_:b0`; this lets isomorphic datasets compare equal (the
  `@json` / `tjs` cluster, where the serialisation was already correct).
  Deterministic, so it never changes a previously-passing comparison.

### Consumer impact

Additive; the `establishBase` change only affects how an absolute `@base` is
stored (more RFC-correct). Expansion and compaction unchanged.
Characterization fixtures byte-identical, unit suite green (214). VC stays
pinned at `^0.1.1`.

### Deferred

`rdfDirection` (`i18n-datatype` / `compound-literal`) serialisation modes
remain (4 tests), plus the toRdf failures shared with the expansion tail.

## [0.29.0] - 2026-06-08

Compaction algorithm buildout, phase 4: property-scoped contexts.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.28.0:      310      169      376      855
v0.29.0:      310      172      376      858   (+3 compact)
```

### Added (compaction)

- Property-scoped `@context`: a term carrying an inline `@context` activates
  it (overlaid on a clone of the active context, inverse rebuilt) while that
  property's value is compacted, then rolls back — so scoped terms become
  selectable inside the value (`#tc001`, `#tc002`, `#tc004`, `#tc005`),
  including legal protected-term overrides from a property-scoped context
  (`#tpr04`). A bare scoped term's `@id` resolves through the inherited
  `@vocab`; a `null` entry removes the term.

### Consumer impact

Additive (compaction-only). Expansion and toRdf unchanged. Characterization
fixtures byte-identical, unit suite green (212). VC stays pinned at `^0.1.1`.

### Deferred

Type-scoped contexts during compaction (`#tc011`/`#tc017`/`#tc019`/… — node
type drives the scoped context, with nullification and `@type`-value
compaction edges) remain the structural follow-up.

## [0.28.0] - 2026-06-08

Compaction algorithm buildout, phase 3: value-aware term selection. Designed
from a §5.6.2 spec + current-code + per-fixture workflow pass whose key
finding — the W3C compaction gate is *key-blind* (it compares with
`toEqualCanonicalizing`, which discards associative keys) — meant the real
wins are in VALUE SHAPE, not term names. Implemented as a surgical value-aware
selection over the existing flat inverse (no nested-table rewrite), gated by a
full per-test before/after diff against both the W3C and the key-sensitive
unit compaction suites.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.27.0:      310      163      376      849
v0.28.0:      310      169      376      855   (+6 compact)
```

### Added (compaction)

- Per-value term selection (§5.6.2): each expanded item in a property's value
  may select a DIFFERENT term, so one property can split across terms — e.g. a
  node reference whose `@id` round-trips to a vocab term compacts under a
  `@type: @vocab` term while another compacts under `@type: @id`; lists with
  different common `@type` / `@language` route to different `@list` terms.
- Value-aware term scoring: among terms mapping to the same IRI, prefer one
  whose coercion matches the value (`@type: @vocab`/`@id` for node refs,
  `@type: T` / `@language: L` for matching value objects, a `@container: @list`
  term with matching common type/language for lists). A non-matching coerced
  term never displaces a plain term.
- `@language` collapse (§5.6.3): a language-tagged value whose language matches
  the term's `@language` coercion — or, absent one, the active default
  `@language` — drops `@language` and becomes the bare scalar.
- A term-definition `@type` now resolves through a defined term (e.g.
  `@type: "type1"` where `type1` maps to an IRI) when matching the value's
  `@type`, fixing a latent `expandedEquals` gap.

### Consumer impact

Additive (compaction-only). Expansion and toRdf unchanged. Characterization
fixtures byte-identical, unit suite green (210). VC stays pinned at `^0.1.1`.

### Deferred

The remaining compaction tail (`#t0018`, `#t0089` language-container routing,
and tests entangled with not-fully-expanded inputs) needs per-value routing
into `@language`-container maps and input pre-expansion — a following release.

## [0.27.0] - 2026-06-08

Compaction algorithm buildout, phase 2: `@graph` container maps and
`@reverse` compaction (the workflow plan's next-ranked low/medium-risk
tranches).

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.26.0:      310      142      376      828
v0.27.0:      310      163      376      849   (+21 compact)
```

### Added (compaction)

- `@graph` container compaction (§5.6):
  - `[@graph, @id]` → a map keyed by each graph's (compacted) `@id`, else
    `@none`;
  - `[@graph, @index]` → a map keyed by each graph's `@index`, else `@none`;
  - plain `@graph` → a simple graph unwraps to its node (single) or an
    `@included` wrapper (multiple); a *named* graph (has `@id`) is kept as
    `{@id, @graph}`;
  - `@set` keeps the result (or each map entry) as an array.
- `@reverse` compaction: the `@reverse` map's inner nodes are compacted, and
  a property carrying a reverse-coerced term (`{"@reverse": "…"}`) is hoisted
  to the top level under that term; the rest stay under the (aliased)
  `@reverse` map.

### Consumer impact

Additive (compaction-only). Expansion and toRdf unchanged. Characterization
fixtures byte-identical, unit suite green (207). VC stays pinned at `^0.1.1`.

### Deferred

The remaining compaction gap is the structural work: a value-aware
inverse-context term selection (§5.6.2 full) and property-/type-scoped
contexts during compaction.

## [0.26.0] - 2026-06-08

Compaction algorithm buildout, phase 1: keyword-value recursion, `@nest`,
top-level `@graph` wrapping, and value-compaction fixes (`@none` aliasing,
`@direction`, `@type: @none`). Designed from a spec §5.6 + current-code +
per-fixture analysis pass.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.25.0:      310      111      376      797
v0.26.0:      310      142      376      828   (+31 compact)
```

(Compaction was 111 at the v0.25.0 tag; this release takes it to 142.)

### Added (compaction)

- `@graph` and `@included` values are compacted recursively — their inner
  node objects are now compacted instead of passed through verbatim (the
  single largest root cause of compaction failures).
- Multiple top-level node objects are wrapped in a (possibly aliased)
  `@graph` map (§5.6 step 7).
- `@nest`: a property whose term defines `@nest` is grouped under the
  (verbatim/aliased) nest term; an `@nest` value that is neither `@nest`
  nor a term aliasing it is rejected.
- Container-map keys synthesised as `@none` are compacted to a keyword
  alias when the context defines one (e.g. `"none": "@none"`).
- Value compaction now preserves `@direction`, and a `@type: @none` term
  disables value collapsing (the value object is kept with aliased keys).

### Consumer impact

Additive (compaction-only). Expansion and toRdf unchanged. Characterization
fixtures byte-identical, unit suite green (202). VC stays pinned at `^0.1.1`.

### Deferred

Compaction remains partial: `@graph` container maps (`[@graph,@id]` /
`[@graph,@index]`), `@reverse`, a value-aware inverse-context term
selection, and property-/type-scoped contexts during compaction are the
next tranches (the larger / structural items).

## [0.25.0] - 2026-06-08

Compaction processing-mode gates, keyword-alias compaction, and a
`@type`-coercion validation fix (a latent bug that also affected
expansion).

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.24.0:      309      101      375      785
v0.25.0:      310      111      376      797   (+1 expand, +10 compact, +1 toRdf)
```

### Fixed

- A term-definition `@type` coercion may resolve through a previously-defined
  term in the same context (e.g. `"@type": "t2"` where `t2` maps to an IRI),
  not only through `@vocab` or as an absolute IRI / keyword (§4.2.2 step 12).
  The v0.22.0 validator wrongly rejected this as an "Invalid type mapping" —
  a latent bug that also rejected valid expansion contexts (`#t0015`,
  `#t0018`, `#t0024`, `#tla01`; +1 expand, +1 toRdf).

### Added

- `Processor::compact()` accepts an optional `?string $processingMode`,
  threaded through to `ContextProcessor` so the 1.0/1.1 term-definition
  gates apply during compaction.
- JSON-LD 1.0 term-definition gates (apply to all algorithms): `@prefix`,
  `@nest`, and scoped `@context` are 1.1-only and invalid in 1.0
  (`#tep07`, `#tep10`, `#tep11`); the array / `@id` / `@type` / `@graph`
  container gates now also fire for compaction (`#tep12`–`#tep15`,
  `#tep05`).
- `@prefix` is rejected on a compact-IRI / IRI-shaped term in any mode —
  only simple terms may be prefixes (§4.2.2 step 24, `#tep09`).
- Keyword-alias compaction: a keyword (e.g. `@id`, `@type`, `@list`)
  compacts to a defined keyword alias (`"id"`, `"type"`, …) instead of
  emitting the bare keyword.

### Consumer impact

Additive. Characterization fixtures byte-identical, unit suite green (196).
VC stays pinned at `^0.1.1`.

### Deferred

Compaction is still a partial §5.6 implementation (101→111). The bulk of
the remaining gap (free-floating-node drop, `@reverse`, `@nest`, scoped
contexts, `@graph` containers, type/index maps, value-compaction edges) is
a dedicated algorithm buildout for a following release.

## [0.24.0] - 2026-06-07

Container expansion: the `@graph` container family, map-container `@none`
handling, and tolerance of a missing `@context` on `expand()`.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.23.0:      284      101      355      740
v0.24.0:      309      101      375      785   (+25 expand, +20 toRdf)
```

### Fixed / Added

- `expand()` now tolerates a missing `@context` (the document expands
  against an empty active context, mirroring `toRdf()`), instead of
  throwing "Missing @context". A document without `@context` is valid.
- Plain `@graph` container: each top-level element of the value is wrapped
  in its OWN graph object (multiple objects produce multiple separate
  `{@graph: […]}` objects, and an already-graph element is wrapped one
  further level), per §5.5.
- Combined containers `[@graph, @index]`, `[@graph, @id]`, and
  `[@graph, @type]`: each map entry's expansion is wrapped in a graph
  object first, then the `@index` (key) / `@id` (expanded key) / `@type`
  (expanded key) is attached as a sibling of `@graph`. An entry that is
  already a graph object is not re-wrapped.
- Map-container `@none`: a map key whose IRI expansion is `@none` (the
  literal keyword OR an alias of it) no longer attaches `@index` / `@id` /
  `@type` / `@language` metadata — the previous code only recognised the
  literal string `"@none"`. Applies to `@index`, `@id`, `@type`, and
  `@language` maps.

### Consumer impact

Additive. Characterization fixtures byte-identical, unit suite green (192).
VC stays pinned at `^0.1.1`.

### Deferred

`@graph`/map cases needing the type-scoped "map context" step
(`#tm008`/`#tm017`), property-valued index into a graph object (`#tpi11`),
and `@type: @none` value coercion (`#ttn02`) remain — separate clusters.

## [0.23.0] - 2026-06-07

Processing-mode (JSON-LD 1.0 / 1.1) threading. The effective mode is now
carried from the caller (`processingMode` / the test manifest's
`specVersion`) through `JsonLdProcessor` → `ContextProcessor` →
`TermDefinitions`, and the 1.0-vs-1.1 behavioural differences are gated.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.22.0:      275      101      346      722
v0.23.0:      284      101      355      740   (+9 expand, +9 toRdf)
```

### Added

- `Processor::expand()` / `Processor::toRdf()` take an optional
  `?string $processingMode` (`"json-ld-1.0"` / `"json-ld-1.1"`, default
  1.1). `ContextProcessor` accepts and threads it; `TermDefinitions`
  carries it (`setProcessingMode()` / `isJson10()`).

### Added (error conditions, JSON-LD 1.0)

- `@version: 1.1` under an explicit `json-ld-1.0` mode is a processing mode
  conflict (`#tep02`).
- `@propagate` and `@import` are 1.1-only context entries — invalid in 1.0
  (`#tc029`, `#tso01`).
- A keyword (`@type`) may not be redefined with a map in 1.0
  (`#ter42`).
- `@container` in 1.0 must be a single string from
  `{@list, @set, @index, @language}` — array containers and the 1.1
  additions (`@id`/`@type`/`@graph`) are invalid (`#ter21`, `#tes01`).
- A property-valued `@index` term definition requires 1.1 (`#tpi01`).
- A document-relative or empty `@vocab` is invalid in 1.0 (`#t0115`/`#t0116`,
  `#te115`/`#te116`).
- A list whose members include a list object is a list of lists — rejected
  in 1.0 (1.1 lifted the restriction) (`#ter24`, `#ter32`).

### Added (error conditions, JSON-LD 1.1)

- When a term is itself IRI-shaped (a colon other than first/last char, or a
  slash), its IRI expansion must equal its `@id` mapping (§4.2.2). A keyword
  `@id` (e.g. `@type`) can never equal an IRI term, so it is an invalid IRI
  mapping. The check is 1.1-only: `#ter43` (1.1, error) and `#t0026` (1.0,
  valid) share an input and differ only by mode. Simple terms (no colon/slash,
  e.g. `type`) are unaffected, so 1.1 `@id: @type` aliases still work
  (`#tc0073`).

### Consumer impact

Additive. The new parameter is optional and defaults to 1.1, so existing
callers are unchanged. Characterization fixtures byte-identical, unit suite
green (187). VC stays pinned at `^0.1.1`.

## [0.22.0] - 2026-06-06

Term-definition IRI-mapping validation (the `@type` / bare-term / keyword-alias
negative-test subset that needs no processing-mode threading).

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.21.0:      271      101      342      714
v0.22.0:      275      101      346      722   (+4 expand, +4 toRdf)
```

### Added (error conditions)

- A term-definition `@type` (type coercion) must be a keyword
  (`@id`/`@vocab`/`@json`/`@none`) or resolve to an absolute IRI — a blank
  node (`_:…`) or an unresolvable relative value (no `@vocab`) is an invalid
  type mapping.
- A bare term (no `@id`, no `@reverse`, no `:`/`/`, not a keyword) with no
  active `@vocab` is an invalid IRI mapping (`@reverse` terms are exempt — the
  reverse IRI supplies the mapping).
- A term may not alias `@context` (`@id: "@context"` is an invalid keyword
  alias).

### Deferred

The remaining `ter*` negatives need processing-mode (1.0/1.1) threading
(`#ter21`/`#ter24`/`#ter42`, and `#ter43`/`#t0026` which share an input but
differ by mode), compact-IRI consistency (`#ter44`), or cyclic-IRI detection
(`#ter10`).

### Consumer impact

Additive. Characterization fixtures byte-identical, unit suite green (173).
VC stays pinned at `^0.1.1`.

## [0.21.0] - 2026-06-06

Scoped remote / `@import` contexts and relative context references.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.20.0:      266      101      336      703
v0.21.0:      271      101      342      714   (+5 expand, +6 toRdf)
```

### Added

- **Remote / `@import` scoped contexts.** A `DocumentLoader` is now threaded
  into the expander, so a term's scoped `@context` that is a string IRI or
  carries `@import` (in type-scoped, property-scoped, or embedded position)
  is dereferenced and overlaid. Type-scoped `@import` honours `@propagate`.
- **Relative context references** are resolved against the active base before
  loading (so a document context like `"so-context.jsonld"` resolves against
  the document URL).
- A term definition's scoped `@context` may be a string or null (previously
  only an inline array/map was accepted).

### Consumer impact

Additive. Characterization fixtures byte-identical, unit suite green (168).
VC stays pinned at `^0.1.1`.

## [0.20.0] - 2026-06-06

`@import` — context sourcing at the document level.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.19.0:      259      101      329      689
v0.20.0:      266      101      336      703   (+7 expand, +7 toRdf)
```

### Added

- **`@import` (§4.1.2 step 5.6).** A context's `@import` value (a string IRI)
  is dereferenced through the `DocumentLoader` and reverse-merged beneath the
  containing context — the local entries override the imported ones, letting
  a sourced (e.g. JSON-LD 1.0) context be upgraded in place. Errors: a
  non-string `@import` (invalid `@import` value), an imported context that
  itself contains `@import`, and an `@import` target whose `@context` is a
  list rather than a single map (invalid remote context).

### Known limitation

`@import` (and remote string contexts) inside *scoped* contexts
(type-/property-scoped, embedded) is not yet resolved — those are applied by
the expander, which currently has no `DocumentLoader`. The deferred tests
(`#tso05`, `#tso06`, `#tc031`, `#tc034`) need the loader threaded into
expansion. Document-level `@import` is fully supported.

### Consumer impact

Additive. Characterization fixtures byte-identical, unit suite green (167).
VC stays pinned at `^0.1.1`.

## [0.19.0] - 2026-06-06

Active-context propagation refactor — the keystone for context scoping. The
per-object "reset to document context" is replaced by spec-faithful
propagate semantics: property-scoped and embedded contexts now flow into
nested node objects, type-scoped contexts are confined via a previous-context
rollback, `@context: null` resets the active context, and `@propagate`
overrides per-kind defaults. This turns on **scoped** `@protected` enforcement.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.18.0:      247      101      314      662
v0.19.0:      259      101      329      689   (+12 expand, +15 toRdf)
```

### Changed / Added

- **Propagate semantics (§5.5 step 7 + Context Processing step 3).** A
  non-propagating (type-scoped, or any `@propagate: false`) context records a
  previous-context snapshot; on descending into a new node object the active
  context rolls back to it. Property-scoped and embedded node contexts
  propagate (default `@propagate: true`).
- **`@context: null` reset** in scoped contexts: clears terms / vocab
  (original base preserved); guarded by an "invalid context nullification"
  check when protected terms are present and override is not permitted.
- **Scoped `@protected` enforcement.** With the active context now threaded,
  override-protected is applied per kind — property-scoped `true`, type-scoped
  and embedded `false` — so a scoped context redefining a protected term
  raises (while an explicit `@protected: false` or a context reset still
  lifts protection).
- An empty node object as a property value is preserved (`[{}]`) rather than
  dropped.

### Known limitation

`#tpr06` (toRdf): an `@context: null` reset that empties a node yields an
empty node object, which PHP represents as `[]` — indistinguishable from an
empty list in the RDF node-map, so its `<prop> _:bN` quad is not emitted.
Expansion of the same input is correct. This single toRdf edge case is the
trade for the +15 toRdf gain and needs a distinct empty-node representation
to resolve.

### Consumer impact

Additive overall. Characterization fixtures byte-identical, unit suite green
(167, with new scoped-context guards). VC stays pinned at `^0.1.1`.

## [0.18.0] - 2026-06-06

`@protected` term protection — the tracking infrastructure plus
document-level enforcement. Built carefully against the 39 passing
protected/scoped positives first: zero positive regressions.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.17.0:      244      100      311      655
v0.18.0:      247      101      314      662   (+3 expand, +1 compact, +3 toRdf)
```

### Added

- **Protected-term tracking** on `TermDefinitions`: a term is protected when
  its definition (or its enclosing context's top-level `@protected`) marks it
  so; a term's own `@protected` overrides the context default (so
  `{"@protected": false}` opts out).
- **Document-level protected-term redefinition enforcement
  (§4.1.2 / §4.2.2):** redefining — or nullifying — a protected term in a
  later document context layer raises, unless the new definition is identical
  (ignoring `@protected`), in which case it is permitted and the term stays
  protected.

### Known limitation

Protected-term enforcement inside *scoped* contexts (type-scoped,
property-scoped, embedded node `@context`) is intentionally **not** enforced
yet: doing so correctly requires propagating `@context: null` resets into
nested-object expansion (an active-context threading refactor that would
otherwise risk the scope-isolation guarantees ~39 scoped tests depend on).
Those negative tests (`#tpr01`, `#tpr03`–`#tpr12`, `@import` `#tso*`, …)
remain deferred. Scoped redefinitions are conservatively *allowed* (never
wrongly rejected).

### Consumer impact

Additive. Characterization fixtures byte-identical, unit suite green (164).
VC stays pinned at `^0.1.1`.

## [0.17.0] - 2026-06-06

More validation surface — the expansion-time error conditions from the
checklist, each measured with zero positive regressions (one near-miss:
`@reverse: "@ignoreMe"` must be *ignored*, not rejected — distinguished
from a genuinely invalid `@reverse: "not an IRI"`).

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.16.0:      238      100      302      640
v0.17.0:      244      100      311      655   (+6 expand, +9 toRdf)
```

### Added (error conditions)

- **Colliding keywords (§5.5):** two distinct properties expanding to the
  same keyword now raise — except `@type` and `@included`, which are merged
  across source properties (this also fixes multi-property `@type`).
- **Value-object `@type` well-formedness:** must be a single, absolute,
  whitespace-free IRI (or `@json`); a blank node, a relative IRI, a
  multi-element datatype array, or whitespace is an invalid typed value.
- **`@language` map values** must be strings.
- **`@reverse` mapping IRI:** a term's `@reverse` that expands to a
  non-IRI / non-blank-node (e.g. `"not an IRI"`) raises; one that expands to
  null (a keyword-shaped value like `"@ignoreMe"`) leaves the term ignored.

### Consumer impact

Additive. Characterization fixtures byte-identical, unit suite green (160).
VC stays pinned at `^0.1.1`. Remaining negative families (`@protected`
redefinition, IRI-mapping term-definition validation, `@import`,
processing-mode gating) are deferred — they need dedicated architectural
work (prior-definition tracking, term-time IRI expansion).

## [0.16.0] - 2026-06-06

Validation surface — the bulk of the W3C *negative* test suite. A spec
extraction (a workflow over the local W3C API spec) produced a per-error
checklist of exact throw conditions plus the anti-false-positive guard for
each (the "reverse trap": a validator must never raise on valid input).
Each check was implemented and verified to add negative passes with zero
positive regressions.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.15.0:      213       98      273      584
v0.16.0:      238      100      302      640   (+25 expand, +2 compact, +29 toRdf)
```

### Added (error conditions)

- **Context processing (§4.1.2):** `@version` must be exactly `1.1`;
  `@propagate` must be boolean; `@type` may only be "redefined" with a map
  of `{@container:@set?, @protected?}` (any other shape is keyword
  redefinition); a term value must be a string, map, or null.
- **Create Term Definition (§4.2.2):** empty-string term; `@reverse` must be
  a string and must not coexist with `@id`/`@nest`; a reverse term's
  `@container` is limited to `@set`/`@index`; `@prefix` must be boolean; a
  `@type`-container term requires `@type` `@id`/`@vocab`; a property-valued
  `@index` requires an `@index` container and an IRI value.
- **Expansion (§5.5):** `@included` must expand to node objects (not a
  scalar / value object / list object); an `@nest` value must be a node
  object or array of them and must not be a value object; `@id` / `@index`
  values must be strings; `@type` must be a string or array of strings; a
  property-valued index entry must be a node object, not a value object.

### Consumer impact

Additive. Characterization fixtures byte-identical, unit suite green (160),
expansion/compaction/toRdf of valid documents unchanged. VC stays pinned at
`^0.1.1`. (Deferred, higher-risk: `@protected` redefinition, processing-mode
gating, `@import`, and IRI-mapping term validation.)

## [0.15.0] - 2026-06-06

Spec-grounded conformance grind, driven by a fix plan extracted from the
local W3C "JSON-LD 1.1 Processing Algorithms and API" specification. Each
change verified net-positive in isolation; one planned change (tolerating a
missing `@context`) was measured net-negative — it exposes the absence of
content-level validation, the negative-test trap — and dropped.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.14.0:      201       95      247      543
v0.15.0:      213       98      273      584   (+12 expand, +3 compact, +26 toRdf)
```

### Fixed

- **IRI Expansion gates term lookup on vocab mode (§5.2 steps 4-5).** A term's
  IRI mapping is now applied only when expanding in vocab mode (property keys,
  `@type`); a non-vocab value such as an `@id` no longer matches a same-named
  term and instead resolves document-relative against the base. A term whose
  mapping is a *keyword* still resolves in either mode.
- **Value-object `@type` is a scalar (§5.3 step 4).** Inside a value object,
  `@type` is collapsed from a single-element list back to the datatype-IRI
  string (node-object `@type` stays an array).
- **`@vocab` accepts compact IRIs, blank nodes, the empty string, and relative
  references (§4.1.2 step 5.8)**, each resolved during context merge (compact
  via its prefix term, empty/relative against the base or current `@vocab`,
  blank node verbatim). Only a non-string `@vocab` is rejected.
- **toRdf drops statements with malformed IRIs (§8.1/§8.2).** A subject,
  predicate, object, or graph IRI containing characters illegal in an IRIREF
  (e.g. a space) yields no RDF term, so the statement is silently dropped.

### Added

- **`jld:PositiveSyntaxTest` handling in the toRdf harness.** Entries with no
  expected fixture now pass when the processor produces output without
  raising (previously mis-scored as failures).

### Consumer impact

Additive. Characterization fixtures byte-identical, unit suite green (160),
compaction improved. VC stays pinned at `^0.1.1`.

## [0.14.0] - 2026-06-06

Conformance grind on top of v0.13.0's toRdf — four measured expansion /
context fixes, each kept only after verifying a net-positive W3C delta.

W3C JSON-LD 1.1 test suite:

```
            expand   compact   toRdf   total
v0.13.0:      191       95      234      520
v0.14.0:      201       95      247      543   (+10 expand, +13 toRdf)
```

### Fixed

- **Array `@container` values** (e.g. `["@graph", "@set"]`, `["@index",
  "@set"]`) are now accepted by term-definition validation. The expander
  already understood array containers; only the validation rejected them.
- **`@set` is unwrapped during expansion.** `{"@set": […]}` now expands to
  its contents directly (an empty `@set` expands to nothing) instead of
  being retained as a node object — which previously surfaced as a stray
  blank node in toRdf output.
- **Property-valued index** (`@container: @index` with `@index: "<prop>"`):
  each map key is now attached as a *value* of the named property on the
  expanded item, instead of as `@index` metadata.
- **Terms mapped to `null`** (`"term": null` / `{"@id": null}`) are now
  decoupled — they no longer fall back to `@vocab` during IRI expansion.

### Consumer impact

Additive. Characterization fixtures byte-identical, unit suite green (158),
compaction unchanged. VC stays pinned at `^0.1.1`.

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
