<?php

declare(strict_types=1);

namespace Accredify\JsonLd;

use Accredify\JsonLd\Algorithms\Compaction;
use Accredify\JsonLd\Algorithms\Expansion;
use Accredify\JsonLd\Algorithms\Flattening;
use Accredify\JsonLd\Algorithms\Framing;
use Accredify\JsonLd\Algorithms\FromRdf;
use Accredify\JsonLd\Algorithms\NodeMap;
use Accredify\JsonLd\Algorithms\ToRdf;
use Accredify\JsonLd\Context\ContextProcessor;
use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Contracts\Processor;
use Accredify\JsonLd\Documents\CompactedDocument;
use Accredify\JsonLd\Documents\ExpandedDocument;
use Accredify\JsonLd\Documents\FlattenedDocument;
use Accredify\JsonLd\Documents\FramedDocument;
use Accredify\JsonLd\Documents\FromRdfDocument;
use Accredify\JsonLd\Documents\RdfDataset;
use Accredify\JsonLd\Enums\Keyword;
use Accredify\JsonLd\Internal\BlankNodeIssuer;
use Accredify\JsonLd\Rdf\NQuadsParser;

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
        return new ExpandedDocument($this->runExpansion($document, $options, frameExpansion: false));
    }

    /**
     * The shared expansion pipeline. A missing @context is valid: the document
     * expands against an empty active context (full-IRI properties survive,
     * unmapped terms drop), so an empty one is injected for ContextProcessor.
     * `$frameExpansion` relaxes the algorithm for a frame document (wildcards,
     * `@id`/`@type` patterns, frame keywords).
     *
     * @param  array<array-key, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function runExpansion(array $document, ?JsonLdOptions $options, bool $frameExpansion): array
    {
        $documentForContext = $document;
        if (! isset($documentForContext['@context'])) {
            $documentForContext['@context'] = [];
        }
        $documentForContext['@context'] = $this->withExpandContext($documentForContext['@context'], $options);

        $contextProcessor = new ContextProcessor($documentForContext, $this->documentLoader, $options?->base, $options?->processingMode);

        $documentWithoutContext = $document;
        unset($documentWithoutContext['@context']);

        return (new Expansion($contextProcessor->getTermDefinitions(), $this->documentLoader, $frameExpansion))
            ->expand($documentWithoutContext);
    }

    /**
     * §10.3 `expandContext` option: an API-supplied context applied BEFORE the
     * document's own `@context`, initialising the active context. A
     * `{"@context": …}` wrapper is unwrapped; a string value is a remote
     * context reference (resolved through the document loader).
     */
    private function withExpandContext(mixed $documentContext, ?JsonLdOptions $options): mixed
    {
        $expandContext = $options?->expandContext;
        if ($expandContext === null) {
            return $documentContext;
        }
        if (is_array($expandContext) && array_key_exists('@context', $expandContext)) {
            $expandContext = $expandContext['@context'];
        }

        return [$expandContext, $documentContext];
    }

    public function compact(array $expanded, array|string $context, ?JsonLdOptions $options = null): CompactedDocument
    {
        // §5.6: compaction operates on the EXPANDED document. Expand the input
        // first (idempotent for already-expanded input) so a document carrying
        // its own @context (e.g. @container definitions) is normalised first.
        $expandedInput = $this->expand($expanded, $options)->toArray();

        return new CompactedDocument($this->compactExpanded($expandedInput, $context, $options));
    }

    /**
     * Compact an ALREADY-expanded document against a context — the
     * post-expansion half of {@see compact()}. Shared with {@see flatten()},
     * whose flattened input is already expanded, so it does not pay for a
     * redundant second expansion pass.
     *
     * @param  array<array-key, mixed>  $expandedInput
     * @param  array<array-key, mixed>|string  $context
     * @return array<string, mixed>
     */
    private function compactExpanded(array $expandedInput, array|string $context, ?JsonLdOptions $options): array
    {
        // Normalise the supplied context into a {@context: …} document for
        // ContextProcessor (which reads the @context key).
        if (is_array($context) && isset($context['@context'])) {
            $contextDocument = $context;
        } else {
            $contextDocument = ['@context' => $context];
        }

        $contextProcessor = new ContextProcessor($contextDocument, $this->documentLoader, $options?->base, $options?->processingMode);
        $compaction = new Compaction($contextProcessor->getTermDefinitions(), $options !== null ? $options->compactArrays : true);

        $compacted = $compaction->compact($expandedInput);

        // Prepend the supplied @context (only when there's content + a
        // non-empty context), matching the spec's output shape.
        $contextValue = is_array($context) && isset($context['@context']) ? $context['@context'] : $context;
        if ($compacted !== [] && $contextValue !== [] && $contextValue !== '') {
            $compacted = [Keyword::Context->value => $contextValue] + $compacted;
        }

        /** @var array<string, mixed> $compacted */
        return $compacted;
    }

    public function flatten(array $document, array|string|null $context = null, ?JsonLdOptions $options = null): FlattenedDocument
    {
        // Expand first. A missing @context is tolerated (the document expands
        // against an empty active context), mirroring toRdf().
        $documentForContext = $document;
        if (! isset($documentForContext['@context'])) {
            $documentForContext['@context'] = [];
        }
        $documentForContext['@context'] = $this->withExpandContext($documentForContext['@context'], $options);

        $contextProcessor = new ContextProcessor($documentForContext, $this->documentLoader, $options?->base, $options?->processingMode);

        $documentWithoutContext = $document;
        unset($documentWithoutContext['@context']);

        $expanded = (new Expansion($contextProcessor->getTermDefinitions(), $this->documentLoader))
            ->expand($documentWithoutContext);

        $flattened = (new Flattening)->flatten($expanded);

        // §4.6 step 7: with no context, return the expanded-flattened form.
        if ($context === null || $context === [] || $context === '') {
            return new FlattenedDocument($flattened);
        }

        // §4.6 step 8: otherwise compact the flattened output against the
        // supplied context. The flattened nodes are ALREADY expanded, so go
        // straight to compactExpanded() (it skips the redundant re-expansion
        // compact() would do); it wraps multiple nodes — and, with
        // compactArrays:false, a single node — in @graph and prepends @context.
        return new FlattenedDocument($this->compactExpanded($flattened, $context, $options));
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
        $documentForContext['@context'] = $this->withExpandContext($documentForContext['@context'], $options);

        $contextProcessor = new ContextProcessor($documentForContext, $this->documentLoader, $options?->base, $options?->processingMode);

        $documentWithoutContext = $document;
        unset($documentWithoutContext['@context']);

        $expanded = (new Expansion($contextProcessor->getTermDefinitions(), $this->documentLoader))
            ->expand($documentWithoutContext);

        return new RdfDataset((new ToRdf($options?->rdfDirection, $options !== null ? $options->produceGeneralizedRdf : false))->toRdf($expanded));
    }

    public function fromRdf(RdfDataset|string $input, ?JsonLdOptions $options = null): FromRdfDocument
    {
        // An N-Quads string is parsed; an RdfDataset is consumed directly (e.g.
        // the output of toRdf()), with no string round-trip.
        $quads = is_string($input) ? (new NQuadsParser)->parse($input) : $input->getQuads();

        $result = (new FromRdf(
            $options !== null && $options->useNativeTypes,
            $options !== null && $options->useRdfType,
            $options?->rdfDirection,
        ))->fromRdf($quads);

        return new FromRdfDocument($result);
    }

    public function frame(array $document, array $frame, ?JsonLdOptions $options = null): FramedDocument
    {
        // Expand the input and build a node map (default graph) to frame over.
        $expandedInput = $this->expand($document, $options)->toArray();
        $nodeMap = (new NodeMap(new BlankNodeIssuer))->generate($expandedInput);
        /** @var array<string, array<string, mixed>> $merged */
        $merged = $nodeMap[Keyword::Default->value] ?? [];

        // Expand the frame against its own @context, in frame-expansion mode
        // (wildcards, @id/@type patterns, and frame keywords are preserved).
        $expandedFrame = $this->runExpansion($frame, $options, frameExpansion: true);
        $frameObject = $expandedFrame[0] ?? [];

        $framed = (new Framing(
            $merged,
            $options?->embed,
            $options !== null && $options->explicit,
            $options !== null && $options->requireAll,
        ))->frame($frameObject);

        // Compact each framed node against the frame's @context, then wrap the
        // result in @graph (unless omitGraph) and prepend that @context.
        $frameContext = array_key_exists(Keyword::Context->value, $frame) ? $frame[Keyword::Context->value] : [];
        $contextProcessor = new ContextProcessor([Keyword::Context->value => $frameContext], $this->documentLoader, $options?->base, $options?->processingMode);
        $compaction = new Compaction($contextProcessor->getTermDefinitions(), $options === null || $options->compactArrays);

        $graph = [];
        foreach ($framed as $node) {
            $graph[] = $compaction->compact([$node]);
        }

        $result = [];
        if ($frameContext !== [] && $frameContext !== '' && $frameContext !== null) {
            $result[Keyword::Context->value] = $frameContext;
        }
        // omitGraph: the explicit option, else the mode default — true for
        // JSON-LD 1.1 (a single top-level node is emitted bare), false for 1.0
        // (always @graph-wrapped).
        $explicitOmitGraph = $options?->omitGraph;
        $processingMode = $options?->processingMode;
        $omitGraph = $explicitOmitGraph ?? (($processingMode ?? 'json-ld-1.1') !== 'json-ld-1.0');
        if ($omitGraph && count($graph) === 1) {
            $result += $graph[0];
        } else {
            $result[Keyword::Graph->value] = $graph;
        }

        /** @var array<string, mixed> $result */
        return new FramedDocument($result);
    }
}
