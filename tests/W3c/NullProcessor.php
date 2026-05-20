<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\W3c;

/**
 * Default harness processor used until real algorithms exist in src/.
 *
 * Every method throws {@see NotImplementedException}, which the Pest harness
 * translates into a skipped test. As Phase 4 lands real implementations,
 * this class will be replaced by an adapter that delegates into the package.
 */
final class NullProcessor implements Processor
{
    public function expand(array $input, array $options): array
    {
        throw new NotImplementedException('Expansion not implemented');
    }

    public function compact(array $input, array $context, array $options): array
    {
        throw new NotImplementedException('Compaction not implemented');
    }

    public function toRdf(array $input, array $options): string
    {
        throw new NotImplementedException('toRdf not implemented');
    }
}
