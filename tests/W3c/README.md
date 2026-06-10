# W3C JSON-LD 1.1 Conformance Harness

This directory hosts the harness that runs the
[official W3C JSON-LD 1.1 test suite](https://github.com/w3c/json-ld-api)
against our package. The test suite itself is pulled in as a git submodule
at `tests/w3c/` (lowercase `c`) — these files (uppercase `C`) are the
harness that drives it.

## Layout

```
tests/
├── w3c/                            # submodule: w3c/json-ld-api
│   └── tests/
│       ├── expand-manifest.jsonld
│       ├── compact-manifest.jsonld
│       ├── toRdf-manifest.jsonld
│       └── …
└── W3c/                            # our harness (this directory)
    ├── Harness.php                 # reads + parses a manifest
    ├── TestCase.php                # value object per manifest entry
    ├── Processor.php               # adapter interface
    ├── NullProcessor.php           # legacy: throws NotImplemented
    ├── NotImplementedException.php
    ├── Support/
    │   ├── PhpJsonLdAdapter.php    # delegates to Accredify\JsonLd\JsonLdProcessor
    │   └── W3cDocumentLoader.php   # offline loader for W3C fixtures
    └── Algorithms/
        ├── ExpansionTest.php       # runs via PhpJsonLdAdapter
        ├── CompactionTest.php      # runs via PhpJsonLdAdapter
        └── ToRdfTest.php           # runs via PhpJsonLdAdapter (N-Quads)
```

## Running

```bash
# Initialise the submodule once
git submodule update --init --recursive

# Run only the project's unit tests (the default)
composer test

# Run the W3C conformance suite
composer test:w3c

# Run both
composer test:all
```

## Current score

```
Expansion:    372 passed /  13 failed (v0.58.0)
Compaction:   225 passed /  21 failed (v0.58.0)
toRdf:        436 passed /  31 failed (v0.58.0)
```

> v0.42.0 corrected the expand/compact comparison from `toEqualCanonicalizing`
> to `toEqual` (spec-accurate: object-key order insignificant, array order
> significant). Counts from v0.42.0 onward are not directly comparable to
> earlier rows, which used the looser (gate-inflated) comparison.

Each Phase 4 PR:
1. Re-runs `composer test:w3c`.
2. Records the before / after `passed` count in the commit message.
3. Never regresses the `passed` count.

### History

| Release | Expand passed | Δ      | Note                                  |
| ------- | -------------:| ------:| ------------------------------------- |
| v0.1.1  |           69  |    —   | Harness wired; lift-and-shift baseline |
| v0.2.0  |          113  |   +44  | Expansion rewrite (PR 4.1)            |
| v0.3.0  |          129  |   +16  | Container handling (PR 4.2)           |
| v0.4.0  |          126  |    -3  | Scope activation (PR 4.3) — see notes |
| v0.5.0  |          139  |   +13  | Value objects + @json (PR 4.4)        |
| v0.6.0  |          147  |    +8  | @reverse (PR 4.5)                     |
| v0.7.0  |          163  |   +16  | @base + relative IRIs (PR 4.6)        |
| v0.8.0  |          163  |     0  | Compaction added (67 compact passes)  |
| v0.9.0  |          170  |    +7  | Compact-IRI term keys (+5 compact too)|
| v0.10.0 |          170  |     0  | Compaction container-maps (+23 compact)|
| v0.11.0 |          175  |    +5  | @direction + default @language        |
| v0.12.0 |          177  |    +2  | Drop unmapped relative terms (§5.5 #13)|
| v0.13.0 |          191  |   +14  | toRdf (§7) + expansion gaps it surfaced|
| v0.14.0 |          201  |   +10  | array @container, @set unwrap, prop-index, null terms|
| v0.15.0 |          213  |   +12  | vocab-gated IRI expansion, value @type scalar, @vocab values, toRdf well-formed IRIs|
| v0.16.0 |          238  |   +25  | validation surface (negative tests): context + term-def + expansion error conditions|
| v0.17.0 |          244  |    +6  | colliding keywords, value @type well-formedness, @language-map, @reverse IRI|
| v0.18.0 |          247  |    +3  | @protected tracking + document-level redefinition enforcement|
| v0.19.0 |          259  |   +12  | active-context propagation: scoped @protected, @context:null reset, @propagate|
| v0.20.0 |          266  |    +7  | @import (document-level context sourcing)|
| v0.21.0 |          271  |    +5  | scoped remote/@import contexts + relative context refs|
| v0.22.0 |          275  |    +4  | term-def IRI-mapping validation (@type, bare term, @context alias)|
| v0.23.0 |          284  |    +9  | processing-mode (1.0/1.1) threading + gates (+9 toRdf too)|
| v0.24.0 |          309  |   +25  | container expansion: @graph family, map @none, missing-@context (+20 toRdf too)|
| v0.25.0 |          310  |    +1  | compaction 1.0 gates + keyword-alias + @type-coercion fix (+10 compact, +1 toRdf)|
| v0.26.0 |          310  |     0  | compaction buildout p1: keyword recursion, @nest, @graph wrap, value fixes (+31 compact)|
| v0.27.0 |          310  |     0  | compaction buildout p2: @graph container maps + @reverse (+21 compact)|
| v0.28.0 |          310  |     0  | compaction buildout p3: value-aware term selection + @language collapse (+6 compact)|
| v0.29.0 |          310  |     0  | compaction buildout p4: property-scoped contexts (+3 compact)|
| v0.30.0 |          310  |     0  | toRdf tail: RFC-3986 @base verbatim + blank-node-isomorphism compare (+10 toRdf)|
| v0.31.0 |          310  |     0  | JsonLdOptions value object (refactor; scores unchanged)|
| v0.32.0 |          310  |     0  | compaction buildout p5: type-scoped contexts + non-propagation (+6 compact)|
| v0.33.0 |          310  |     0  | rdfDirection toRdf modes (i18n-datatype / compound-literal) (+4 toRdf)|
| v0.34.0 |          315  |    +5  | expansion validation gates: @language/@container/@type:@none/@reverse/@list (+4 toRdf)|
| v0.35.0 |          324  |    +9  | IRI-consistency + protected-override + scoped-context propagation (+3 compact, +9 toRdf)|
| v0.36.0 |          325  |    +1  | protected @type redefinition (#tpr32) + scoped-@base compaction (+2 compact, +1 toRdf)|
| v0.37.0 |          329  |    +4  | cross-suite expansion: @type:@none, @type:@vocab doc-relative, @vocab:null reset, cyclic IRI (+4 toRdf)|
| v0.38.0 |          330  |    +1  | RDF value canonicalization: strict node-map dedup, num≥1e21 double, null @json (+5 toRdf)|
| v0.39.0 |          336  |    +6  | scoped-context expansion: @type pre-scoped context, propagate revert, scoped @base (+7 toRdf)|
| v0.40.0 |          337  |    +1  | term @id materialisation from @vocab at definition time (#tc010/#tc005/#tc035) (+3 toRdf)|
| v0.41.0 |          340  |    +3  | @direction in @language container maps (#tdi04/#tdi05/#tdi06)|
| v0.42.0 |          352  |   gate | gate→toEqual (spec-correct) + lexicographic container-map ordering + @nest two-pass; compact recalibrated 183→174 (honest)|
| v0.43.0 |          352  |    +0  | compaction term-selection: @vocab-collision, @vocab-relative @type, destructive-coercion guard (+4 compact)|
| v0.44.0 |          352  |    +0  | compaction features: property-valued @index, @type-map sole-@id, nested @list, @graph arrays (+15 compact)|
| v0.45.0 |          352  |    +0  | compaction IRI relativisation (@id against document base) + base threading (+4 compact)|
| v0.46.0 |          352  |    +0  | expand-first compaction + sibling-isolation + default-language + @graph simple/named (+11 compact)|
| v0.47.0 |          352  |    +0  | @type with @container:@set stays an array in 1.1 (+2 compact)|
| v0.48.0 |          358  |    +6  | expansion: free-floating drop, keyword-shaped @id, @list scalar wrap (+4 toRdf)|
| v0.49.0 |          362  |    +4  | expansion @type/@id maps: keep entry @id, type-scoped ctx, previous-context base, string→node-ref (+4 toRdf)|
| v0.50.0 |          366  |    +4  | expansion: @index keep-own, @vocab-as-term, @prefix:false, keyword-shaped @reverse (+3 toRdf)|
| v0.51.0 |          370  |    +4  | expansion: lexicographic key order (@type/@nest/lang accumulation) + property-valued @index on graph objects (+1 toRdf)|
| v0.52.0 |          370  |    +0  | compaction §5.7 compact-IRI prefix flag: only prefix-eligible terms form term:suffix (+3 compact)|
| v0.53.0 |          370  |    +0  | compaction type-scoped @context: scoped @vocab + list-form layers (+3 compact)|
| v0.54.0 |          370  |    +0  | compaction: @type-map key scoped context + @index on @list objects (+2 compact)|
| v0.55.0 |          370  |    +0  | compaction: @index on value objects + @included @set array (+2 compact)|
| v0.56.0 |          372  |    +2  | expansion: reverse-property term with @container:@index (+1 toRdf)|
| v0.57.0 |          372  |    +0  | compaction: compactArrays option (§5.6.2) threaded through options (+3 compact)|
| v0.58.0 |          372  |    +0  | compaction: reverse term with container -> index map + property-valued index via term def (+2 compact)|

### Notes on v0.4.0

The absolute count dipped by 3, but the architectural change was a
net win. v0.3.0's term lookup did recursive search through nested
`@context` entries, which accidentally made scoped terms findable as
if they were unscoped. v0.4.0 enforces strict scope: terms are only
visible when their scope (type-scoped or property-scoped) is active.
Several scope-specific tests now pass (`#tc009`, `#tc010`, `#tc011`,
etc.); a few tests that benefited from the leak now fail.

VC consumers see zero regression — the characterization fixtures are
byte-identical to v0.3.0.

## Out of scope

The v1.0 scope is Expansion, Compaction, and toRdf. The harness deliberately
does **not** load `flatten-manifest.jsonld`, `fromRdf-manifest.jsonld`,
`html-manifest.jsonld`, or `remote-doc-manifest.jsonld`. Those manifests can
be added in follow-up PRs once the v1.0 scope is shipped.
