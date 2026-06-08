# PHP-JSON-LD

<!-- Badges (placeholders — wired up in PR 1.2 / Phase 6) -->
<!-- ![CI](https://img.shields.io/github/actions/workflow/status/accredifysg/php-json-ld/ci.yml?branch=main) -->
<!-- ![Packagist Version](https://img.shields.io/packagist/v/accredifysg/php-json-ld) -->
<!-- ![PHP Version](https://img.shields.io/packagist/php-v/accredifysg/php-json-ld) -->
<!-- ![License](https://img.shields.io/packagist/l/accredifysg/php-json-ld) -->
<!-- ![JSON-LD 1.1 Compliance](https://img.shields.io/badge/JSON--LD%201.1-WIP-orange) -->

A PHP implementation of the [JSON-LD 1.1](https://www.w3.org/TR/json-ld11/) specification.

> **Status: Pre-1.0.** v0.1.0 ships the JSON-LD code extracted from
> [accredifysg/verifiable-credentials-php](https://github.com/accredifysg/verifiable-credentials-php).
> It is functionally complete for VCv2 / Open Badges v3 expansion but is
> **not yet spec-compliant** with JSON-LD 1.1 — see
> [CHANGELOG](CHANGELOG.md) for known limitations. The public API may change
> before v1.0.

## Goals

- Faithful implementation of the JSON-LD 1.1 algorithms defined in the
  [JSON-LD 1.1 Processing Algorithms and API](https://www.w3.org/TR/json-ld11-api/).
- Pluggable document loader so consumers (e.g. verifiable-credential libraries)
  can serve known `@context` URLs from local resources.
- No mandatory HTTP client dependency — bring your own (Guzzle is suggested,
  not required).
- Tested against the [official W3C JSON-LD test suite](https://github.com/w3c/json-ld-api).

## Planned scope (v1.0)

- [x] Custom `DocumentLoader` interface
- [~] Expansion (§5.5) — implemented; ~310/385 of the W3C expand suite
- [~] Compaction (§5.6) — implemented incl. container-maps, keyword recursion, `@nest`; ~142/246 of the W3C compact suite
- [~] Serialize JSON-LD to RDF (§7 / `toRdf`) — implemented; ~376/467 of the W3C toRdf suite (N-Quads output; `@json`/JCS, `rdfDirection`, and generalized RDF pending)

Out of scope for v1.0: Flattening, Framing, RDF-to-JSON-LD (`fromRdf`).

## Installation

```bash
composer require accredifysg/php-json-ld:^0.1
```

Requires PHP 8.1+. You also need a PSR-18 HTTP client + PSR-17 request
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

A per-algorithm PASS/FAIL matrix will appear in this section once Phase 4 is
in progress (see [the plan](docs/plan.md)).

### Characterization fixtures

`tests/Algorithms/Characterization/` holds JSON snapshots of the expander's
output, generated from running the original
`accredifysg/verifiable-credentials-php` JsonLdProcessor over a set of
sample documents. They are NOT a spec-conformance reference — they pin
the package's current quirky behaviour so that spec-correctness work in
later phases can land each behaviour change in a reviewable diff.

When a Phase 4 PR changes expansion in a way that updates these fixtures,
the update should be reviewed for correctness and paired with any
matching change in downstream consumers (e.g. VC's signed-credential
test fixtures).

## License

[MIT](LICENSE) © Accredify
