<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Internal;

/**
 * Provides a `contains()` helper for string-backed enums.
 *
 * @internal Not part of the package's public API; subject to change.
 */
trait EnumContains
{
    /**
     * Returns true if the given value matches any of the enum's backing values.
     *
     * Case-sensitive. Use on `enum X: string` only; behaviour is undefined for
     * non-backed enums.
     */
    public static function contains(string $value): bool
    {
        foreach (static::cases() as $case) {
            if ($case->value === $value) {
                return true;
            }
        }

        return false;
    }
}
