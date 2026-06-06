<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Rdf;

/**
 * An RDF quad: a triple (subject, predicate, object) plus an optional graph
 * name. A null graph denotes a statement in the default graph.
 */
final class RdfQuad
{
    public function __construct(
        public readonly RdfTerm $subject,
        public readonly RdfTerm $predicate,
        public readonly RdfTerm $object,
        public readonly ?RdfTerm $graph = null,
    ) {}

    /**
     * Serialise this quad to a single N-Quads line (without trailing newline).
     */
    public function toNQuads(): string
    {
        $line = $this->subject->toNQuads().' '
            .$this->predicate->toNQuads().' '
            .$this->object->toNQuads().' ';

        if ($this->graph !== null) {
            $line .= $this->graph->toNQuads().' ';
        }

        return $line.'.';
    }
}
