<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Context\TermDefinitions;
use Accredify\JsonLd\Enums\Keyword;

/**
 * Lifted-and-shifted expansion routine from accredifysg/verifiable-credentials-php.
 *
 * Implements ENOUGH of the JSON-LD 1.1 Expansion Algorithm
 * (https://www.w3.org/TR/json-ld11-api/#expansion-algorithm) to satisfy the
 * VCv2 / Open Badges v3 codepaths used by the VC repo's
 * eddsa-rdfc-2022 crypto suite. It is NOT spec-compliant; known gaps:
 *
 * - IRI expansion uses FILTER_VALIDATE_URL, which rejects valid IRIs like
 *   `did:web:…`, `urn:…`, blank node `_:…`.
 * - No container handling (@list, @set, @language, @index, @graph, @id,
 *
 *   @type, @nest, @included).
 * - No @reverse, @json, language-tagged / direction-tagged strings.
 * - Hardcoded xsd:string collapse.
 * - The `sort()` / `ksort()` / `escapeString()` calls bake a canonicalization
 *   concern into expansion - removing them is a Phase 4 task that will
 *   surface ordering bugs in the downstream RDFC-10 implementation.
 *
 * Phase 4 replaces this entire file. Characterization tests in PR 2.8 pin
 * the current quirks so future refactors can distinguish intentional from
 * accidental output changes.
 */
class Expansion
{
    /** @var list<string> */
    private array $processedPrefixes = [];

    public function __construct(private readonly TermDefinitions $termDefinitions) {}

    /**
     * Main entry point.
     *
     * @param  array<array-key, mixed>  $document
     * @return array<mixed>
     */
    public function expand(array $document): array
    {
        $result = $this->expandNode($document);

        return is_array($result) ? $result : [];
    }

    /**
     * Expands a node based on its type (scalar, array, or object).
     *
     * @return array<mixed>|string|null
     */
    private function expandNode(mixed $node, ?string $activeProperty = null): array|string|null
    {
        if (! is_array($node)) {
            return $this->expandScalarNode($node, $activeProperty);
        }

        return isset($node[0])
            ? $this->expandArrayNode($node, $activeProperty)
            : $this->expandObjectNode($node);
    }

    /**
     * @return array<mixed>|string|null
     */
    private function expandScalarNode(mixed $node, ?string $activeProperty): array|string|null
    {
        $termDef = $this->termDefinitions->getTermDefinition($activeProperty);

        if (is_string($node)) {
            $node = $this->escapeString($node);
        }

        if ($activeProperty === Keyword::Type->value) {
            return is_string($node) ? $this->expandIri($node, true) : null;
        }

        if ($termDef === null) {
            return ['@value' => $node];
        }

        return $this->expandScalarWithTermDef($node, $termDef);
    }

    /**
     * @param  array<array-key, mixed>  $termDef
     * @return array<string, mixed>
     */
    private function expandScalarWithTermDef(mixed $node, array $termDef): array
    {
        if (isset($termDef['@type']) && is_string($termDef['@type'])) {
            $type = $termDef['@type'];

            if ($type === Keyword::Vocab->value) {
                return ['@id' => is_string($node) ? $this->expandVocabTerm($node) : ''];
            }
            if ($type === Keyword::Id->value) {
                return ['@id' => is_string($node) ? $this->expandIri($node) : ''];
            }

            // Don't add @type for xsd:string as it's the default
            $expandedType = $this->expandIri($type, true);
            if (
                $expandedType === 'https://www.w3.org/2001/XMLSchema#string'
                || $expandedType === 'http://www.w3.org/2001/XMLSchema#string'
                || $type === 'xsd:string'
                || $type === 'string'
            ) {
                return ['@value' => $node];
            }

            return [
                '@type' => $expandedType,
                '@value' => $node,
            ];
        }

        return ['@value' => $node];
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<mixed>
     */
    private function expandArrayNode(array $node, ?string $activeProperty): array
    {
        if ($node !== [] && is_array($node[0] ?? null) && isset($node[0]['@value'])) {
            // Pre-sort sibling @value items so canonicalization is stable.
            // (Bakes a canon concern into expansion; Phase 4 removes this.)
            usort($node, function (mixed $a, mixed $b) {
                if (! is_array($a) || ! is_array($b)) {
                    return 0;
                }
                $av = $a['@value'] ?? null;
                $bv = $b['@value'] ?? null;
                if (is_scalar($av) && is_scalar($bv)) {
                    return $av <=> $bv;
                }

                return 0;
            });
        }

        $result = [];
        foreach ($node as $item) {
            $expandedItem = $this->expandNode($item, $activeProperty);
            if ($expandedItem !== null) {
                $result[] = $expandedItem;
            }
        }

        if ($activeProperty === Keyword::Type->value) {
            sort($result);
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<string, mixed>
     */
    private function expandObjectNode(array $node): array
    {
        $expanded = [];

        foreach ($node as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if ($key === Keyword::Context->value) {
                continue;
            }

            if ($key === Keyword::Type->withoutAtSign()) {
                $expanded['@type'] = $this->expandTypeValue($value);

                continue;
            }

            $expandedProperty = $this->expandProperty($key, $value);
            if ($expandedProperty !== null) {
                [$expandedKey, $expandedValue] = $expandedProperty;
                $expanded[$expandedKey] = $expandedValue;
            }
        }

        ksort($expanded);

        return $expanded;
    }

    /**
     * @return list<string>
     */
    private function expandTypeValue(mixed $value): array
    {
        if (! is_array($value)) {
            if (! is_string($value)) {
                return [];
            }
            $termDef = $this->termDefinitions->getTermDefinition($value);
            $expandedType = $termDef !== null && isset($termDef['@id']) && is_string($termDef['@id'])
                ? $termDef['@id']
                : $this->expandIri($value, true);

            return [$expandedType];
        }

        $types = [];
        foreach ($value as $typeValue) {
            if (! is_string($typeValue)) {
                continue;
            }
            $termDef = $this->termDefinitions->getTermDefinition($typeValue);
            $types[] = $termDef !== null && isset($termDef['@id']) && is_string($termDef['@id'])
                ? $this->expandIri($termDef['@id'], true)
                : $this->expandIri($typeValue, true);
        }
        sort($types);

        return $types;
    }

    /**
     * @return array{0: string, 1: mixed}|null [expandedKey, expandedValue]
     */
    private function expandProperty(string $key, mixed $value): ?array
    {
        $termDef = $this->termDefinitions->getTermDefinition($key);

        if ($termDef === null) {
            if (str_contains($key, ':')) {
                $expandedKey = $this->expandIri($key, true);
                if ($expandedKey !== $key) {
                    $termDef = ['@id' => $expandedKey];
                }
            }
            if ($termDef === null) {
                return null;
            }
        }

        if (! isset($termDef['@id']) || ! is_string($termDef['@id'])) {
            return null;
        }
        $expandedKey = $this->expandIri($termDef['@id'], true);
        $expandedValue = $this->expandNode($value, $key);

        if ($expandedValue === null) {
            return null;
        }

        if ($expandedKey === Keyword::Id->value) {
            // @id keeps its raw value (no array wrapping).
            if (is_array($expandedValue) && isset($expandedValue['@value'])) {
                return [$expandedKey, $expandedValue['@value']];
            }

            return [$expandedKey, $expandedValue];
        }

        $finalValue = (is_array($expandedValue) && array_key_exists(0, $expandedValue))
            ? $expandedValue
            : [$expandedValue];

        return [$expandedKey, $finalValue];
    }

    /**
     * Expands a vocabulary term to its IRI.
     *
     * Carries forward a VC quirk: iterates over the TermDefinitions object's
     * public properties (= one iteration yielding the inner term map) and
     * checks for `@id` / `@vocab` keys on it. In practice this dead-code
     * branch never matches because ContextProcessor strips keywords out of
     * the term map. Preserved verbatim under the lift-and-shift charter;
     * Phase 4 removes it.
     */
    private function expandVocabTerm(string $value): string
    {
        $termDef = $this->termDefinitions->getTermDefinition($value);
        if ($termDef !== null && isset($termDef['@id']) && is_string($termDef['@id'])) {
            return $termDef['@id'];
        }

        foreach ((array) $this->termDefinitions as $def) {
            if (is_array($def)) {
                $result = $this->findTermInDefinition($value, $def);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $def
     */
    private function findTermInDefinition(string $value, array $def): ?string
    {
        if (isset($def['@id']) && is_string($def['@id']) && $def['@id'] === $value) {
            return $def['@id'];
        }

        if (isset($def['@vocab']) && is_string($def['@vocab'])) {
            $vocabIri = rtrim($def['@vocab'], '/');

            return $vocabIri.'/'.$value;
        }

        return null;
    }

    private function expandIri(string $value, bool $vocab = false): string
    {
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        $this->processedPrefixes = [];
        $currentValue = $value;

        $termDef = $this->termDefinitions->getTermDefinition($currentValue);
        if ($termDef !== null && isset($termDef['@id']) && is_string($termDef['@id'])) {
            $currentValue = $termDef['@id'];
        }

        while (str_contains($currentValue, ':')) {
            [$prefix, $path] = explode(':', $currentValue, 2);

            if (in_array($prefix, $this->processedPrefixes, true)) {
                break;
            }
            $this->processedPrefixes[] = $prefix;

            $prefixDef = $this->termDefinitions->getTermDefinition($prefix);
            if ($prefixDef === null || ! isset($prefixDef['@id']) || ! is_string($prefixDef['@id'])) {
                break;
            }

            $currentValue = rtrim($prefixDef['@id'], '/').'/'.$path;
        }

        return $currentValue;
    }

    private function escapeString(string $value): string
    {
        return trim(str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value
        ));
    }
}
