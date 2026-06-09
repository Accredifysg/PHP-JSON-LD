<?php

declare(strict_types=1);

namespace Accredify\JsonLd;

use Accredify\JsonLd\Algorithms\Compaction;
use Accredify\JsonLd\Algorithms\Expansion;
use Accredify\JsonLd\Algorithms\ToRdf;
use Accredify\JsonLd\Context\ContextProcessor;
use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Contracts\Processor;
use Accredify\JsonLd\Documents\CompactedDocument;
use Accredify\JsonLd\Documents\ExpandedDocument;
use Accredify\JsonLd\Documents\RdfDataset;
use Accredify\JsonLd\Enums\Keyword;

/**
 * Default {@see Processor} implementation.
 *
 * Wires {@see ContextProcessor} → {@see Expansion} for callers who don't
 * need the intermediate stages directly. The {@see DocumentLoader}
 * required by ContextProcessor is injected here and passed through.
 *
 * Usage:
 *
 *   $loader = new CachingDocumentLoader(new HttpDocumentLoader($psr18, $psr17));
 *   $processor = new JsonLdProcessor($loader);
 *   $expanded = $processor->expand($document);
 *
 * If you want to share state across multiple `expand()` calls (e.g. a
 * cache of resolved contexts), reuse the same JsonLdProcessor instance.
 * Otherwise a fresh ContextProcessor + Expansion pair is created per call.
 */
final class JsonLdProcessor implements Processor
{
    public function __construct(
        private readonly DocumentLoader $documentLoader,
    ) {}

    public function expand(array $document, ?JsonLdOptions $options = null): ExpandedDocument
    {
        // A missing @context is valid: the document expands against an empty
        // active context (full-IRI properties survive, unmapped terms drop).
        // Inject an empty one so ContextProcessor (which requires the key)
        // does not reject the document. (§5.1 — expand has no @context
        // precondition; toRdf relies on the same tolerance.)
        $documentForContext = $document;
        if (! isset($documentForContext['@context'])) {
            $documentForContext['@context'] = [];
        }

        $contextProcessor = new ContextProcessor($documentForContext, $this->documentLoader, $options?->base, $options?->processingMode);

        $documentWithoutContext = $document;
        unset($documentWithoutContext['@context']);

        $expansion = new Expansion($contextProcessor->getTermDefinitions(), $this->documentLoader);

        return new ExpandedDocument(
            $expansion->expand($documentWithoutContext),
        );
    }

    public function compact(array $expanded, array|string $context, ?JsonLdOptions $options = null): CompactedDocument
    {
        // Normalise the supplied context into a {@context: …} document for
        // ContextProcessor (which reads the @context key).
        if (is_array($context) && isset($context['@context'])) {
            $contextDocument = $context;
        } else {
            $contextDocument = ['@context' => $context];
        }

        // §5.6: compaction operates on the EXPANDED document. Expand the input
        // first (idempotent for already-expanded input) so a document carrying
        // its own @context (e.g. @container definitions) is normalised first.
        $expandedInput = $this->expand($expanded, $options)->toArray();

        $contextProcessor = new ContextProcessor($contextDocument, $this->documentLoader, $options?->base, $options?->processingMode);
        $compaction = new Compaction($contextProcessor->getTermDefinitions());

        $compacted = $compaction->compact($expandedInput);

        // Prepend the supplied @context (only when there's content + a
        // non-empty context), matching the spec's output shape.
        $contextValue = is_array($context) && isset($context['@context']) ? $context['@context'] : $context;
        if ($compacted !== [] && $contextValue !== [] && $contextValue !== '') {
            $compacted = [Keyword::Context->value => $contextValue] + $compacted;
        }

        /** @var array<string, mixed> $compacted */
        return new CompactedDocument($compacted);
    }

    public function toRdf(array $document, ?JsonLdOptions $options = null): RdfDataset
    {
        // A missing @context is tolerated for toRdf: documents that address
        // their predicates with full IRIs need no context. Inject an empty
        // one so ContextProcessor (which requires the key) expands against an
        // empty active context rather than throwing.
        $documentForContext = $document;
        if (! isset($documentForContext['@context'])) {
            $documentForContext['@context'] = [];
        }

        $contextProcessor = new ContextProcessor($documentForContext, $this->documentLoader, $options?->base, $options?->processingMode);

        $documentWithoutContext = $document;
        unset($documentWithoutContext['@context']);

        $expanded = (new Expansion($contextProcessor->getTermDefinitions(), $this->documentLoader))
            ->expand($documentWithoutContext);

        return new RdfDataset((new ToRdf($options?->rdfDirection))->toRdf($expanded));
    }
}
