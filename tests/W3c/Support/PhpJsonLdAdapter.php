<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\W3c\Support;

use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\JsonLdOptions;
use Accredify\JsonLd\JsonLdProcessor;
use Accredify\JsonLd\Tests\W3c\NotImplementedException;
use Accredify\JsonLd\Tests\W3c\Processor;

/**
 * Adapter wiring the W3C harness's {@see Processor} contract to the
 * package's own {@see JsonLdProcessor}.
 *
 * For each Phase 4 PR, run `composer test:w3c` before and after; the PASS
 * delta is the PR's signal. The harness uses {@see W3cDocumentLoader} so
 * remote `@context` URLs resolve to local fixture files — no network calls.
 *
 * `compact` and `toRdf` still throw {@see NotImplementedException} until
 * PR 4.9 and PR 4.10 land.
 */
final class PhpJsonLdAdapter implements Processor
{
    public function __construct(
        private readonly DocumentLoader $loader,
    ) {}

    public static function default(): self
    {
        return new self(W3cDocumentLoader::default());
    }

    public function expand(array $input, array $options): array
    {
        // `base` is the effective document base — option.base if the manifest
        // entry set one, otherwise the document URL (injected by the test
        // harness).
        $base = isset($options['base']) && is_string($options['base']) ? $options['base'] : null;

        return (new JsonLdProcessor($this->loader))
            ->expand($input, new JsonLdOptions(base: $base, processingMode: self::processingMode($options), expandContext: self::expandContext($options)))
            ->toArray();
    }

    /**
     * The `expandContext` API option as surfaced by the manifest: a context
     * map, or a context URL (already resolved by the harness to the W3C test
     * base, which the loader serves locally).
     *
     * @param  array<string, mixed>  $options
     * @return array<array-key, mixed>|string|null
     */
    private static function expandContext(array $options): array|string|null
    {
        $expandContext = $options['expandContext'] ?? null;

        return is_string($expandContext) || is_array($expandContext) ? $expandContext : null;
    }

    /**
     * Derive the effective processing mode from a manifest entry's `option`
     * block. `processingMode` (the API option) wins; otherwise `specVersion`
     * (which version of the spec the test targets) selects the mode; absent
     * both, the default is JSON-LD 1.1.
     *
     * @param  array<string, mixed>  $options
     */
    private static function processingMode(array $options): ?string
    {
        foreach (['processingMode', 'specVersion'] as $key) {
            if (isset($options[$key]) && is_string($options[$key])) {
                return $options[$key];
            }
        }

        return null;
    }

    public function compact(array $input, array $context, array $options): array
    {
        // The document base lets compaction relativise @id values (§5.6).
        $base = isset($options['base']) && is_string($options['base']) ? $options['base'] : null;

        // compactArrays defaults to true; a manifest entry may set it false.
        $compactArrays = isset($options['compactArrays']) && is_bool($options['compactArrays']) ? $options['compactArrays'] : true;

        return (new JsonLdProcessor($this->loader))
            ->compact($input, $context, new JsonLdOptions(base: $base, processingMode: self::processingMode($options), compactArrays: $compactArrays))
            ->toArray();
    }

    public function toRdf(array $input, array $options): string
    {
        $base = isset($options['base']) && is_string($options['base']) ? $options['base'] : null;

        $rdfDirection = isset($options['rdfDirection']) && is_string($options['rdfDirection']) ? $options['rdfDirection'] : null;

        return (new JsonLdProcessor($this->loader))
            ->toRdf($input, new JsonLdOptions(base: $base, processingMode: self::processingMode($options), rdfDirection: $rdfDirection, expandContext: self::expandContext($options)))
            ->toNQuads();
    }
}
