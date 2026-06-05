# Changelog

All notable changes to `accredifysg/php-json-ld` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/accredifysg/php-json-ld/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/accredifysg/php-json-ld/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/accredifysg/php-json-ld/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/accredifysg/php-json-ld/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/accredifysg/php-json-ld/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/accredifysg/php-json-ld/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/accredifysg/php-json-ld/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/accredifysg/php-json-ld/releases/tag/v0.1.0
