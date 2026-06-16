<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Enums;

use Accredify\JsonLd\Internal\EnumContains;

/**
 * The full set of JSON-LD 1.1 keywords as defined in
 * {@link https://www.w3.org/TR/json-ld11/#keywords §1.7 Syntax Tokens}.
 *
 * Keyword aliases (e.g. a context mapping `id` to `@id`) are NOT enum cases —
 * those are term definitions resolved at processing time.
 */
enum Keyword: string
{
    use EnumContains;

    case Base = '@base';
    case Container = '@container';
    case Context = '@context';
    case Default = '@default';
    case Direction = '@direction';
    case Graph = '@graph';
    case Id = '@id';
    case Import = '@import';
    case Included = '@included';
    case Index = '@index';
    case Json = '@json';
    case Language = '@language';
    case List = '@list';
    case Nest = '@nest';
    case None = '@none';
    case Prefix = '@prefix';
    case Propagate = '@propagate';
    case Protected = '@protected';
    case Reverse = '@reverse';
    case Set = '@set';
    case Type = '@type';
    case Value = '@value';
    case Version = '@version';
    case Vocab = '@vocab';

    /**
     * Returns the keyword value with its leading `@` stripped.
     *
     * Used when looking up the keyword's *alias* form in a term map (e.g. when
     * a context declares `"type": "@type"` and a document then uses `type`).
     */
    public function withoutAtSign(): string
    {
        return substr($this->value, 1);
    }
}
