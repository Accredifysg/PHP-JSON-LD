<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Tests\W3c;

use RuntimeException;

/**
 * Thrown by harness processors that don't yet implement an algorithm.
 *
 * The Pest harness catches this and marks the test as skipped, which
 * keeps the W3C testsuite output meaningful while the package is still
 * being built out.
 */
final class NotImplementedException extends RuntimeException {}
