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
        // TODO (PR 4.5): pipe options.base / options.expandContext /
        //   options.processingMode through a JsonLdOptions value object.
        //   For now we ignore them — most W3C tests don't need them.
        return (new JsonLdProcessor($this->loader))
            ->expand($input)
            ->toArray();
    }

    public function compact(array $input, array $context, array $options): array
    {
        throw new NotImplementedException('Compaction lands in PR 4.9');
    }

    public function toRdf(array $input, array $options): string
    {
        throw new NotImplementedException('toRdf lands in PR 4.10');
    }
}
