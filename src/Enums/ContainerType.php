<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Enums;

use Accredify\JsonLd\Internal\EnumContains;

/**
 * Values permitted for a term definition's `@container` mapping.
 *
 * See {@link https://www.w3.org/TR/json-ld11/#container-mapping §3.8} of the
 * JSON-LD 1.1 specification.
 *
 * Note: the spec permits `@container` to also be an *array* combining some of
 * these values (e.g. `["@graph", "@id"]`). That combinatorial validation lives
 * in the term-definition validator, not in this enum.
 */
enum ContainerType: string
{
    use EnumContains;

    case Graph = '@graph';
    case Id = '@id';
    case Index = '@index';
    case Language = '@language';
    case List = '@list';
    case Set = '@set';
    case Type = '@type';
}
