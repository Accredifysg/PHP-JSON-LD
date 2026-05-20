# PHP-JSON-LD

<!-- Badges (placeholders — wired up in PR 1.2 / Phase 6) -->
<!-- ![CI](https://img.shields.io/github/actions/workflow/status/accredifysg/php-json-ld/ci.yml?branch=main) -->
<!-- ![Packagist Version](https://img.shields.io/packagist/v/accredifysg/php-json-ld) -->
<!-- ![PHP Version](https://img.shields.io/packagist/php-v/accredifysg/php-json-ld) -->
<!-- ![License](https://img.shields.io/packagist/l/accredifysg/php-json-ld) -->
<!-- ![JSON-LD 1.1 Compliance](https://img.shields.io/badge/JSON--LD%201.1-WIP-orange) -->

A PHP implementation of the [JSON-LD 1.1](https://www.w3.org/TR/json-ld11/) specification.

> **Status: Work in progress.** This package is being extracted from
> [accredifysg/verifiable-credentials-php](https://github.com/accredifysg/verifiable-credentials-php)
> and incrementally brought into conformance with the W3C JSON-LD 1.1 specification.
> The public API is not yet stable; do not depend on it for production use until v1.0.

## Goals

- Faithful implementation of the JSON-LD 1.1 algorithms defined in the
  [JSON-LD 1.1 Processing Algorithms and API](https://www.w3.org/TR/json-ld11-api/).
- Pluggable document loader so consumers (e.g. verifiable-credential libraries)
  can serve known `@context` URLs from local resources.
- No mandatory HTTP client dependency — bring your own (Guzzle is suggested,
  not required).
- Tested against the [official W3C JSON-LD test suite](https://github.com/w3c/json-ld-api).

## Planned scope (v1.0)

- [ ] Expansion (§5.5)
- [ ] Compaction (§5.6)
- [ ] Serialize JSON-LD to RDF (§6 / `toRdf`)
- [ ] Custom `DocumentLoader` interface

Out of scope for v1.0: Flattening, Framing, RDF-to-JSON-LD (`fromRdf`).

## Installation

```bash
composer require accredifysg/php-json-ld
```

Requires PHP 8.1+.

## Usage

> Usage examples land in a later PR once the public API stabilises.

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

## License

[MIT](LICENSE) © Accredify
