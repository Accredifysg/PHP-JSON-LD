<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\W3c;

use JsonException;
use RuntimeException;

/**
 * One row in a W3C JSON-LD test manifest, normalised into a value object.
 *
 * Created by {@see Harness}. Consumed by the Pest harness files in
 * tests/W3c/Algorithms/ which invoke a {@see Processor} and compare its
 * output against the expected fixture pointed to by this object.
 */
final class TestCase
{
    /**
     * @param  array<string, mixed>  $options
     * @param  array<int, string>  $rawTypes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $purpose,
        public readonly ?string $algorithm,
        public readonly bool $isPositive,
        public readonly bool $isNegative,
        public readonly ?string $inputPath,
        public readonly ?string $expectPath,
        public readonly mixed $expectErrorCode,
        public readonly ?string $contextPath,
        public readonly array $options,
        public readonly string $baseIri,
        public readonly ?string $documentUrl,
        public readonly array $rawTypes,
    ) {}

    /**
     * @return array<mixed>
     */
    public function loadInput(): array
    {
        return $this->loadJson($this->inputPath, 'input');
    }

    /**
     * @return array<mixed>
     */
    public function loadExpected(): array
    {
        return $this->loadJson($this->expectPath, 'expect');
    }

    /**
     * @return array<mixed>
     */
    public function loadContext(): array
    {
        return $this->loadJson($this->contextPath, 'context');
    }

    /**
     * Loads the input as raw text rather than JSON — used by fromRdf, whose
     * input fixtures are N-Quads (`.nq`) documents, not JSON.
     */
    public function loadInputRaw(): string
    {
        if ($this->inputPath === null) {
            throw new RuntimeException("Test {$this->id} has no input file");
        }
        if (! is_file($this->inputPath)) {
            throw new RuntimeException("input file not found at {$this->inputPath}");
        }

        return (string) file_get_contents($this->inputPath);
    }

    /**
     * @return array<mixed>
     */
    private function loadJson(?string $path, string $label): array
    {
        if ($path === null) {
            throw new RuntimeException("Test {$this->id} has no {$label} file");
        }
        if (! is_file($path)) {
            throw new RuntimeException("{$label} file not found at {$path}");
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new RuntimeException("Failed to parse {$label} {$path}: {$e->getMessage()}", 0, $e);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Expected {$label} at {$path} to decode to a JSON object/array, got ".gettype($decoded));
        }

        return $decoded;
    }

    public function describe(): string
    {
        return sprintf('%s — %s', $this->id, $this->name);
    }
}
