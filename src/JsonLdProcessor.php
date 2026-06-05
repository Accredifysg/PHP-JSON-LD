<?php

declare(strict_types=1);

namespace Accredify\JsonLd;

use Accredify\JsonLd\Algorithms\Expansion;
use Accredify\JsonLd\Context\ContextProcessor;
use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Contracts\Processor;
use Accredify\JsonLd\Documents\ExpandedDocument;

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

    public function expand(array $document, ?string $base = null): ExpandedDocument
    {
        $contextProcessor = new ContextProcessor($document, $this->documentLoader, $base);

        $documentWithoutContext = $document;
        unset($documentWithoutContext['@context']);

        $expansion = new Expansion($contextProcessor->getTermDefinitions());

        return new ExpandedDocument(
            $expansion->expand($documentWithoutContext),
        );
    }
}
