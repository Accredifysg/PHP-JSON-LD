# PHP-JSON-LD

<!-- Badges (placeholders — wired up in PR 1.2 / Phase 6) -->
<!-- ![CI](https://img.shields.io/github/actions/workflow/status/accredifysg/php-json-ld/ci.yml?branch=main) -->
<!-- ![Packagist Version](https://img.shields.io/packagist/v/accredifysg/php-json-ld) -->
<!-- ![PHP Version](https://img.shields.io/packagist/php-v/accredifysg/php-json-ld) -->
<!-- ![License](https://img.shields.io/packagist/l/accredifysg/php-json-ld) -->
<!-- ![JSON-LD 1.1 Compliance](https://img.shields.io/badge/JSON--LD%201.1-partial-green) -->

A PHP implementation of the [JSON-LD 1.1](https://www.w3.org/TR/json-ld11/) specification.

> **Status: stable.** The public API is stable and the project follows
> [Semantic Versioning](https://semver.org/) — breaking changes bump the major
> version. The package delivers spec-compliant JSON-LD 1.1 **Expansion**,
> **Compaction**, **Serialize to RDF** (`toRdf`), **Flattening**,
> **RDF-to-JSON-LD** (`fromRdf`), and **Framing**, validated against the
> official W3C JSON-LD 1.1 test suites — see the [compliance matrix](#compliance)
> for per-algorithm conformance and the [CHANGELOG](CHANGELOG.md) for the
> enumerated residual blockers.

## Goals

- Faithful implementation of the JSON-LD 1.1 algorithms defined in the
  [JSON-LD 1.1 Processing Algorithms and API](https://www.w3.org/TR/json-ld11-api/).
- Pluggable document loader so consumers (e.g. verifiable-credential libraries)
  can serve known `@context` URLs from local resources.
- No mandatory HTTP client dependency — bring your own (Guzzle is suggested,
  not required).
- Tested against the [official W3C JSON-LD test suite](https://github.com/w3c/json-ld-api).

## Scope (delivered)

- [x] Custom `DocumentLoader` interface
- [x] Expansion (§5.5) — **381/385 of the W3C expand suite** (the remaining 4 are environment/spec-accommodation blockers)
- [x] Compaction (§5.6) — **246/246 of the W3C compact suite (100%)**: container-maps (incl. property-valued `@index`, `@type`-map node refs), keyword recursion, `@nest`, nested `@list`, `@graph` maps, `@reverse` (incl. per-value term selection and containers), full inverse-context term scoring (list/direction-aware, `@json` literals), property-/type-scoped contexts with scoped `@base`/`@vocab`, IRI relativisation, expand-first normalisation, the `compactArrays` option, §5.7 prefix rules and error conditions
- [x] Serialize JSON-LD to RDF (§7 / `toRdf`) — **463/467 of the W3C toRdf suite** (the remaining 4 are environment/spec-accommodation blockers); N-Quads output incl. `rdfDirection`, `produceGeneralizedRdf`, and JCS `@json` canonicalization
- [x] Flattening (§4.6 / `flatten`) — **58/58 of the W3C flatten suite (100%)**; folds named graphs into `@graph`, with optional compaction when a context is supplied
- [x] RDF to JSON-LD (§4.9 / `fromRdf`) — **49/53 of the W3C fromRdf suite**; a built-in dependency-free N-Quads parser, `useNativeTypes` / `useRdfType`, `rdf:first`/`rest`/`nil` list reconstruction, and `@json` literals (remaining: list-of-lists conversion + two non-normative compound-literal cases)
- [x] Framing (`frame`) — **89/92 of the W3C json-ld-framing suite**; merged/default-graph framing, `@type`/`@id`/value/node-pattern matching, wildcard `{}` vs `match none` `[]`, `@embed` (`@once`/`@never`/`@always`), `@explicit`, `@default`/`@omitDefault`/`@requireAll`, `@graph`/`@included`/`@reverse` framing (incl. `@container:@graph`), blank-node pruning, and `omitGraph` (remaining 3 are compaction safe-mode strictness, `@language` case-normalization, and the legacy `@embed: @last`)

> Suite numbers from v0.42.0 use the spec-accurate `toEqual` comparison
> (object-key order insignificant, array order significant); earlier numbers
> used a looser comparison and are not directly comparable — see the CHANGELOG.

A frame's `{}` wildcard cannot be represented in PHP's associative-array model
(it decodes the same as `[]`), so the framing API treats an empty frame value as
`match none` and accepts the documented `Expansion::FRAME_WILDCARD` sentinel for
a wildcard.

## Installation

```bash
composer require accredifysg/php-json-ld:^1.0
```

Requires PHP 8.2+. You also need a PSR-18 HTTP client + PSR-17 request
factory if you use the bundled `HttpDocumentLoader` (e.g. `guzzlehttp/guzzle`
and `guzzlehttp/psr7`), or you can implement `DocumentLoader` yourself to
serve `@context` URLs from wherever you like.

## Usage

```php
use Accredify\JsonLd\JsonLdProcessor;
use Accredify\JsonLd\Loaders\CachingDocumentLoader;
use Accredify\JsonLd\Loaders\HttpDocumentLoader;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$loader = new CachingDocumentLoader(
    new HttpDocumentLoader(new Client, new HttpFactory),
);

$processor = new JsonLdProcessor($loader);
$expanded = $processor->expand($yourDocument)->toArray();
```

If you want to serve known contexts from local files (recommended for
verifiable credentials), implement `Accredify\JsonLd\Contracts\DocumentLoader`
yourself. See
[`tests/Algorithms/Characterization/Support/BundledContextLoader.php`](tests/Algorithms/Characterization/Support/BundledContextLoader.php)
for an example.

## Compliance

The package is tested against the
[official W3C JSON-LD 1.1 test suite](https://github.com/w3c/json-ld-api),
pulled in as a git submodule at `tests/w3c/`. See
[tests/W3c/README.md](tests/W3c/README.md) for harness layout and how to
run it.

```bash
# Run only the project's unit tests (the default)
composer test

# Run the W3C conformance suite
composer test:w3c
```

### Conformance matrix

| Algorithm                  | Spec        | W3C suite | Passing | Conformance              |
| -------------------------- | ----------- | --------: | ------: | ------------------------ |
| Expansion                  | §5.5        |       385 |     381 | 4 documented blockers    |
| Compaction                 | §5.6        |       246 |     246 | **100%**                 |
| Serialize to RDF (`toRdf`) | §7          |       467 |     463 | 4 documented blockers    |
| Flattening                 | §4.6        |        58 |      58 | **100%**                 |
| RDF to JSON-LD (`fromRdf`) | §4.9        |        53 |      49 | 4 documented blockers    |
| Framing                    | framing §4  |        92 |      89 | 3 documented blockers    |

**Totals: 1286 / 1301 passing** across the six manifests; Compaction and
Flattening are fully conformant. The 15 residual non-conformances (4 in expand,
4 in toRdf, 4 in fromRdf, 3 in framing) are mostly negative-test / non-normative /
environment limits:

- `#tc032` / `#tc033` — *unused* embedded contexts aren't validated (negative tests)
- `#ter56` — redefining the `@context` keyword isn't rejected (negative test)
- `#t0122` (expand only) — keyword-shaped (`@`) IRIs are dropped rather than kept as `{@id: null}` (non-normative)
- `#tjs10` (toRdf only) — JSON-literal structural canonicalization differs
- `#t0008` / `#tli03` (fromRdf) — list-of-lists conversion (the 1.0 shape / nested ordering); single-level lists are fully supported
- `#tdi11` / `#tdi12` (fromRdf, non-normative) — compound-literal direction folding
- `#t0010` (framing) — compaction safe-mode rejects `dcterms:creator` as an IRI confused with the `dcterms` prefix (the reference processor errors here too)
- `#t0045` (framing) — `@language` case-normalization (expansion preserves case, which the consensus-critical `toRdf` bytes depend on)
- `#t0059` (framing) — the legacy `@embed: @last` mode (the `@once` default is implemented)

These are carried as an explicit expected-failure allowlist
([`tests/W3c/KnownBlockers.php`](tests/W3c/KnownBlockers.php)) so the
conformance suite stays green and **gates CI** — any new regression, or a
listed blocker that starts passing, fails the build. See the
[CHANGELOG](CHANGELOG.md) and
[tests/W3c/README.md](tests/W3c/README.md) for the full release-over-release
history.

> Counts use the spec-accurate `toEqual` comparison (object-key order
> insignificant, array order significant) introduced in v0.42.0; earlier
> figures used a looser comparison and are not directly comparable.

### Characterization fixtures

`tests/Algorithms/Characterization/` holds JSON snapshots of the expander's
output, generated from running the original
`accredifysg/verifiable-credentials-php` JsonLdProcessor over a set of
sample documents. They are NOT a spec-conformance reference — they pin
the package's behaviour so that any change to expansion output lands as a
reviewable diff.

When a PR changes expansion in a way that updates these fixtures, the
update should be reviewed for correctness and paired with any matching
change in downstream consumers (e.g. VC's signed-credential test
fixtures).

## License

[MIT](LICENSE) © Accredify
