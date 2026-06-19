<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\W3c;

use Accredify\JsonLd\Algorithms\Expansion;
use JsonException;
use RuntimeException;
use stdClass;

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
        public readonly ?string $framePath,
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
     * Load a frame, preserving empty JSON objects (`{}`) as the wildcard
     * sentinel. Unlike {@see loadInput()} this decodes to objects first, since
     * the associative form cannot distinguish a frame's `{}` (wildcard) from
     * `[]` (match none) — see {@see Expansion::FRAME_WILDCARD}.
     *
     * @return array<mixed>
     */
    public function loadFrame(): array
    {
        if ($this->framePath === null) {
            throw new RuntimeException("Test {$this->id} has no frame file");
        }
        if (! is_file($this->framePath)) {
            throw new RuntimeException("frame file not found at {$this->framePath}");
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($this->framePath),
                associative: false,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new RuntimeException("Failed to parse frame {$this->framePath}: {$e->getMessage()}", 0, $e);
        }

        $converted = $this->markEmptyObjects($decoded);
        if (! is_array($converted)) {
            throw new RuntimeException("Expected frame at {$this->framePath} to decode to a JSON object/array");
        }

        return $converted;
    }

    /**
     * Recursively convert a frame decoded as objects into associative arrays,
     * mapping each empty object `{}` to the wildcard sentinel so it survives
     * (an empty array `[]` — match none — stays an empty array).
     */
    private function markEmptyObjects(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            if ($properties === []) {
                return [Expansion::FRAME_WILDCARD => true];
            }

            $out = [];
            foreach ($properties as $key => $item) {
                $out[$key] = $this->markEmptyObjects($item);
            }

            return $out;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->markEmptyObjects($item), $value);
        }

        return $value;
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

        $contents = file_get_contents($this->inputPath);
        if ($contents === false) {
            throw new RuntimeException("Failed to read input file at {$this->inputPath}");
        }

        return $contents;
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
