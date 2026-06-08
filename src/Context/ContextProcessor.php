<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Context;

use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Enums\Keyword;
use Accredify\JsonLd\Exceptions\DocumentLoaderException;
use Accredify\JsonLd\Exceptions\JsonLdException;
use Accredify\JsonLd\Internal\IriResolver;
use Accredify\JsonLd\Loaders\HttpDocumentLoader;

/**
 * Walks a JSON-LD document's `@context` declarations and collapses them into
 * a single flat {@see TermDefinitions} map.
 *
 * Lifted from accredifysg/verifiable-credentials-php with two structural
 * changes:
 *
 * 1. Remote-context fetching is delegated to an injected
 *    {@see DocumentLoader} rather than instantiating a Guzzle client
 *    directly. Callers that need to serve bundled contexts (e.g. a
 *    verifiable-credential library shipping VC v2 / Open Badges v3
 *    contexts) provide a loader that returns those locally; everyone else
 *    can plug in {@see HttpDocumentLoader}.
 * 2. The DocumentLoader is required (no implicit default) so the loading
 *    strategy is always explicit.
 *
 * Carries forward the VC implementation's spec gaps: no `@import`,
 * `@propagate`, type-/property-scoped contexts, no real `@protected`
 * enforcement, `@base` and `@vocab` are stored but not applied at IRI
 * expansion time. Phase 4 replaces this with a spec-compliant
 * implementation.
 */
class ContextProcessor
{
    private const MAX_DEPTH = 10;

    private const DEFAULT_VERSION = 1.1;

    private TermDefinitions $termDefinitions;

    /** Effective processing mode ("json-ld-1.0" or "json-ld-1.1"). */
    private readonly string $processingMode;

    /** @var list<array<string, mixed>> */
    private array $processedContexts = [];

    /** @var array<string, true> */
    private array $loadedRemoteContexts = [];

    /**
     * @param  array<array-key, mixed>  $jsonLd  The full JSON-LD document.
     *                                           Must contain `@context`; the rest is ignored.
     * @param  string|null  $processingMode  The JSON-LD processing mode
     *                                       ("json-ld-1.0" / "json-ld-1.1").
     *                                       Null defaults to "json-ld-1.1".
     */
    public function __construct(
        private readonly array $jsonLd,
        private readonly DocumentLoader $documentLoader,
        ?string $baseIri = null,
        ?string $processingMode = null,
    ) {
        if (! isset($jsonLd['@context'])) {
            throw new JsonLdException('Invalid JSON-LD: Missing @context');
        }

        $this->processingMode = $processingMode ?? 'json-ld-1.1';

        $this->termDefinitions = new TermDefinitions;
        $this->termDefinitions->setProcessingMode($this->processingMode);

        // The initial base is the document location (or a caller-supplied
        // base). `@base` declarations in the context can override it.
        $this->termDefinitions->setBase($baseIri);

        $this->processJsonLdContext();
    }

    /** True when the effective processing mode is JSON-LD 1.0. */
    private function isJson10(): bool
    {
        return $this->processingMode === 'json-ld-1.0';
    }

    public function getTermDefinitions(): TermDefinitions
    {
        return $this->termDefinitions;
    }

    private function processJsonLdContext(): void
    {
        $this->processedContexts[] = ['@version' => self::DEFAULT_VERSION];
        $this->processContextLayer($this->jsonLd['@context']);
        $this->mergeContexts();
    }

    private function processContextLayer(mixed $context, int $depth = 0): void
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new JsonLdException('Maximum context depth exceeded');
        }

        if (is_string($context)) {
            $this->handleRemoteContext($context, $depth);

            return;
        }

        if (! is_array($context)) {
            throw new JsonLdException('Context must be string or array');
        }

        $this->handleArrayContext($context, $depth);
    }

    private function handleRemoteContext(string $url, int $depth): void
    {
        // A context reference may be relative; resolve it against the active
        // base before loading (§4.1.2 step 5.2.1).
        $base = $this->termDefinitions->getBase();
        if ($base !== null && $base !== '' && ! filter_var($url, FILTER_VALIDATE_URL)) {
            $url = IriResolver::resolve($base, $url);
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new JsonLdException('Remote context must be a valid URL');
        }

        if (isset($this->loadedRemoteContexts[$url])) {
            throw new JsonLdException("Circular reference detected: {$url}");
        }

        $remoteContext = $this->fetchRemoteContext($url);
        $this->processedContexts[] = $remoteContext;

        if (isset($remoteContext['@context'])) {
            $this->processContextLayer($remoteContext['@context'], $depth + 1);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRemoteContext(string $url): array
    {
        $this->loadedRemoteContexts[$url] = true;

        try {
            $remote = $this->documentLoader->loadDocument($url);
        } catch (DocumentLoaderException $e) {
            unset($this->loadedRemoteContexts[$url]);
            throw new JsonLdException("Failed to load context from {$url}: {$e->getMessage()}", 0, $e);
        }

        $document = $remote->document;
        // Accept both shapes a context endpoint may return:
        //   { "@context": { … } }   (the canonical W3C shape)
        //   { … }                    (a flat term map, no @context wrapper)
        if (isset($document['@context']) && is_array($document['@context'])) {
            return $this->asStringKeyed($document['@context']);
        }

        return $this->asStringKeyed($document);
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    private function handleArrayContext(array $context, int $depth): void
    {
        // List of contexts (string URLs or nested arrays)
        if (isset($context[0])) {
            foreach ($context as $subContext) {
                $this->processContextLayer($subContext, $depth);
            }

            return;
        }

        // A context object that wraps another @context — recurse into it.
        // Some VC context endpoints return `{ "@context": { … } }` at the
        // top level even when not embedded as a term definition.
        if (isset($context['@context']) && is_array($context['@context'])) {
            $this->processContextLayer($context['@context'], $depth + 1);

            return;
        }

        // Map of term -> definition
        $processedContext = [];
        foreach ($context as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (Keyword::contains($key)) {
                $this->validateKeywordValue($key, $value);
            }
            $processedContext[$key] = $value;
        }

        // @import: dereference a single remote context and reverse-merge it
        // beneath this context — the local entries override the imported ones
        // (§4.1.2 step 5.6). This lets a JSON-LD 1.0 context be sourced and
        // upgraded in place.
        if (array_key_exists(Keyword::Import->value, $processedContext)) {
            $import = $processedContext[Keyword::Import->value];
            unset($processedContext[Keyword::Import->value]);
            if (is_string($import)) {
                $base = $this->termDefinitions->getBase();
                $importUrl = $base !== null && $base !== '' ? IriResolver::resolve($base, $import) : $import;
                $imported = $this->fetchImportedContext($importUrl);
                if (array_key_exists(Keyword::Import->value, $imported)) {
                    throw new JsonLdException('Invalid context entry: an imported context must not contain @import');
                }
                $processedContext = array_merge($imported, $processedContext);
            }
        }

        $this->processedContexts[] = $processedContext;
    }

    /**
     * Dereference an `@import` target and return its `@context`, which must be
     * a single map (not an array of contexts).
     *
     * @return array<string, mixed>
     */
    private function fetchImportedContext(string $url): array
    {
        try {
            $remote = $this->documentLoader->loadDocument($url);
        } catch (DocumentLoaderException $e) {
            throw new JsonLdException("Failed to load imported context from {$url}: {$e->getMessage()}", 0, $e);
        }

        $context = $remote->document['@context'] ?? null;
        // @import may only reference a single context object, not a list.
        if (! is_array($context) || (array_is_list($context) && $context !== [])) {
            throw new JsonLdException('Invalid remote context: an @import target must define a single @context map');
        }

        return $this->asStringKeyed($context);
    }

    private function validateKeywordValue(string $key, mixed $value): void
    {
        // Processing-mode gates: JSON-LD 1.0 forbids several context entries
        // that were introduced in 1.1.
        if ($this->isJson10()) {
            // @version: 1.1 under an explicit 1.0 processing mode is a
            // conflict (§4.1.2 — "processing mode conflict").
            if ($key === Keyword::Version->value && $value === self::DEFAULT_VERSION) {
                throw new JsonLdException('processing mode conflict: @version 1.1 is incompatible with processingMode json-ld-1.0');
            }
            // @propagate and @import are 1.1-only context entries.
            if ($key === Keyword::Propagate->value || $key === Keyword::Import->value) {
                throw new JsonLdException("invalid context entry: {$key} is not available in JSON-LD 1.0");
            }
            // A keyword (e.g. @type) may not be redefined with a map in 1.0.
            if ($key === Keyword::Type->value && is_array($value)) {
                throw new JsonLdException('keyword redefinition: @type may not be redefined in JSON-LD 1.0');
            }
        }

        $isValid = match ($key) {
            // The only supported processing mode is JSON-LD 1.1, so @version
            // must be exactly the float 1.1 (a JSON number decodes to float).
            Keyword::Version->value => $value === 1.1,
            // @base accepts any string (absolute, relative, or empty) or null
            // — it is resolved against the active base during merge, so the
            // strict-URL check no longer applies (paired with the resolution
            // landing in this release).
            Keyword::Base->value => $value === null || is_string($value),
            // @vocab accepts an absolute IRI, a compact IRI, a blank node
            // ("_:"), an empty string, or a relative reference — all resolved
            // during merge. Only a non-string (other than null) is invalid.
            Keyword::Vocab->value => $value === null || is_string($value),
            Keyword::Language->value => $value === null || (is_string($value) && preg_match('/^[a-zA-Z]{1,8}(-[a-zA-Z0-9]{1,8})*$/', $value) === 1),
            Keyword::Direction->value => $value === null || $value === 'ltr' || $value === 'rtl',
            // @propagate must be a boolean.
            Keyword::Propagate->value => is_bool($value),
            // @import must be a string (an IRI referencing a single context).
            Keyword::Import->value => is_string($value),
            // @type may only be "redefined" with a map whose keys are a
            // subset of {@container, @protected}, and @container (if present)
            // must be @set. Anything else (a string like "@id", an array, an
            // empty map, other keys) is a keyword redefinition error.
            Keyword::Type->value => $this->isValidTypeRedefinition($value),
            default => true,
        };

        if (! $isValid) {
            $repr = is_scalar($value) ? (string) $value : gettype($value);
            throw new JsonLdException("Invalid {$key} value: {$repr}");
        }
    }

    private function isValidTypeRedefinition(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }
        foreach (array_keys($value) as $key) {
            if ($key !== Keyword::Container->value && $key !== Keyword::Protected->value) {
                return false;
            }
        }
        if (array_key_exists(Keyword::Container->value, $value) && $value[Keyword::Container->value] !== Keyword::Set->value) {
            return false;
        }

        return true;
    }

    private function mergeContexts(): void
    {
        foreach ($this->processedContexts as $context) {
            // Apply @base: relative values resolve against the current
            // effective base; null resets it. array_key_exists (not isset)
            // so an explicit null reset is honoured.
            if (array_key_exists(Keyword::Base->value, $context)) {
                $baseValue = $context[Keyword::Base->value];
                if ($baseValue === null) {
                    $this->termDefinitions->setBase(null);
                } elseif (is_string($baseValue)) {
                    $this->termDefinitions->setBase(
                        IriResolver::establishBase($this->termDefinitions->getBase(), $baseValue)
                    );
                }
            }

            // Apply default @language / @direction (array_key_exists so an
            // explicit null reset is honoured). These are applied to plain
            // string values during value expansion.
            if (array_key_exists(Keyword::Language->value, $context)) {
                $lang = $context[Keyword::Language->value];
                $this->termDefinitions->setDefaultLanguage(is_string($lang) ? $lang : null);
            }
            if (array_key_exists(Keyword::Direction->value, $context)) {
                $dir = $context[Keyword::Direction->value];
                $this->termDefinitions->setDefaultDirection(is_string($dir) ? $dir : null);
            }

            // Apply @vocab. A null clears it; otherwise the value is resolved
            // (compact IRI via a prefix term, empty string / relative against
            // the base or current @vocab, blank node kept as-is) and pushed.
            if (array_key_exists(Keyword::Vocab->value, $context)) {
                $vocabValue = $context[Keyword::Vocab->value];
                if ($vocabValue === null) {
                    $this->termDefinitions->setVocab(null);
                } elseif (is_string($vocabValue)) {
                    $this->termDefinitions->pushVocab($this->resolveVocab($vocabValue));
                }
            }

            // A context whose top-level @protected is true protects every
            // term it defines. Document-level processing never overrides a
            // protected term (override-protected = false).
            $protectedContext = ($context[Keyword::Protected->value] ?? null) === true;

            foreach ($context as $key => $value) {
                if (Keyword::contains($key)) {
                    continue;
                }
                if (is_string($value) || is_array($value)) {
                    $this->termDefinitions->addTermDefinition($key, $value, $protectedContext, false);
                } elseif ($value === null) {
                    // A term mapped to null is explicitly decoupled. Nullifying
                    // a protected term (without override) is a protected-term
                    // redefinition.
                    if ($this->termDefinitions->isProtected($key)) {
                        throw new JsonLdException("Protected term redefinition: '{$key}' is protected and cannot be cleared");
                    }
                    // A null term defined inside a @protected context is itself
                    // protected, so a later (non-override) redefinition of it is
                    // rejected (#tpr28).
                    $this->termDefinitions->termDefinitions[$key] = $protectedContext
                        ? [Keyword::Id->value => null, Keyword::Protected->value => true]
                        : [Keyword::Id->value => null];
                } else {
                    // A term value must be a string, a map, or null (§4.2.2
                    // step 9). A boolean/number is an invalid term definition.
                    throw new JsonLdException("Invalid term definition for '{$key}': value must be a string, map, or null");
                }
            }
        }
    }

    /**
     * Resolve an `@vocab` value to its effective IRI (or blank-node prefix):
     *  - empty string → the active base IRI;
     *  - blank node ("_:…") → kept verbatim;
     *  - compact IRI whose prefix is a defined term → expanded via the prefix;
     *  - absolute IRI (scheme://… or scheme:…) → kept;
     *  - relative reference → appended to the current @vocab if set, else
     *    resolved against the base.
     */
    private function resolveVocab(string $vocab): string
    {
        // JSON-LD 1.0 has no document-relative @vocab: the value must be an
        // absolute IRI (or blank-node prefix). An empty string (which would
        // resolve against the base) or a relative reference (no scheme) is an
        // invalid vocab mapping.
        if ($this->isJson10() && ($vocab === '' || ! str_contains($vocab, ':'))) {
            throw new JsonLdException("invalid vocab mapping: relative @vocab '{$vocab}' is not available in JSON-LD 1.0");
        }

        if ($vocab === '') {
            return $this->termDefinitions->getBase() ?? '';
        }

        if (str_starts_with($vocab, '_:')) {
            return $vocab;
        }

        if (str_contains($vocab, ':')) {
            [$prefix, $suffix] = explode(':', $vocab, 2);
            if (! str_starts_with($suffix, '//')) {
                $prefixDef = $this->termDefinitions->getTermDefinition($prefix);
                if ($prefixDef !== null && isset($prefixDef['@id']) && is_string($prefixDef['@id'])) {
                    return $prefixDef['@id'].$suffix;
                }
            }

            return $vocab; // absolute IRI
        }

        // Relative reference: append to the current @vocab, else resolve
        // against the base.
        $currentVocab = $this->termDefinitions->getVocab();
        if ($currentVocab !== null) {
            return $currentVocab.$vocab;
        }
        $base = $this->termDefinitions->getBase();

        return $base !== null ? IriResolver::resolve($base, $vocab) : $vocab;
    }

    /**
     * @param  array<array-key, mixed>  $array
     * @return array<string, mixed>
     */
    private function asStringKeyed(array $array): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
