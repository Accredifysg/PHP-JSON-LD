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
    ├── NullProcessor.php           # default: throws NotImplemented
    ├── NotImplementedException.php
    └── Algorithms/
        ├── ExpansionTest.php
        ├── CompactionTest.php
        └── ToRdfTest.php
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

## Current baseline

Every test currently reports `skipped: not yet implemented`. As Phase 4 of
the [plan](../../C:\Users\Shaun\.claude\plans\can-you-write-the-noble-mist.md)
lands real algorithms, swap `NullProcessor` in the `Algorithms/*Test.php`
files for an adapter that delegates into `Accredify\JsonLd`.

Each PR in Phase 4 must:
1. Re-run `composer test:w3c`
2. Record the before / after `passed` count in the PR description
3. Never regress the passed count

## Out of scope

The v1.0 scope is Expansion, Compaction, and toRdf. The harness deliberately
does **not** load `flatten-manifest.jsonld`, `fromRdf-manifest.jsonld`,
`html-manifest.jsonld`, or `remote-doc-manifest.jsonld`. Those manifests can
be added in follow-up PRs once the v1.0 scope is shipped.
