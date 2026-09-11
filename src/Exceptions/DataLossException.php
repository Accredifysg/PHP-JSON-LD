<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Exceptions;

/**
 * Raised in safe mode (`JsonLdOptions::$safe`) when the processor would
 * otherwise silently drop data — an undefined term, a relative IRI that
 * cannot become an RDF term, a malformed value whose statement is skipped.
 *
 * Maps to the W3C VC-DATA-INTEGRITY 1.0 §2.4.3 "Securing Data Losslessly"
 * requirement (`DATA_LOSS_DETECTION_ERROR`): implementations that use
 * JSON-LD processing MUST throw an error when data is dropped by a JSON-LD
 * processor, such as when an undefined term is detected in an input
 * document.
 *
 * {@see $eventCode} uses the jsonld.js safe-mode event catalog strings
 * (`lib/events.js`) where an equivalent site exists — e.g. "invalid
 * property", "relative subject reference", "reserved @id value" — so an
 * event system could be retrofitted later without changing call sites.
 * Codes for fork-specific drop sites jsonld.js does not have (e.g.
 * "context load failed", "invalid map key") are documented in the README's
 * Safe mode section.
 *
 * The message always reads `Safe mode: {prose} ({eventCode})`, e.g.
 * `Safe mode: term 'alumniOf' does not expand to an absolute IRI or
 * keyword (invalid property)`.
 */
final class DataLossException extends JsonLdException
{
    /**
     * @param  string  $eventCode  jsonld.js-compatible event code naming the
     *                             kind of drop (see class docblock).
     * @param  string  $message  Prose describing the dropped datum; the
     *                           "Safe mode:" prefix and "({$eventCode})"
     *                           suffix are appended here.
     * @param  array<string, mixed>  $details  The dropped datum and its
     *                                         surroundings (term, value, id,
     *                                         subject, …), for programmatic
     *                                         consumers.
     */
    public function __construct(
        public readonly string $eventCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct("Safe mode: {$message} ({$eventCode})");
    }
}
