<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\W3c;

/**
 * Adapter contract that the W3C harness calls into.
 *
 * Each method receives the parsed input document plus a (possibly empty)
 * options array drawn from the manifest entry, and must return a value in
 * the shape the corresponding algorithm specifies:
 *
 * - expand:   array (the expanded JSON-LD document, always wrapped in [])
 * - compact:  array (the compacted JSON-LD document)
 * - flatten:  array (the flattened JSON-LD document)
 * - toRdf:    string (N-Quads serialization)
 * - fromRdf:  array (expanded JSON-LD deserialised from an N-Quads string)
 *
 * Implementations that don't yet support an algorithm should throw
 * {@see NotImplementedException}; the harness will mark the test skipped.
 */
interface Processor
{
    /**
     * @param  array<mixed>  $input
     * @param  array<string, mixed>  $options
     * @return array<mixed>
     */
    public function expand(array $input, array $options): array;

    /**
     * @param  array<mixed>  $input
     * @param  array<mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<mixed>
     */
    public function compact(array $input, array $context, array $options): array;

    /**
     * @param  array<mixed>  $input
     * @param  array<mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<mixed>
     */
    public function flatten(array $input, array $context, array $options): array;

    /**
     * @param  array<mixed>  $input
     * @param  array<string, mixed>  $options
     */
    public function toRdf(array $input, array $options): string;

    /**
     * @param  array<string, mixed>  $options
     * @return array<mixed>
     */
    public function fromRdf(string $input, array $options): array;
}
