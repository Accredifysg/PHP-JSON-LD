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

    /** Default `@language` applied to plain string values, or null. */
    private ?string $defaultLanguage = null;

    /** Default `@direction` ("ltr"/"rtl") applied to plain string values, or null. */
    private ?string $defaultDirection = null;

    /** Effective processing mode ("json-ld-1.0" or "json-ld-1.1"). */
    private string $processingMode = 'json-ld-1.1';

    /**
     * The context to roll back to when this context is non-propagating
     * (@propagate: false, e.g. a type-scoped context): on descending into a
     * new node object the active context reverts to this snapshot. Null for a
     * normal (propagating) context.
     */
    private ?TermDefinitions $previousContext = null;

    /**
     * @param  array<string, TermDefinition|string>  $termDefinitions
     */
    public function __construct(
        public array $termDefinitions = []
    ) {}

    public function getPreviousContext(): ?TermDefinitions
    {
        return $this->previousContext;
    }

    public function setPreviousContext(?TermDefinitions $previous): void
    {
        $this->previousContext = $previous;
    }

    public function setProcessingMode(string $mode): void
    {
        $this->processingMode = $mode;
    }

    public function getProcessingMode(): string
    {
        return $this->processingMode;
    }

    /** True when the effective processing mode is JSON-LD 1.0. */
    public function isJson10(): bool
    {
        return $this->processingMode === 'json-ld-1.0';
    }

    /**
     * True if any term in this context is protected — used to gate the
     * "invalid context nullification" check when an @context:null reset is
     * applied without override-protected.
     */
    public function hasAnyProtected(): bool
    {
        foreach ($this->termDefinitions as $definition) {
            if (is_array($definition) && ($definition['@protected'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    public function setDefaultLanguage(?string $language): void
    {
        $this->defaultLanguage = $language;
    }

    public function getDefaultLanguage(): ?string
    {
        return $this->defaultLanguage;
    }

    public function setDefaultDirection(?string $direction): void
    {
        $this->defaultDirection = $direction;
    }

    public function getDefaultDirection(): ?string
    {
        return $this->defaultDirection;
    }

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
     * @param  bool  $protectedContext  True when the enclosing context is
     *                                  `@protected`, so every term it defines
     *                                  is protected.
     * @param  bool  $overrideProtected  True when the active context-processing
     *                                   step is permitted to redefine a
     *                                   protected term (property-scoped contexts
     *                                   and `@import`); false otherwise.
     */
    public function addTermDefinition(string $key, array|string $termDefinition, bool $protectedContext = false, bool $overrideProtected = false): void
    {
        $this->validateTermSyntax($key);

        if (is_string($termDefinition)) {
            $termDefinition = ['@id' => $termDefinition];
        }

        $this->validateTermDefinitionStructure($key, $termDefinition);
        $this->storeProtectedAware($key, $termDefinition, $protectedContext, $overrideProtected);
    }

    /**
     * Store a (pre-validated) term definition that came from a scoped context
     * overlay, applying protected-term enforcement but skipping the term-syntax
     * check (scoped overlays legitimately carry keyword-alias / compact terms).
     *
     * @param  TermDefinition  $termDefinition
     */
    public function overlayTerm(string $key, array $termDefinition, bool $protectedContext, bool $overrideProtected): void
    {
        $this->storeProtectedAware($key, $termDefinition, $protectedContext, $overrideProtected);
    }

    /**
     * Returns true if the term currently carries a protected definition.
     */
    public function isProtected(string $key): bool
    {
        $existing = $this->termDefinitions[$key] ?? null;

        return is_array($existing) && ($existing['@protected'] ?? false) === true;
    }

    /**
     * Apply the JSON-LD 1.1 protected-term rule (§4.1.2 step 5.13 / §4.2.2
     * step 5) before storing: a protected term may only be redefined when
     * override is permitted, or when the new definition is identical to the
     * existing one (ignoring `@protected`).
     *
     * @param  TermDefinition  $termDefinition
     */
    private function storeProtectedAware(string $key, array $termDefinition, bool $protectedContext, bool $overrideProtected): void
    {
        // A term's own @protected (if present) overrides the enclosing
        // context's @protected — so {"@protected": false} opts a term out of
        // an otherwise-protected context.
        $explicit = array_key_exists('@protected', $termDefinition) ? ($termDefinition['@protected'] === true) : null;
        $isProtected = $explicit ?? $protectedContext;

        if (! $overrideProtected && $this->isProtected($key)) {
            /** @var TermDefinition $existing */
            $existing = $this->termDefinitions[$key];
            if (! $this->sameDefinitionIgnoringProtected($existing, $termDefinition)) {
                throw new JsonLdException("Protected term redefinition: '{$key}' is protected and cannot be redefined");
            }
            // An identical redefinition is permitted but keeps the term
            // protected (§4.2.2 step 5: the protection is not lost).
            $isProtected = true;
        }

        if ($isProtected) {
            $termDefinition['@protected'] = true;
        } else {
            unset($termDefinition['@protected']);
        }

        $this->termDefinitions[$key] = $termDefinition;
    }

    /**
     * @param  TermDefinition  $a
     * @param  TermDefinition  $b
     */
    private function sameDefinitionIgnoringProtected(array $a, array $b): bool
    {
        unset($a['@protected'], $b['@protected']);
        ksort($a);
        ksort($b);

        return $a == $b;
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

    /**
     * True when a term is itself IRI-shaped: it contains a colon anywhere but
     * as the first or last character (a compact IRI / absolute IRI), or it
     * contains a slash anywhere (a relative IRI reference). Per §4.2.2 such a
     * term's IRI expansion must agree with its @id mapping.
     */
    private function isIriShapedTerm(string $term): bool
    {
        $colon = strpos($term, ':');
        if ($colon !== false && $colon !== 0 && $colon !== strlen($term) - 1) {
            return true;
        }

        return str_contains($term, '/');
    }

    /**
     * Locally IRI-expand a value using the terms defined so far plus the active
     * vocabulary mapping (a lightweight subset of §4.3, sufficient for the
     * §4.2.2 term/id consistency check): a compact IRI "prefix:suffix" expands
     * via a defined prefix term; an absolute IRI / blank node is kept; a bare
     * term with an active vocab mapping gets it prepended; anything otherwise
     * unresolvable is returned unchanged.
     */
    private function localExpandIri(string $value): string
    {
        if ($value === '' || Keyword::contains($value)) {
            return $value;
        }

        if (str_contains($value, ':')) {
            [$prefix, $suffix] = explode(':', $value, 2);
            if ($prefix === '_' || str_starts_with($suffix, '//')) {
                return $value; // blank node or absolute IRI
            }
            $prefixDef = $this->getTermDefinition($prefix);
            if (is_array($prefixDef) && isset($prefixDef[Keyword::Id->value]) && is_string($prefixDef[Keyword::Id->value])) {
                return $prefixDef[Keyword::Id->value].$suffix;
            }

            return $value; // undefined prefix → opaque
        }

        $vocab = $this->getVocab();

        return $vocab !== null ? $vocab.$value : $value;
    }

    private function validateTermSyntax(string $term): void
    {
        // A term MAY be a compact IRI ("ex:date"), an absolute IRI, or
        // otherwise contain ':' / '/' — contexts legitimately define those
        // (e.g. to attach `@type` coercion to a compact-IRI property). The
        // IRI-expansion algorithm already resolves such terms, so storing
        // them is correct. The only hard rule is that a term MUST NOT be a
        // JSON-LD keyword.
        if ($term === '') {
            throw new JsonLdException('Invalid term definition: a term may not be the empty string');
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

        // A term may alias a keyword (e.g. type → @type) but NOT @context.
        if (($definition['@id'] ?? null) === Keyword::Context->value) {
            throw new JsonLdException("Invalid keyword alias in term '{$term}': @id may not be @context");
        }

        // JSON-LD 1.0 has a narrower term-definition vocabulary: @prefix,
        // @nest, and scoped @context were all introduced in 1.1.
        if ($this->isJson10()) {
            foreach ([Keyword::Prefix->value, Keyword::Nest->value, Keyword::Context->value] as $kw) {
                if (array_key_exists($kw, $definition)) {
                    throw new JsonLdException("Invalid term definition '{$term}': {$kw} is not available in JSON-LD 1.0");
                }
            }
        }

        // @prefix may only be set on a simple term — a compact-IRI / IRI term
        // (containing a colon other than first/last, or a slash) may not be a
        // prefix (§4.2.2 step 24).
        if (array_key_exists(Keyword::Prefix->value, $definition) && $this->isIriShapedTerm($term)) {
            throw new JsonLdException("Invalid term definition '{$term}': @prefix is not allowed on a compact-IRI term");
        }

        // @prefix may not be set on a keyword-alias term (its @id is a keyword)
        // — only IRI prefixes can be prefixes (#tpr33).
        if (
            ($definition[Keyword::Prefix->value] ?? false) === true
            && isset($definition[Keyword::Id->value])
            && is_string($definition[Keyword::Id->value])
            && Keyword::contains($definition[Keyword::Id->value])
        ) {
            throw new JsonLdException("Invalid term definition '{$term}': @prefix may not be set on a keyword-alias term");
        }

        if (isset($definition['@type']) && ! is_string($definition['@type'])) {
            throw new JsonLdException("Invalid @type in term '{$term}'");
        }

        // @language coercion must be a string (a BCP47 tag) or null.
        if (
            array_key_exists(Keyword::Language->value, $definition)
            && $definition[Keyword::Language->value] !== null
            && ! is_string($definition[Keyword::Language->value])
        ) {
            throw new JsonLdException("Invalid language mapping in term '{$term}'");
        }

        // A term-definition @type (type coercion) must be a keyword
        // (@id/@vocab/@json/@none) or resolve to an absolute IRI — not a blank
        // node, and not an unresolvable relative IRI (no @vocab to resolve a
        // bare value).
        if (isset($definition['@type']) && is_string($definition['@type'])) {
            $type = $definition['@type'];
            // @type: @none is a JSON-LD 1.1 feature — invalid in 1.0.
            if ($this->isJson10() && $type === Keyword::None->value) {
                throw new JsonLdException("Invalid type mapping in term '{$term}': @type @none requires JSON-LD 1.1");
            }
            $typeKeywords = [Keyword::Id->value, Keyword::Vocab->value, Keyword::Json->value, Keyword::None->value];
            if (! in_array($type, $typeKeywords, true)) {
                // A bare @type (no colon) resolves to an IRI through the active
                // @vocab OR through a previously-defined term in the same
                // context (§4.2.2 step 12 — IRI-expand the type using the local
                // context). It is invalid only when neither resolves it, or
                // when it is a blank-node identifier.
                $resolvesViaTerm = $this->getTermDefinition($type) !== null;
                if (str_starts_with($type, '_:') || (! str_contains($type, ':') && $this->getVocab() === null && ! $resolvesViaTerm)) {
                    throw new JsonLdException("Invalid type mapping in term '{$term}'");
                }
            }
        }

        // A bare term (no @id and no @reverse, no ':'/'/', not a keyword) can
        // only resolve to an IRI through an active @vocab; without one it is an
        // invalid IRI mapping. (@reverse supplies the mapping, so it is exempt.)
        if (
            ! isset($definition['@id'])
            && ! isset($definition['@reverse'])
            && ! str_contains($term, ':')
            && ! str_contains($term, '/')
            && ! Keyword::contains($term)
            && $this->getVocab() === null
        ) {
            throw new JsonLdException("Invalid IRI mapping for term '{$term}': no @id and no @vocab");
        }

        // JSON-LD 1.1 only: when a term is itself IRI-shaped (contains a colon
        // other than as the first/last character, or contains a slash) and has
        // an @id that differs from the term, the IRI expansion of the term
        // must equal its @id mapping (§4.2.2). A keyword @id (e.g. @type) can
        // never equal an IRI-shaped term; a compact-IRI / relative term whose
        // expansion differs from @id is likewise invalid (#ter43/#ter44/#ter48).
        // In JSON-LD 1.0 the consistency check does not apply (#t0026/#t0071).
        if (
            ! $this->isJson10()
            && isset($definition['@id'])
            && is_string($definition['@id'])
            && $definition['@id'] !== $term
            && $this->isIriShapedTerm($term)
        ) {
            $mappedIri = Keyword::contains($definition['@id'])
                ? $definition['@id']
                : $this->localExpandIri($definition['@id']);
            if ($this->localExpandIri($term) !== $mappedIri) {
                throw new JsonLdException("Invalid IRI mapping for term '{$term}': its IRI expansion does not match @id");
            }
        }

        // Cyclic IRI mapping (§4.2.2): a term whose @id is a compact IRI whose
        // prefix is the term itself (e.g. "term": {"@id": "term:term"}) would
        // expand to itself forever — reject it (#ter10). Absolute IRIs
        // (scheme://…), blank nodes (_:) and keyword @ids are not self-prefixes.
        if (isset($definition['@id']) && is_string($definition['@id']) && ! Keyword::contains($definition['@id'])) {
            $id = $definition['@id'];
            $colon = strpos($id, ':');
            if ($colon !== false) {
                $prefix = substr($id, 0, $colon);
                $suffix = substr($id, $colon + 1);
                if ($prefix === $term && $prefix !== '_' && ! str_starts_with($suffix, '//')) {
                    throw new JsonLdException("Invalid IRI mapping for term '{$term}': cyclic IRI mapping");
                }
            }
        }

        if (isset($definition['@container'])) {
            $container = $definition['@container'];

            // JSON-LD 1.0 only recognises a single-string @container drawn from
            // {@list, @set, @index, @language}; array containers and the 1.1
            // additions (@id, @type, @graph) are invalid container mappings.
            if ($this->isJson10()) {
                $valid10 = [Keyword::List->value, Keyword::Set->value, Keyword::Index->value, Keyword::Language->value];
                if (! is_string($container) || ! in_array($container, $valid10, true)) {
                    $repr = is_string($container) ? $container : gettype($container);
                    throw new JsonLdException("Invalid @container in term '{$term}': {$repr} requires JSON-LD 1.1");
                }
            }

            // @container may be a single keyword or an array of keywords
            // (e.g. ["@graph", "@set"] or ["@index", "@set"]). Each entry must
            // be a recognised container type.
            $entries = is_array($container) ? $container : [$container];
            foreach ($entries as $entry) {
                if (! is_string($entry) || ! ContainerType::contains($entry)) {
                    $repr = is_string($entry) ? $entry : gettype($entry);
                    throw new JsonLdException("Invalid @container in term '{$term}': {$repr}");
                }
            }

            // @list is exclusive: it may not be combined with another container
            // (e.g. ["@list", "@set"] is an invalid container mapping).
            if (is_array($container) && in_array(Keyword::List->value, $container, true) && count($container) > 1) {
                throw new JsonLdException("Invalid @container in term '{$term}': @list may not be combined with another container");
            }
        }

        if (array_key_exists('@reverse', $definition)) {
            // @reverse value must be a string (§4.2.2 step 13).
            if (! is_string($definition['@reverse'])) {
                throw new JsonLdException("Invalid reverse property in term '{$term}': @reverse must be a string");
            }
            // @reverse must not coexist with @id or @nest (§4.2.2 step 14).
            if (isset($definition['@id']) || isset($definition['@nest'])) {
                throw new JsonLdException("Invalid reverse property in term '{$term}': @reverse cannot be used with @id or @nest");
            }
            // A reverse term's @container is limited to @set, @index, or none.
            if (isset($definition['@container'])) {
                $reverseContainer = $definition['@container'];
                if (! in_array($reverseContainer, [Keyword::Set->value, Keyword::Index->value], true)) {
                    throw new JsonLdException("Invalid reverse property in term '{$term}': @container must be @set, @index, or absent");
                }
            }
        }

        // @prefix must be a boolean (§4.2.2 step 25).
        if (array_key_exists('@prefix', $definition) && ! is_bool($definition['@prefix'])) {
            throw new JsonLdException("Invalid @prefix value in term '{$term}': must be a boolean");
        }

        // @nest must be a string, and the only keyword it may be is @nest.
        if (isset($definition['@nest'])) {
            $nest = $definition['@nest'];
            if (! is_string($nest) || (str_starts_with($nest, '@') && $nest !== Keyword::Nest->value)) {
                throw new JsonLdException("Invalid @nest value in term '{$term}'");
            }
        }

        if (isset($definition['@container'])) {
            $containerEntries = is_array($definition['@container'])
                ? $definition['@container']
                : [$definition['@container']];

            // When @container includes @type, the term's @type (if any) must be
            // @id or @vocab (§4.2.2 step 19).
            if (
                in_array(Keyword::Type->value, $containerEntries, true)
                && isset($definition['@type'])
                && is_string($definition['@type'])
                && $definition['@type'] !== Keyword::Id->value
                && $definition['@type'] !== Keyword::Vocab->value
            ) {
                throw new JsonLdException("Invalid type mapping in term '{$term}': a @type container requires @type @id or @vocab");
            }
        }

        // Property-valued @index is a JSON-LD 1.1 feature; in 1.0 a term
        // definition carrying @index is an invalid term definition (#tpi01).
        if (array_key_exists('@index', $definition) && $this->isJson10()) {
            throw new JsonLdException("Invalid term definition '{$term}': property-valued @index requires JSON-LD 1.1");
        }

        // Property-valued @index: requires a @container that includes @index
        // and a string @index value that is not itself a keyword.
        if (array_key_exists('@index', $definition)) {
            $containerEntries = isset($definition['@container'])
                ? (is_array($definition['@container']) ? $definition['@container'] : [$definition['@container']])
                : [];
            $index = $definition['@index'];
            if (
                ! in_array(Keyword::Index->value, $containerEntries, true)
                || ! is_string($index)
                || str_starts_with($index, '@')
            ) {
                throw new JsonLdException("Invalid term definition '{$term}': @index requires an @index container and an IRI value");
            }
        }

        if (isset($definition['@protected']) && ! is_bool($definition['@protected'])) {
            throw new JsonLdException("Invalid @protected in term '{$term}'");
        }

        // Validate a nested (scoped) @context if present. It may be a string
        // (a remote context IRI), null, or a map / array of layers; only a
        // bad shape (e.g. a bare number/bool) is rejected here. A map's
        // @protected entry must be boolean.
        if (array_key_exists('@context', $definition) && $definition['@context'] !== null) {
            $scoped = $definition['@context'];
            if (! is_string($scoped) && ! is_array($scoped)) {
                throw new JsonLdException("Invalid @context in term '{$term}'");
            }
            if (is_array($scoped)) {
                foreach ($scoped as $contextKey => $contextValue) {
                    if ($contextKey === Keyword::Protected->value && ! is_bool($contextValue)) {
                        throw new JsonLdException("Invalid @protected in nested context for term '{$term}'");
                    }
                }
            }
        }
    }
}
