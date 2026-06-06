<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Internal;

/**
 * Issues stable blank node identifiers of the form `_:b0`, `_:b1`, … per the
 * JSON-LD 1.1 "Generate Blank Node Identifier" algorithm.
 *
 * A single issuer instance is threaded through Node Map Generation and the
 * subsequent toRdf list conversion so that identifiers are allocated in a
 * deterministic, document-stable order (the order the W3C toRdf fixtures
 * encode).
 */
final class BlankNodeIssuer
{
    /** @var array<string, string> map of input identifier → issued identifier */
    private array $map = [];

    private int $counter = 0;

    public function __construct(
        private readonly string $prefix = '_:b',
    ) {}

    /**
     * Returns the identifier issued for the given existing identifier,
     * issuing (and remembering) a fresh one on first sight. A null argument
     * always allocates a brand-new identifier (for nodes with no `@id`).
     */
    public function getId(?string $identifier = null): string
    {
        if ($identifier !== null && isset($this->map[$identifier])) {
            return $this->map[$identifier];
        }

        $issued = $this->prefix.$this->counter++;

        if ($identifier !== null) {
            $this->map[$identifier] = $issued;
        }

        return $issued;
    }
}
