<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Exceptions;

use Accredify\JsonLd\Contracts\DocumentLoader;

/**
 * Raised when a {@see DocumentLoader} fails to
 * fetch or parse a remote document.
 *
 * Maps to the JSON-LD 1.1 error code
 * {@link https://www.w3.org/TR/json-ld11-api/#dom-jsonlderrorcode-loading-document-failed `loading document failed`}.
 */
class DocumentLoaderException extends JsonLdException {}
