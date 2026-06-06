<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Context;

use Accredify\JsonLd\Algorithms\Expansion;
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
    /** @var list<string> Stack of active @vocab IRIs (innermost-last). */
    private array $vocabStack = [];

    /** Active base IRI for document-relative IRI resolution, or null. */
    private ?string $base = null;

    /**
     * @param  array<string, TermDefinition|string>  $termDefinitions
     */
    public function __construct(
        public array $termDefinitions = []
    ) {}

    /**
     * Sets the active base IRI (used to resolve document-relative `@id` /
     * `@type` IRIs during expansion). Null disables document-relative
     * resolution.
     */
    public function setBase(?string $base): void
    {
        $this->base = $base;
    }

    public function getBase(): ?string
    {
        return $this->base;
    }

    /**
     * Push a `@vocab` IRI onto the stack.
     *
     * `@vocab` participates in IRI expansion as a fallback: when a term is
     * neither defined nor a compact IRI, the active `@vocab` is prepended to
     * it. Stacking lets a type-scoped context temporarily override the
     * outer `@vocab` without losing the parent value.
     */
    public function pushVocab(?string $vocab): void
    {
        if ($vocab !== null) {
            $this->vocabStack[] = $vocab;
        }
    }

    public function popVocab(): void
    {
        if ($this->vocabStack !== []) {
            array_pop($this->vocabStack);
        }
    }

    /**
     * Returns the active (innermost) `@vocab` IRI, or null if none is set.
     */
    public function getVocab(): ?string
    {
        return $this->vocabStack === [] ? null : end($this->vocabStack);
    }

    /**
     * Replaces the entire vocab stack with a single value (or clears it).
     *
     * Kept for convenience when constructing scoped term definitions from a
     * parent — call `setVocab($parent->getVocab())` to mirror the parent's
     * active vocab without copying its full stack.
     */
    public function setVocab(?string $vocab): void
    {
        $this->vocabStack = $vocab !== null ? [$vocab] : [];
    }

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
     *
     * Note: this method intentionally only checks top-level term
     * definitions. Terms defined inside a type-scoped or property-scoped
     * `@context` are NOT findable here — the {@see Expansion}
     * activates those scopes by overlaying their terms onto a fresh
     * TermDefinitions before lookups. (v0.1.x's recursive search through
     * nested @context entries leaked scoped terms into unscoped lookups,
     * breaking spec compliance.)
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

        return null;
    }

    private function validateTermSyntax(string $term): void
    {
        // A term MAY be a compact IRI ("ex:date"), an absolute IRI, or
        // otherwise contain ':' / '/' — contexts legitimately define those
        // (e.g. to attach `@type` coercion to a compact-IRI property). The
        // IRI-expansion algorithm already resolves such terms, so storing
        // them is correct. The only hard rule is that a term MUST NOT be a
        // JSON-LD keyword.
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
}
