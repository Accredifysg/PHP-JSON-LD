<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Exceptions;

use Exception;

/**
 * Base exception raised by every JSON-LD algorithm in this package.
 *
 * In Phase 4 this will gain typed subclasses keyed off the
 * {@link https://www.w3.org/TR/json-ld11-api/#jsonlderrorcode JsonLdErrorCode}
 * vocabulary so callers can distinguish, e.g., an invalid `@context` from a
 * loading failure.
 */
class JsonLdException extends Exception {}
