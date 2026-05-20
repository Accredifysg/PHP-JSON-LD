<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Context;

use Accredify\JsonLd\Enums\ContainerType;
use Accredify\JsonLd\Enums\Keyword;
use Accredify\JsonLd\Exceptions\JsonLdException;

/**
 * Holds the term → definition mappings produced by processing one or more
 * `@context` documents.
 *
 * Shape of {@see $termDefinitions} is roughly:
 *
 *   [
 *     "id"   => ["@id" => "@id"],
 *     "type" => ["@id" => "@type"],
 *     "name" => ["@id" => "https://schema.org/name"],
 *     "VerifiableCredential" => [
 *       "@id"      => "https://www.w3.org/2018/credentials#VerifiableCredential",
 *       "@context" => [
 *         "@protected" => true,
 *         "id"         => "@id",
 *         "type"       => "@type",
 *         // …
 *       ],
 *     ],
 *     // …
 *   ]
 *
 * Note: this is the lifted-and-shifted shape from
 * accredifysg/verifiable-credentials-php; it covers what VCv2 / Open Badges
 * v3 contexts actually use, but is NOT a full JSON-LD 1.1 term definition
 * (no `@reverse`, `@language`, `@direction`, `@nest`, `@prefix`, `@index`,
 * type-/property-scoped context awareness). Phase 4 will replace this with a
 * spec-compliant implementation.
 *
 * @phpstan-type TermDefinition array<array-key, mixed>
 */
class TermDefinitions
{
    /**
     * @param  array<string, TermDefinition|string>  $termDefinitions
     */
    public function __construct(
        public array $termDefinitions = []
    ) {}

    /**
     * @param  TermDefinition|string  $termDefinition  String values are
     *                                                 normalised to `['@id' => $value]` per JSON-LD's IRI-shorthand syntax.
     */
    public function addTermDefinition(string $key, array|string $termDefinition): void
    {
        $this->validateTermSyntax($key);

        if (is_string($termDefinition)) {
            $termDefinition = ['@id' => $termDefinition];
        }

        $this->validateTermDefinitionStructure($key, $termDefinition);
        $this->termDefinitions[$key] = $termDefinition;
    }

    /**
     * @return TermDefinition|null Returns null when the term is unknown.
     *                             Strings stored in {@see $termDefinitions} are inflated to
     *                             `['@id' => $value]` before being returned, so callers can assume an
     *                             array shape.
     */
    public function getTermDefinition(?string $term): ?array
    {
        if ($term === null) {
            return null;
        }

        if (isset($this->termDefinitions[$term])) {
            $value = $this->termDefinitions[$term];

            return is_string($value) ? ['@id' => $value] : $value;
        }

        foreach ($this->termDefinitions as $definition) {
            if (is_array($definition)) {
                $result = $this->findExactTermInDefinition($term, $definition);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    private function validateTermSyntax(string $term): void
    {
        if (str_contains($term, ':') || str_contains($term, '/')) {
            throw new JsonLdException("Invalid term '{$term}': cannot contain ':' or '/'");
        }

        if (Keyword::contains($term)) {
            throw new JsonLdException("Invalid term '{$term}': cannot be a keyword");
        }
    }

    /**
     * @param  TermDefinition  $definition
     */
    private function validateTermDefinitionStructure(string $term, array $definition): void
    {
        if (isset($definition['@id']) && ! is_string($definition['@id'])) {
            throw new JsonLdException("Invalid @id in term '{$term}'");
        }

        if (isset($definition['@type']) && ! is_string($definition['@type'])) {
            throw new JsonLdException("Invalid @type in term '{$term}'");
        }

        if (isset($definition['@container'])) {
            $container = $definition['@container'];
            if (! is_string($container) || ! ContainerType::contains($container)) {
                $repr = is_string($container) ? $container : gettype($container);
                throw new JsonLdException("Invalid @container in term '{$term}': {$repr}");
            }
        }

        if (isset($definition['@protected']) && ! is_bool($definition['@protected'])) {
            throw new JsonLdException("Invalid @protected in term '{$term}'");
        }

        // Validate nested @context if present
        if (isset($definition['@context'])) {
            if (! is_array($definition['@context'])) {
                throw new JsonLdException("Invalid @context in term '{$term}': must be an array");
            }

            foreach ($definition['@context'] as $contextKey => $contextValue) {
                if ($contextKey === Keyword::Protected->value && ! is_bool($contextValue)) {
                    throw new JsonLdException("Invalid @protected in nested context for term '{$term}'");
                }
            }
        }
    }

    /**
     * @param  TermDefinition  $definition
     * @return TermDefinition|null
     */
    private function findExactTermInDefinition(string $term, array $definition): ?array
    {
        if (isset($definition[$term])) {
            $value = $definition[$term];

            return is_string($value) ? ['@id' => $value] : (is_array($value) ? $value : null);
        }

        foreach ($definition as $value) {
            if (is_array($value)) {
                $result = $this->findExactTermInDefinition($term, $value);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }
}
