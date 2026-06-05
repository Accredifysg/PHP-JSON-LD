<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\W3c;

use JsonException;
use RuntimeException;

/**
 * Reads a W3C JSON-LD test manifest and yields the individual test cases.
 *
 * The W3C test suite (tests/w3c/) is a git submodule pointing at
 * https://github.com/w3c/json-ld-api. Each algorithm manifest is a JSON-LD
 * document whose `sequence` array contains test cases of shape:
 *
 *   {
 *     "@id": "#t0001",
 *     "@type": ["jld:PositiveEvaluationTest", "jld:ExpandTest"],
 *     "name": "drop free-floating nodes",
 *     "purpose": "...",
 *     "input": "expand/0001-in.jsonld",
 *     "expect": "expand/0001-out.jsonld",
 *     "option": { "processingMode": "json-ld-1.0", "base": "..." }
 *   }
 *
 * Negative tests use `expectErrorCode` instead of `expect`.
 *
 * This class is intentionally narrow: it just parses the manifest. The
 * actual execution of each test lives in the Pest harness files, which
 * compare a {@see Processor}'s output against the expected fixture.
 */
final class Harness
{
    public function __construct(
        private readonly string $manifestsRoot,
    ) {
        if (! is_dir($manifestsRoot)) {
            throw new RuntimeException(sprintf(
                'W3C tests directory not found at %s. Run `git submodule update --init --recursive`.',
                $manifestsRoot,
            ));
        }
    }

    public static function fromDefaultLocation(): self
    {
        return new self(__DIR__.'/../w3c/tests');
    }

    /**
     * @return iterable<string, TestCase>
     */
    public function manifest(string $name): iterable
    {
        $manifestPath = $this->manifestsRoot.'/'.$name;
        if (! is_file($manifestPath)) {
            throw new RuntimeException("Manifest not found: {$manifestPath}");
        }

        $data = $this->decodeJsonFile($manifestPath, 'manifest');

        $baseIri = isset($data['baseIri']) && is_string($data['baseIri']) ? $data['baseIri'] : '';
        $manifestDir = dirname($manifestPath);
        $sequence = isset($data['sequence']) && is_array($data['sequence']) ? $data['sequence'] : [];

        foreach ($sequence as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $id = isset($entry['@id']) && is_string($entry['@id']) ? $entry['@id'] : null;
            if ($id === null) {
                continue;
            }

            yield $id => $this->parseEntry($entry, $id, $manifestDir, $baseIri);
        }
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function parseEntry(array $entry, string $id, string $manifestDir, string $baseIri): TestCase
    {
        $types = $this->stringList($entry['@type'] ?? []);
        $isPositive = in_array('jld:PositiveEvaluationTest', $types, true);
        $isNegative = in_array('jld:NegativeEvaluationTest', $types, true);
        $algorithm = $this->resolveAlgorithm($types);

        // The document URL is the manifest base + the relative input ref
        // (e.g. "expand/0062-in.jsonld"). Used as the default base IRI for
        // document-relative IRI resolution; option.base overrides it.
        $inputRef = isset($entry['input']) && is_string($entry['input']) ? $entry['input'] : null;
        $documentUrl = $inputRef !== null ? $baseIri.$inputRef : null;

        return new TestCase(
            id: $id,
            name: isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : '',
            purpose: isset($entry['purpose']) && is_string($entry['purpose']) ? $entry['purpose'] : '',
            algorithm: $algorithm,
            isPositive: $isPositive,
            isNegative: $isNegative,
            inputPath: $this->joinRelativePath($manifestDir, $entry['input'] ?? null),
            expectPath: $this->joinRelativePath($manifestDir, $entry['expect'] ?? null),
            expectErrorCode: $entry['expectErrorCode'] ?? null,
            contextPath: $this->joinRelativePath($manifestDir, $entry['context'] ?? null),
            options: $this->stringKeyedArray($entry['option'] ?? []),
            baseIri: $baseIri,
            documentUrl: $documentUrl,
            rawTypes: $types,
        );
    }

    /**
     * @param  array<int, string>  $types
     */
    private function resolveAlgorithm(array $types): ?string
    {
        foreach ($types as $type) {
            if ($type === 'jld:PositiveEvaluationTest' || $type === 'jld:NegativeEvaluationTest') {
                continue;
            }

            // Strip the jld: prefix and the trailing "Test" suffix.
            // e.g. "jld:ExpandTest" -> "Expand"
            if (str_starts_with($type, 'jld:') && str_ends_with($type, 'Test')) {
                return substr($type, 4, -4);
            }
        }

        return null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeJsonFile(string $path, string $label): array
    {
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

    /**
     * Normalises a manifest field that may be either a single string or a
     * list of strings into an array of strings.
     *
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    private function joinRelativePath(string $baseDir, mixed $relative): ?string
    {
        if (! is_string($relative) || $relative === '') {
            return null;
        }

        return $baseDir.'/'.$relative;
    }
}
