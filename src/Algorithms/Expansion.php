<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Context\TermDefinitions;
use Accredify\JsonLd\Enums\Keyword;
use Throwable;

/**
 * Lifted-and-shifted expansion routine from accredifysg/verifiable-credentials-php.
 *
 * Implements the subset of the JSON-LD 1.1 Expansion Algorithm
 * (https://www.w3.org/TR/json-ld11-api/#expansion-algorithm) needed by the
 * VC repo's eddsa-rdfc-2022 + ecdsa-sd-2023 crypto suites. Notable features
 * vs a bare lift:
 *
 * - Result is always wrapped in an outer array (per the spec's "expansion
 *   produces an array of node objects" rule).
 * - `id` and `type` keyword aliases are handled directly in `expandObjectNode`
 *   without requiring a term-definition lookup, so documents whose contexts
 *   don't expose those aliases at the top level still expand correctly.
 * - Type-scoped contexts: when an object has an `@type`/`type` whose term
 *   definition contains a nested `@context`, those terms are merged into a
 *   transient {@see TermDefinitions} used for the object's other properties.
 * - `@vocab` is used as a fallback when expanding terms in `vocab` mode and
 *   neither a term definition nor a compact-IRI prefix matches.
 * - Compact IRI expansion (`prefix:path`) preserves the `#` or `/` suffix of
 *   the prefix's IRI instead of always inserting a `/`.
 *
 * Known gaps vs full JSON-LD 1.1 (Phase 4 territory):
 *
 * - IRI expansion still uses FILTER_VALIDATE_URL, which rejects valid IRIs
 *   like `did:web:…`, `urn:…`, blank node `_:…`.
 * - No container handling: `@list`, `@set`, `@language`, `@index`, `@graph`,
 *   `@id`, `@type`, `@nest`, `@included`.
 * - No `@reverse`, `@json` literals, language-tagged / direction-tagged
 *   strings.
 * - The `sort` / `ksort` / `escapeString` calls bake a canonicalization
 *   concern into expansion; removing them is a Phase 4 task that will
 *   surface latent ordering bugs in downstream RDFC-10 consumers.
 *
 * Phase 4 replaces this entire file with a spec-compliant implementation.
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
        $expanded = $this->expandNode($document);

        if (! is_array($expanded)) {
            return [];
        }

        // Wrap a single object result in an outer array per the JSON-LD 1.1
        // Expansion Algorithm — the output is always a list of node objects.
        // Empty results pass through unwrapped.
        if (! isset($expanded[0]) && $expanded !== []) {
            return [$expanded];
        }

        return $expanded;
    }

    /**
     * Expands a node based on its type (scalar, array, or object).
     *
     * @return array<mixed>|string
     */
    private function expandNode(mixed $node, ?string $activeProperty = null): array|string
    {
        if (! is_array($node)) {
            return $this->expandScalarNode($node, $activeProperty);
        }

        return isset($node[0])
            ? $this->expandArrayNode($node, $activeProperty)
            : $this->expandObjectNode($node);
    }

    /**
     * @return array<mixed>|string
     */
    private function expandScalarNode(mixed $node, ?string $activeProperty): array|string
    {
        $termDef = $this->termDefinitions->getTermDefinition($activeProperty);

        if (is_string($node)) {
            $node = $this->escapeString($node);
        }

        if ($activeProperty === Keyword::Type->value) {
            return is_string($node) ? $this->expandIri($node, true) : '';
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

            // Don't add @type for xsd:string — it's the default literal type.
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
            if ($expandedItem !== '' || $activeProperty === Keyword::Type->value) {
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

        // First pass: collect types so type-scoped contexts can activate.
        $types = [];
        if (isset($node['@type'])) {
            $types = is_array($node['@type']) ? $node['@type'] : [$node['@type']];
        } elseif (isset($node['type'])) {
            $types = is_array($node['type']) ? $node['type'] : [$node['type']];
        }

        $scopedTermDefinitions = $this->createScopedTermDefinitions(
            array_values(array_filter($types, 'is_string'))
        );

        foreach ($node as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if ($key === Keyword::Context->value) {
                continue;
            }

            // @id keyword (direct + alias).
            if ($key === Keyword::Id->value || $key === Keyword::Id->withoutAtSign()) {
                if (is_string($value)) {
                    $expanded['@id'] = $value;
                } elseif (is_scalar($value)) {
                    $expanded['@id'] = (string) $value;
                }

                continue;
            }

            // @type keyword (direct + alias).
            if ($key === Keyword::Type->value || $key === Keyword::Type->withoutAtSign()) {
                $expanded['@type'] = $this->expandTypeValue($value);

                continue;
            }

            $expandedProperty = $this->expandPropertyWithScope($key, $value, $scopedTermDefinitions);
            if ($expandedProperty !== null) {
                [$expandedKey, $expandedValue] = $expandedProperty;
                $expanded[$expandedKey] = $expandedValue;
            }
        }

        ksort($expanded);

        return $expanded;
    }

    /**
     * Builds a transient {@see TermDefinitions} that includes terms from any
     * type-scoped contexts associated with the given types.
     *
     * @param  list<string>  $types
     */
    private function createScopedTermDefinitions(array $types): TermDefinitions
    {
        $scoped = new TermDefinitions($this->termDefinitions->termDefinitions);

        $baseVocab = $this->termDefinitions->getVocab();
        if ($baseVocab !== null) {
            $scoped->setVocab($baseVocab);
        }

        foreach ($types as $type) {
            $typeDef = $this->termDefinitions->getTermDefinition($type);
            if (
                $typeDef === null
                || ! isset($typeDef['@context'])
                || ! is_array($typeDef['@context'])
            ) {
                continue;
            }

            // A type-scoped context may declare its own @vocab; push it so
            // it takes precedence within this object's expansion.
            if (
                isset($typeDef['@context'][Keyword::Vocab->value])
                && is_string($typeDef['@context'][Keyword::Vocab->value])
            ) {
                $scoped->pushVocab($typeDef['@context'][Keyword::Vocab->value]);
            }

            foreach ($typeDef['@context'] as $term => $definition) {
                if (! is_string($term) || str_starts_with($term, '@')) {
                    continue;
                }
                if (! is_string($definition) && ! is_array($definition)) {
                    continue;
                }
                try {
                    $scoped->addTermDefinition($term, $definition);
                } catch (Throwable) {
                    // Already-defined or invalid terms — skip rather than abort.
                }
            }
        }

        return $scoped;
    }

    /**
     * @return array{0: string, 1: mixed}|null [expandedKey, expandedValue]
     */
    private function expandPropertyWithScope(string $key, mixed $value, TermDefinitions $scoped): ?array
    {
        // Prefer the scoped definition, fall back to the base term map.
        $termDef = $scoped->getTermDefinition($key) ?? $this->termDefinitions->getTermDefinition($key);

        if ($termDef === null) {
            if (str_contains($key, ':')) {
                $expandedKey = $this->expandIri($key, true);
                if ($expandedKey !== $key) {
                    $termDef = ['@id' => $expandedKey];
                }
            }
            if ($termDef === null) {
                // Last resort: try @vocab expansion.
                $expandedKey = $this->expandIri($key, true);
                if ($expandedKey !== $key) {
                    $termDef = ['@id' => $expandedKey];
                } else {
                    return null;
                }
            }
        }

        if (! isset($termDef['@id']) || ! is_string($termDef['@id'])) {
            return null;
        }
        $expandedKey = $this->expandIri($termDef['@id'], true);
        $expandedValue = $this->expandNode($value, $key);

        if ($expandedKey === Keyword::Id->value) {
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
     * Expands a vocabulary term. Carries forward a VC quirk: iterates the
     * inner term definitions array searching for the value via
     * {@see findTermInDefinition}.
     */
    private function expandVocabTerm(string $value): string
    {
        $termDef = $this->termDefinitions->getTermDefinition($value);
        if ($termDef !== null && isset($termDef['@id']) && is_string($termDef['@id'])) {
            return $termDef['@id'];
        }

        foreach ($this->termDefinitions->termDefinitions as $def) {
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

        // Try compact-IRI prefix expansion FIRST so terms like "sec:created"
        // use the "sec" prefix definition rather than falling back to @vocab.
        $prefixExpanded = false;

        if (str_contains($currentValue, ':')) {
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

                $prefixIri = $prefixDef['@id'];
                if (str_ends_with($prefixIri, '#') || str_ends_with($prefixIri, '/')) {
                    // Prefix already has a separator — concatenate directly.
                    $currentValue = $prefixIri.$path;
                } else {
                    $currentValue = $prefixIri.'/'.$path;
                }

                $prefixExpanded = true;
            }
        }

        // Fall back to @vocab if:
        // 1. vocab flag is true,
        // 2. no term definition matched,
        // 3. prefix expansion didn't happen,
        // 4. the value is not already a URL.
        if (
            $vocab
            && $termDef === null
            && ! $prefixExpanded
            && ! filter_var($currentValue, FILTER_VALIDATE_URL)
        ) {
            $vocabIri = $this->termDefinitions->getVocab();
            if ($vocabIri !== null) {
                // Per JSON-LD spec: concatenate directly. The vocab IRI is
                // expected to already end with the appropriate separator.
                return $vocabIri.$currentValue;
            }
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
