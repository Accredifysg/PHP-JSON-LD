# Changelog

All notable changes to `accredifysg/php-json-ld` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

> **The next release is 2.0.0.** The JSON-LD 1.1 algorithms still missing from
> the 1.x line — Flattening and RDF-to-JSON-LD `fromRdf` (below) and the planned
> `frame` — are added to the public `Contracts\Processor` interface. Adding
> methods to a published interface is a breaking change for downstream
> implementers, so these algorithm additions are bundled into one major release
> rather than shipped piecemeal. Callers using the concrete `JsonLdProcessor`
> are unaffected, and the expand / compact / toRdf OUTPUT is unchanged
> throughout.

### Added

- **Flattening** — a new `Processor::flatten()` / `JsonLdProcessor::flatten()`
  implementing the JSON-LD 1.1 Flattening Algorithm: collect every node object
  into a single flat array in the default graph, labelling blank nodes
  deterministically and folding each named graph into a `@graph` entry on its
  graph-name node; with a supplied context the flattened output is compacted and
  `@graph`-wrapped (reusing the Compaction algorithm). New `Algorithms\Flattening`
  (built on the existing `Algorithms\NodeMap` node-map generation, exactly as
  `toRdf` is), the `Documents\FlattenedDocument` result wrapper, and the
  `Keyword::Default` case. **58/58 of the W3C flatten suite (100%)**.

- **RDF to JSON-LD (`fromRdf`)** — a new `Processor::fromRdf()` /
  `JsonLdProcessor::fromRdf()` implementing the Serialize-RDF-as-JSON-LD
  algorithm. Accepts an `RdfDataset` or an N-Quads string (parsed by a new,
  dependency-free `Rdf\NQuadsParser` — php-json-ld takes no dependency on
  `accredifysg/php-rdf-canonicalize`) and produces expanded JSON-LD: rebuilds
  value objects (`useNativeTypes`), folds or keeps `rdf:type` (`useRdfType`),
  reconstructs `rdf:first`/`rest`/`nil` chains into `@list`, and decodes `@json`
  literals. New `Algorithms\FromRdf`, `Rdf\NQuadsParser`,
  `Documents\FromRdfDocument`, and the `useNativeTypes` / `useRdfType`
  `JsonLdOptions` fields. **49/53 of the W3C fromRdf suite** (remaining:
  list-of-lists conversion `#t0008`/`#tli03`, and two non-normative
  compound-literal direction cases).

- **Framing (`frame`)** — a new `Processor::frame()` / `JsonLdProcessor::frame()`
  implementing the [JSON-LD 1.1 Framing Algorithm](https://www.w3.org/TR/json-ld11-framing/):
  frames the merged graph (or the default graph when the frame carries a
  top-level `@graph`), matching subjects by `@type`/`@id`, value patterns, and
  recursive node patterns (`@requireAll` AND-vs-OR), then embeds referenced
  nodes per `@embed` (`@once` default, `@never`, `@always`, cycle-guarded),
  honours `@explicit`, injects `@default` behind `@preserve` (with
  `@omitDefault`), frames `@graph`/`@included`/`@reverse`, prunes
  singly-referenced blank-node `@id`s, and compacts against the frame's
  `@context` with the mode-dependent `omitGraph`. New `Algorithms\Framing`
  (a faithful port of the reference algorithm, over the existing
  `Algorithms\NodeMap`), `Documents\FramedDocument`, the `embed` / `explicit` /
  `requireAll` / `omitDefault` / `omitGraph` `JsonLdOptions` fields, and a
  frame-expansion mode on `Algorithms\Expansion` (gated, so the existing
  expand/compact/toRdf output is byte-identical). Because PHP's associative
  arrays cannot distinguish a frame's `{}` (wildcard) from `[]` (`match none`),
  an empty frame value is treated as `match none` and a wildcard is carried as
  the `Expansion::FRAME_WILDCARD` sentinel. The merged-vs-default graph decision
  is read from the *raw* frame keys (a sole top-level `@graph` is folded away by
  expansion), so a frame with a top-level `@graph` correctly frames the default
  graph rather than the merged one — fixing `@container:@graph` framing (`#tg010`).
  **89/92 of the W3C json-ld-framing suite** (remaining: compaction safe-mode
  strictness `#t0010`, `@language` case-normalization `#t0045`, and the legacy
  `@embed: @last` `#t0059`). W3C total now 1286/1301.

### Changed

- **BREAKING:** `Contracts\Processor` gains `flatten()`, `fromRdf()`, and
  `frame()`. Adding methods to the published interface breaks any downstream
  implementer, so these land together in 2.0.0. No runtime behaviour change for
  existing `expand` / `compact` / `toRdf` callers.

### Fixed

- **Compaction: a type-scoped `@container:@set` term now keeps its array.** A
  property whose `@container:@set` comes from a type-scoped context (activated by
  the node's `@type`) was unwrapped to a single value, because the array-vs-single
  decision was made *after* recursing into the value — and a nested node object
  rolls back the type-scoped context (§5.6 non-propagation), hiding the term's
  container. The container is now resolved before recursing. Affects plain
  `compact()` too, not only framing (`#t0062`); the 246/246 compaction suite is
  unchanged.

- **Expansion: a scoped context's `"term": null` now nullifies the term.** A
  type-/property-scoped context entry mapping a term to `null` was silently
  ignored, so an inherited definition (e.g. an `@nest` term) survived. It now
  drops the term, matching the spec.

- **Expansion: `@id`/`@index` inside an `@nest` block stay scalars.** The `@nest`
  merge array-wrapped every key, turning a nested `@id` (e.g. via an `id` alias)
  into `["…"]` (invalid). Scalar keywords are now merged verbatim. Together with
  the scoped-`null` fix this clears the `json.api` example (`#tin06`) across
  **expand, toRdf, and flatten** — flatten is now 58/58 (100%).

- **Relative `@context` URLs now resolve correctly.** Two fixes: (1) the remote-
  context cycle guard is now path-based, so a context referenced by two sibling
  contexts (a diamond) loads without a false "circular reference" error while a
  true A→B→A recursion still throws (`#t0128`/`#te128`); (2) a term's relative
  scoped `@context` is resolved against the URL of the context that defines it
  (not the document `@base`), so it loads correctly during expansion (`#tc031`).
  Expand 379→381, toRdf 461→463.

## [1.0.1] - 2026-06-11

### Changed

- **Minimum PHP raised to 8.2** (`composer.json` now requires `^8.2`). PHP 8.1
  is end-of-life (security support ended November 2025) and the dev test
  toolchain (`pestphp/pest` → `brianium/paratest`) no longer installs on 8.1,
  so the v1.0.0 `^8.1` claim could not actually be built or CI-verified on 8.1.
  The library's runtime behaviour is unchanged and remains compatible with PHP
  8.2 / 8.3 / 8.4; the only consumer
  (`accredifysg/verifiable-credentials-php`) already requires `^8.2`. The CI
  matrix drops its 8.1 leg accordingly.

### Fixed

- Removed four redundant `is_string()` sub-conditions in
  `Context\TermDefinitions` term-definition validation that PHPStan 2.2 reports
  as always-true (`booleanAnd.rightAlwaysTrue`) — the `@id` and `@type` offsets
  are already proven to be strings by the earlier validation guards. Behaviour
  is unchanged (each condition was true whenever it was reached); this only
  restores a green `phpstan analyse` (level max) under the current PHPStan
  release that CI resolves to. Conformance is unchanged (W3C 1084/1098).

## [1.0.0] - 2026-06-11

First stable release. The public API is now covered by
[Semantic Versioning](https://semver.org/spec/v2.0.0.html) — breaking changes
bump the major version.

No library code changed since v0.69.0; this release stabilises the public API
and finalises the documentation. Conformance is therefore identical to
v0.69.0, re-verified against the W3C JSON-LD 1.1 test suite at release time:

```
            expand     compact    toRdf      total
v1.0.0:    378/385    246/246    460/467    1084/1098
```

### Delivered

- Spec-compliant JSON-LD 1.1 **Expansion** (§5.5), **Compaction** (§5.6 —
  246/246, 100% of the W3C compact suite), and **Serialize to RDF** /
  `toRdf` (§7).
- Pluggable `DocumentLoader` with no mandatory HTTP-client dependency; bundled
  `HttpDocumentLoader` and `CachingDocumentLoader` for consumers that want one.
- N-Quads output including `rdfDirection`, `produceGeneralizedRdf`, and the
  JSON Canonicalization Scheme (RFC 8785) for `@json` literals.

### Known limitations

- The 14 residual W3C failures (7 in the expand manifest, 7 in toRdf) trace to
  the **same 7 test IDs** and are environment- or spec-accommodation blockers,
  not capability gaps: `#tc031` (offline relative URL), `#tc032`/`#tc033`
  (unused-context error), `#te128` (shared-context circular ref), `#ter56` (VC
  `@context`-term accommodation), `#tin06` (`json.api`), `#tjs10` (PHP
  `json_decode` `{}`-vs-`[]`).
- Flattening, Framing, and RDF-to-JSON-LD (`fromRdf`) are out of scope for the
  1.x line.

## [0.69.0] - 2026-06-10

toRdf harness: compare RDF datasets as sets (de-duplicate quads).

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.68.0:      378      246      459
v0.69.0:      378      246      460   (+1 toRdf)
```

### Fixed (test harness)

- The toRdf comparison now de-duplicates quads before comparing, matching the
  RDF data model (a dataset is a *set* of quads, compared up to isomorphism).
  The same value reached through two `@index` keys yields the same triple once
  its `@index` is dropped in RDF; our `toNQuads` already serialises a proper
  de-duplicated set, but the harness's line-multiplicity comparison wrongly
  expected the reference fixture's duplicate lines (`#te036`). No library code
  changed — the processor's toRdf output is unchanged and remains a valid
  (de-duplicated) RDF dataset.

The remaining 7 toRdf failures are all genuine environment/spec-accommodation
blockers — the same set as expansion's tail: `#tc031` (offline relative URL),
`#tc032`/`#tc033` (unused-context error), `#te128` (shared-context circular
ref), `#ter56` (VC `@context`-term accommodation), `#tin06` (json.api),
`#tjs10` (PHP `json_decode` `{}`-vs-`[]`).

## [0.68.0] - 2026-06-10

toRdf: JSON Canonicalization Scheme for `@json` literals.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.67.0:      378      246      458
v0.68.0:      378      246      459   (+1 toRdf)
```

### Fixed (toRdf, §7.3 — `@json`)

- `@json` literals are now serialised per the JSON Canonicalization Scheme
  (RFC 8785): numbers use the ECMAScript Number-to-String form, so an
  exponential mantissa drops a redundant `.0` (`1e30` → `1e+30`, not
  `1.0e+30`) (`#tjs12`). `canonicalJson` is now a recursive encoder that
  delegates string/structure serialisation to `json_encode` (escaping
  unchanged) and formats only numbers itself; object keys remain sorted.

## [0.67.0] - 2026-06-10

toRdf: empty node objects become blank nodes; `@id`-vanished nodes are dropped.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.66.0:      378      246      456
v0.67.0:      378      246      458   (+2 toRdf)
```

### Fixed

- A genuinely-empty node object (`{}` — e.g. a node whose only property is
  decoupled by a `@context: null` reset) now serialises as a fresh blank node
  referenced by its parent, instead of being silently iterated away in node-map
  generation (`#te016`, `#tpr06`). PHP renders both `{}` and `[]` as an empty
  array, so the node-map builder now treats an empty-array *value item* as an
  empty node object while an empty value *list* still emits nothing.
- Distinct from the above: a node object carrying an `@id` that expanded to
  null (e.g. a keyword-shaped `@ignoreMe`) and having no other content is NOT a
  blank node — the value is dropped during expansion, since the intended
  identifier is simply absent (`#te122`, which previously passed only by
  coincidence of the empty node being discarded).

## [0.66.0] - 2026-06-10

toRdf: reject double-fragment (non-well-formed) IRIs.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.65.0:      378      246      454
v0.66.0:      378      246      456   (+2 toRdf)
```

### Fixed (toRdf, §7.1)

- A statement whose IRI (predicate, subject, object, or graph name) contains
  more than one `#` is dropped — an IRI has at most one fragment. This arises
  when a property is appended to a `#`-terminated relative `@vocab` (e.g.
  `#frag` under `@vocab: "./rel#"` → `…/rel##frag`), which the well-formedness
  check in `isAbsoluteIri` now rejects (`#te111`, `#te112`).

## [0.65.0] - 2026-06-10

toRdf: drop literals with malformed language tags.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.64.0:      378      246      453
v0.65.0:      378      246      454   (+1 toRdf)
```

### Fixed (toRdf, §7.3)

- A language-tagged literal whose `@language` is not a well-formed BCP47
  language tag (e.g. `"a b"`, which contains a space) is now dropped — the
  statement carries no valid RDF language — instead of emitting an invalid
  N-Quads literal (`#twf05`).

## [0.64.0] - 2026-06-10

toRdf harness: sound blank-node isomorphism comparison.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.63.0:      378      246      443
v0.64.0:      378      246      453   (+10 toRdf)
```

### Fixed (test harness)

- The toRdf conformance comparison gains a **sound** RDF-dataset isomorphism
  fallback. The existing order-based blank-node canonicalisation
  (`normaliseNQuads`) is correct only for graphs without blank-node symmetry;
  the `@graph`-container fixtures produce symmetric implicit named graphs whose
  isomorphic-but-relabelled output it mislabelled. The new check does
  signature-pruned backtracking over the blank-node bijection and verifies the
  relabelled quad MULTISET exactly, so it can only rescue a heuristic
  mismatch — never pass a genuinely different dataset. This corrected
  10 false-failures whose RDF output was already spec-correct: the eight
  `@graph`-container tests (`#te093`/`#te094`/`#te096`/`#te097`/`#te098`/
  `#te104`/`#te105`/`#te107`), `#tdi03` (list `@direction`), and `#tpr10`
  (protected terms).

No library code changed — this is a test-infrastructure correctness fix; the
processor's toRdf output is byte-identical.

## [0.63.0] - 2026-06-10

toRdf: the `produceGeneralizedRdf` option (§7.1).

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.62.0:      378      246      441
v0.63.0:      378      246      443   (+2 toRdf)
```

### Added

- `produceGeneralizedRdf` is now honoured by `toRdf`: a blank-node predicate is
  emitted as a generalized-RDF statement instead of being dropped (`#t0118`,
  `#te075`). Threaded through `JsonLdProcessor::toRdf()` and the W3C harness
  adapter. The option defaults to `false`, so default output (valid RDF only)
  is byte-unchanged.

## [0.62.0] - 2026-06-10

Expansion: the `expandContext` option, context reset, scoped `@nest`.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.61.0:      372      246      436
v0.62.0:      378      246      441   (+6 expand, +5 toRdf)
```

### Added

- `JsonLdOptions::$expandContext` (§10.3): a context applied BEFORE the
  document's own `@context` when expanding — a context map, a
  `{"@context": …}` wrapper, or a remote context IRI — threaded through
  `expand()`/`toRdf()` and the W3C harness adapter (`#t0077`).

### Fixed (expansion)

- `{"@context": null}` inside a node resets the ENTIRE active context — terms,
  containers and all — so unmapped terms drop and container coercions vanish
  (`#t0016`), and the base resets to the original document base rather than an
  inherited scoped `@base` (`#t0060`).
- `{"@context": {"@base": null}}` clears the base entirely, leaving relative
  IRIs relative in the output (`#t0060`).
- `@propagate: false` on a property-scoped context with `@import` is honoured
  (`#tso06`).
- A term aliased to `@nest` with its own property-scoped `@context` applies
  that context to the nested values — the scoped context is no longer lost in
  the deferred second pass (`#tc037`; the Bibframe example `#tc038` lands as a
  bonus).

The remaining 7 expand failures are the documented environment/spec-
accommodation blockers (`#t0122` non-normative, `#t0128` shared-context
circular ref, `#tc031` offline relative URLs, `#tc032`/`#tc033` unused-context
errors, `#ter56` VC `@context`-term accommodation, `#tin06` json.api).

## [0.61.0] - 2026-06-10

**Compaction reaches 100% of the W3C compact suite (246/246).**

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.60.0:      372      238      436
v0.61.0:      372      246      436   (+8 compact — the full suite)
```

### Fixed (compaction — final cluster)

- Inline contexts supplied as part of the compaction context document are
  applied when compacting (`#t0007`).
- Index-map round-tripping: container-map entries preserve their original
  shapes through compact→expand→compact (`#t0038`, `#ta038`).
- An `@id` exactly equal to the document base relativises to `./` per
  RFC 3986 (`IriResolver::relativize` edge case, `#t0076`).
- A graph object WITH `@id` does not enter a `[@graph, @index]` container map;
  it falls back to the (aliased) full form (`#t0083`).
- A scoped `@context` given as an array including `@set`-bearing definitions
  applies its array values correctly (`#ts002`).
- NEW error conditions: compacting throws "IRI confused with prefix" when an
  absolute IRI collides with a defined prefix term (`#te002`), and a
  type-scoped context illegally overriding a protected term is rejected
  during compaction context activation (`#tpr03`).
- Expansion's compact-IRI step gains the matching prefix-candidate guard
  (no expand/toRdf behaviour change — both suites byte-identical).

## [0.60.0] - 2026-06-10

Compaction: reverse-map value-aware terms, `@json` arrays, property-valued
index `@none`, `@list`+`@index`, and list/direction-aware selection.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.59.0:      372      230      436
v0.60.0:      372      238      436   (+8 compact)
```

### Fixed (compaction)

- `@reverse`-map values now get per-value term selection, mirroring the forward
  path: each node ref picks the term whose coercion fits it (`@type: @id` vs
  `@type: @vocab`), so one reverse property can split across two terms
  (`#t0044`).
- A `@json` literal whose `@value` is an array reaches the output verbatim —
  it is no longer iterated as list items (which double-nested it under
  `@container: @set` and dropped its content) (`#tjs07`).
- A property-valued `@index` entry left as a sole-`@id` node ref collapses to a
  bare IRI string ONLY when the index property was absent from the entry (the
  `@none` key case); entries that kept their index property stay objects
  (`#tpi05`, with `#tpi03`/`#tpi04` guarded).
- A `{@list, @index}` value declines a `@container: @list` term (the container
  form would drop the `@index`) and falls back to the full-IRI key (`#t0041`).
- List/direction-aware selection: a list term coercing a `@direction` the
  items don't share scores zero, a direction-matching list term wins
  (`#tdi03`); a `@container: @list` term never matches a non-list value
  (`#t0018`); a value that cannot live in a term's `@language` map —
  mismatched `@direction` or a stray `@index` — declines it (`#tdi07`,
  `#t0065`). `valueSignature` now tracks the items' common `@direction`.

## [0.59.0] - 2026-06-10

Compaction: inverse-context term selection core (§5.6.2/§5.7).

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.58.0:      372      225      436
v0.59.0:      372      230      436   (+5 compact)
```

### Fixed (compaction, §5.6.2 / §5.7)

- `buildInverse` now resolves a term whose `@id` is itself a bare TERM NAME
  through the referenced term's definition (self-reference guarded), falling
  back to `@vocab` concatenation — so `container: {"@id": "label"}` indexes
  under the IRI `label` maps to (`#t0027`, `#t0089`, bonus `#tc003`).
- A term with no explicit `@id` whose NAME is compact-IRI-shaped
  (`"ex:vocab/date": {…}`) takes its IRI mapping from its own expansion and is
  now indexed, so its exact inverse match outranks `@vocab`-stripping (`#t0023`).
- `compactIri` now prefers `@vocab`-stripping over forming a NEW compact IRI
  via a prefix term, per the §5.7 ordering (`#t0023`); an exact inverse term
  still wins over both.
- `scoreCandidate` gains container-preference branches: a `@container: @set`
  term wins for multi-valued properties (threaded via a `multiValued` flag),
  and a term with a mismatching `@language` coercion scores below plain terms.
- `selectTerm` declines a candidate set whose only terms carry a `@language`
  coercion that mismatches the value's language — the value falls back to the
  full-IRI key as a value object instead of lossily absorbing the language
  (`#t0017`).

## [0.58.0] - 2026-06-09

Compaction: reverse terms with containers + property-valued index resolution.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.57.0:      372      223      436
v0.58.0:      372      225      436   (+2 compact)
```

### Fixed (compaction)

- A reverse-property term that itself carries a container (`@container: @index`,
  including a property-valued index, or `@graph`) now routes its reverse values
  through the same container-map machinery as a forward property, producing an
  index map instead of a flat array (`#t0036`, `#t0114`).
- A property-valued `@index`'s index property is now resolved through the term
  definition (`resolveTypeMapping`), so a defined term such as
  `predicate → rdf:predicate` wins over a bare `@vocab` concatenation (`#t0114`).

### Known remaining

- `#t0044` (`@type: @vocab` in a reverse map) still needs value-aware *per-value*
  term selection inside the `@reverse` map (each node ref choosing between an
  `@type: @id` and an `@type: @vocab` term); deferred to a focused follow-up.

## [0.57.0] - 2026-06-09

Compaction: the `compactArrays` option (§5.6.2).

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.56.0:      372      220      436
v0.57.0:      372      223      436   (+3 compact)
```

### Added

- `JsonLdOptions::$compactArrays` (default `true`), threaded through
  `JsonLdProcessor::compact()` into `Compaction` and the W3C harness adapter.

### Fixed (compaction, §5.6.2)

- When `compactArrays` is `false`, single-element arrays are no longer unwrapped
  to scalars/objects and `@graph`/top-level array wrappers are kept verbatim
  (`#t0070`, `#t0091`, `#t0093`). A `@container: @list` term still unwraps its
  single `{@list}` element internally (that is list-content delivery, not the
  `compactArrays` collapse). All four unwrap sites are individually gated, so
  the default (`true`) behaviour is byte-unchanged.

## [0.56.0] - 2026-06-09

Expansion: reverse-property term with `@container: @index`.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.55.0:      370      220      435
v0.56.0:      372      220      436   (+2 expand, +1 toRdf)
```

### Fixed (expansion, §5.5)

- A reverse-property term (`{"@reverse": "…"}`) that also carries
  `@container: @index` now expands its value as an index map — the entries
  become the reverse values with their `@index` attached — instead of
  mis-expanding the index map as a node object and producing an empty result
  (`#t0063` plain `@index`, `#t0131` property-valued `@index`; `#te063` toRdf
  twin). A property-scoped `@context` is intentionally not layered here, as no
  legal reverse term shape combines `@index` with `@context`.

## [0.55.0] - 2026-06-09

Compaction: `@index` on value objects + `@included` `@set`.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.54.0:      370      218      435
v0.55.0:      370      220      435   (+2 compact)
```

### Fixed (compaction)

- A value object's `@index` is now preserved when its property is NOT an
  `@index` container (an index container consumes `@index` as the map key
  before value compaction); previously the rebuild dropped it (`#t0030`).
- An `@included` entry whose term carries `@container: @set` now stays an array
  instead of unwrapping a single member (`#tin01`).

## [0.54.0] - 2026-06-09

Compaction: `@type`-map key scoped context + `@index` on `@list` objects.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.53.0:      370      216      435
v0.54.0:      370      218      435   (+2 compact)
```

### Fixed (compaction)

- A `@type`-container map entry is now compacted with the map-key type term's
  type-scoped `@context` active, so the entry's properties compact through the
  scoped term definitions (`#tm007` — the compaction twin of expansion's
  `#tm008`).
- A `@list` object that also carries an `@index` (and whose term is not a
  `@container: @index`) now keeps the index as a sibling of the (aliased)
  `@list` key, instead of dropping it (`#t0042`).

## [0.53.0] - 2026-06-09

Compaction: type-scoped `@context` activation (scoped `@vocab` + list-form
layers).

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.52.0:      370      213      435
v0.53.0:      370      216      435   (+3 compact)
```

### Fixed (compaction, §5.6 — type-scoped contexts)

- A type-scoped `@context`'s `@vocab` is now applied when compacting a node's
  other properties (`#tc016`): the `@type` values that trigger the scope still
  compact through the outer `@vocab` (they resolve via an explicit inverse term,
  which is consulted before `@vocab` stripping), while sibling properties use
  the scoped `@vocab`.
- A type-scoped `@context` given as a LIST of layers (`[{…}]`, `[null, {…}]`)
  is now applied layer-by-layer; previously the list form was silently skipped
  (its integer keys were treated as non-string), so scoped term definitions
  never reached the active context (`#tc017`/`#tc018`).

## [0.52.0] - 2026-06-09

Compaction: the §5.7 compact-IRI prefix flag.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.51.0:      370      210      435
v0.52.0:      370      213      435   (+3 compact)
```

### Fixed (compaction, §5.7)

- A compact IRI (`term:suffix`) is now only formed from a *prefix-eligible*
  term. A term definition is a prefix when it is a simple string definition
  whose IRI mapping ends with a gen-delim (`:/?#[]@`), or when it carries an
  explicit `@prefix: true`. An expanded (object) term definition without
  `@prefix` (`#tp001` in 1.0, `#tp002` in 1.1) or one flagged `@prefix: false`
  (`#tp008`) keeps the full IRI.
- The prefix flag is computed at term-definition time and stored on the
  definition, so it travels through scoped-context copies. Expansion's
  (lenient) compact-IRI handling — which only blocks an explicit
  `@prefix: false` — is intentionally left unchanged, so this is a
  compaction-only tightening (expand/toRdf unaffected; VC characterization
  fixtures remain byte-equivalent).

## [0.51.0] - 2026-06-09

Expansion: lexicographic key processing + property-valued `@index` on graph
objects.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.50.0:      366      210      434
v0.51.0:      370      210      435   (+4 expand, +1 toRdf)
```

### Fixed (expansion)

- Object keys are now processed in lexicographic (code-point) order, so arrays
  accumulated from sibling keys that map to the same property are
  deterministically ordered: a literal `@type` keyword before a `type` alias
  (`#tpr30`), values across multiple `@nest` aliases (`#tn004`), and language
  maps with a colliding property (`#t0035`). Object-key output order is not
  significant to consumers (compaction/toRdf are unaffected; the VC
  characterization fixtures remain byte-equivalent).
- A property-valued `@index` now applies even when the container also wraps
  entries in a graph object (`@container: ["@graph", "@index"]`): the index
  property is attached to the wrapped graph object (`#tpi11`).

## [0.50.0] - 2026-06-09

Expansion: container-map index, `@vocab`-as-term, `@prefix:false`, keyword-shaped
`@reverse` (each gains its toRdf twin).

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.49.0:      362      210      431
v0.50.0:      366      210      434   (+4 expand, +3 toRdf)
```

### Fixed (expansion)

- An `@index`-map entry that already carries its own `@index` keeps it; the map
  key no longer overrides the entry's explicit `@index` (`#t0036`, mirrors the
  `#tm002` `@id`-map rule).
- `@vocab` whose value is itself a defined term is IRI-expanded via that term
  (vocab-style), e.g. `@vocab: "ex"` where `ex → http://example.org/` resolves
  subsequent terms against `http://example.org/` (`#t0125`, §4.1.2 step 5.2).
- A term explicitly flagged `@prefix: false` is no longer usable as a
  compact-IRI prefix, so `tag:champin.net,2019:prop` stays a literal absolute
  IRI rather than expanding via `tag` (`#tpr29`).
- A term whose `@reverse` value has the FORM of a keyword (but is not a real
  keyword) is ignored entirely, so the term falls back to `@vocab` and its value
  expands as an ordinary forward property (`#tpr39`).

## [0.49.0] - 2026-06-09

Type-map expansion cluster (each gains its toRdf twin).

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.48.0:      358      210      427
v0.49.0:      362      210      431   (+4 expand, +4 toRdf)
```

### Fixed (expansion, §5.5 — `@type` / `@id` container maps)

- An `@id`-map entry that already carries its own `@id` keeps it; the map key
  no longer overrides the entry's explicit `@id` (`#tm002`, step 13.8.3.7.4).
- A `@type`-map entry is now expanded with the type (map-key) term's
  type-scoped `@context` active, so the entry sees the type's term definitions
  (`#tm008`).
- A `@type`-map / `@id`-map builds its map context from the *previous* context
  (the context before the type-scoped context that introduced the map
  property), so entries do not inherit the containing object's type-scoped term
  redefinitions (`#tc013`, step 13.8.3.1).
- A string entry of a `@type` map expands to a node reference (`@id`): against
  `@base` (document-relative) by default (`#tm017`), or against `@vocab` when
  the term is `@type: @vocab` (`#tm019`).

## [0.48.0] - 2026-06-09

Expansion clusters (each gains its toRdf twin). Start of the push to 100%.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.47.0:      352      210      423
v0.48.0:      358      210      427   (+6 expand, +4 toRdf)
```

### Fixed (expansion, §5.5)

- Free-floating items are dropped at the top level / in `@graph`: a value
  object, a node with only `@id`, or a `@list` object carries no statement and
  is removed (`#t0045`/`#t0046`/`#t0047`, toRdf twins).
- A term `@id` with the FORM of a keyword (`@` + letters, e.g. `@ignoreMe`) but
  that is not a real keyword is ignored, so the term falls back to `@vocab`
  (`#t0120`/`#tpr37`); `@` alone and `@foo.bar` are not keyword-shaped and are
  kept.
- A scalar value of a `@container: @list` property now expands to a `{@list:…}`
  object (`#t0004`).

## [0.47.0] - 2026-06-09

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.46.0:      352      208      423
v0.47.0:      352      210      423   (+2 compact)
```

### Fixed (compaction, §5.6)

- `@type` whose term carries `@container: @set` now stays an array even for a
  single value (`#t0104`/`#t0105`) — a JSON-LD 1.1 feature; under JSON-LD 1.0
  it is ignored and the scalar is kept (`#t0106` preserved).

### Notes

- A `@prefix`-validity fix (a map-defined term without `@prefix: true`, or
  `@prefix: false`, may not form a compact IRI — `#tp002`/`#tp008`) was
  attempted and reverted: a naive "string-def or `@prefix:true`" eligibility
  rule regressed 19 tests (many contexts legitimately use map-defined terms as
  prefixes). Needs the precise §4.2.2 prefix-flag rule (gen-delim / simple
  term); deferred.

## [0.46.0] - 2026-06-09

Expand-first compaction + the fixes it unlocks. The largest compaction jump.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.45.0:      352      197      423
v0.46.0:      352      208      423   (+11 compact)
```

### Fixed (compaction, §5.6)

- `JsonLdProcessor::compact` now EXPANDS its input first (per §5.6 compaction
  operates on an expanded document), so a document carrying its own `@context`
  (e.g. `@container` definitions) is normalised before compaction. Fixes
  free-floating-node dropping (`#t0001`/`#t0003`/`#t0004`), multiple `@context`
  (`#t0071`), `@set` array contexts (`#ts001`), `@type:@vocab` relative
  (`#t0062`), and the list-of-lists negative (`#te001`).
- Sibling-property compaction is now order-independent: the active context is
  snapshotted/restored around EVERY property's value compaction, so a
  type-scoped-context rollback consumed inside a nested-node value no longer
  leaks into later siblings (`#tc015`/`#tc019`). This was the regression that
  blocked expand-first.
- A language-less string value is no longer collapsed to a bare scalar when an
  effective default/term `@language` is active (which would wrongly imply that
  language on round-trip) — the value object is kept (`#t0072`).
- A SIMPLE graph (a node with only `@graph`) unwraps a single member to a bare
  object, while a NAMED graph (node with `@id` + `@graph`) keeps `@graph` an
  array (`#t0090`/`#t0092`/`#t0094`, `#t0039`/`#t0016` preserved).

## [0.45.0] - 2026-06-09

IRI relativisation in compaction — `@id` values are now expressed as relative
references against the document base.

W3C JSON-LD 1.1 test suite (corrected `toEqual` gate):

```
            expand   compact   toRdf
v0.44.0:      352      193      423
v0.45.0:      352      197      423   (+4 compact)
```

### Added

- `IriResolver::relativize($base, $iri)` — the inverse of `resolve`: produces
  the shortest relative reference (RFC 3986), e.g. `../parent`, `#frag`,
  `?query`. Returns the IRI unchanged when it can't be relativised (no base, or
  a differing scheme/authority). A leading segment that could be misread as a
  keyword (`@…`) or a scheme/compact IRI (`x:…`) is prefixed with `./`.

### Fixed (compaction, §5.6)

- Non-vocab IRI compaction (`@id`, `@type:@id` values) now relativises against
  the document base instead of only stripping a literal prefix (`#t0045`,
  `#t0066`, `#t0111`, `#t0037`).
- The document base (`JsonLdOptions::$base`) is now threaded into compaction
  (`JsonLdProcessor::compact` previously passed `null`), and the W3C compaction
  harness defaults it to the test document URL — matching expansion / toRdf.

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

[Unreleased]: https://github.com/accredifysg/php-json-ld/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/accredifysg/php-json-ld/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/accredifysg/php-json-ld/compare/v0.69.0...v1.0.0
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
