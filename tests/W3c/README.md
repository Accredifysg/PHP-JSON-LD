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
        ├── CompactionTest.php      # still NullProcessor (PR 4.9)
        └── ToRdfTest.php           # still NullProcessor (PR 4.10)
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
Expansion:    113 passed / 272 failed (v0.2.0)
Compaction:   skipped (PR 4.9 pending)
toRdf:        skipped (PR 4.10 pending)
```

Each Phase 4 PR:
1. Re-runs `composer test:w3c`.
2. Records the before / after `passed` count in the commit message.
3. Never regresses the `passed` count.

### History

| Release | Expand passed | Δ      | Note                                  |
| ------- | -------------:| ------:| ------------------------------------- |
| v0.1.1  |           69  |    —   | Harness wired; lift-and-shift baseline |
| v0.2.0  |          113  |   +44  | Expansion rewrite (PR 4.1)            |

## Out of scope

The v1.0 scope is Expansion, Compaction, and toRdf. The harness deliberately
does **not** load `flatten-manifest.jsonld`, `fromRdf-manifest.jsonld`,
`html-manifest.jsonld`, or `remote-doc-manifest.jsonld`. Those manifests can
be added in follow-up PRs once the v1.0 scope is shipped.
