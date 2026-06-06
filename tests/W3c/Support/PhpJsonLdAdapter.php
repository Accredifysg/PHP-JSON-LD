<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\W3c\Support;

use Accredify\JsonLd\Contracts\DocumentLoader;
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
        // harness). Other options (expandContext, processingMode) are not yet
        // threaded; they arrive with the JsonLdOptions VO in a later PR.
        $base = isset($options['base']) && is_string($options['base']) ? $options['base'] : null;

        return (new JsonLdProcessor($this->loader))
            ->expand($input, $base)
            ->toArray();
    }

    public function compact(array $input, array $context, array $options): array
    {
        return (new JsonLdProcessor($this->loader))
            ->compact($input, $context)
            ->toArray();
    }

    public function toRdf(array $input, array $options): string
    {
        $base = isset($options['base']) && is_string($options['base']) ? $options['base'] : null;

        return (new JsonLdProcessor($this->loader))
            ->toRdf($input, $base)
            ->toNQuads();
    }
}
