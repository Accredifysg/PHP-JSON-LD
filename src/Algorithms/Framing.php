<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Enums\Keyword;
use Accredify\JsonLd\JsonLdProcessor;

/**
 * JSON-LD 1.1 Framing Algorithm
 * ({@link https://www.w3.org/TR/json-ld11-framing/#framing-algorithm}).
 *
 * Operates over a node map (subject id → node object, produced from the
 * expanded input) and an expanded frame, producing expanded framed output:
 * nodes matching the frame, with referenced nodes embedded inline per the
 * `@embed` flag. Post-processing (blank-node pruning, `@preserve` unwrap) and
 * compaction with the frame's `@context` are done by
 * {@see JsonLdProcessor::frame()}.
 *
 * This is an evolving implementation; advanced cases (`@default` injection via
 * frame expansion, `@reverse`/`@graph` framing) are layered in incrementally
 * and any unsupported W3C cases are carried as documented blockers.
 */
final class Framing
{
    public const EMBED_ONCE = '@once';

    public const EMBED_LAST = '@last';

    public const EMBED_ALWAYS = '@always';

    public const EMBED_NEVER = '@never';

    /** @var array<string, array<string, mixed>> subject id => node object */
    private array $nodeMap;

    private string $embed;

    private bool $explicit;

    private bool $requireAll;

    /**
     * @param  array<string, array<string, mixed>>  $nodeMap
     */
    public function __construct(
        array $nodeMap,
        ?string $embed,
        bool $explicit,
        bool $requireAll,
    ) {
        $this->nodeMap = $nodeMap;
        $this->embed = $embed ?? self::EMBED_ONCE;
        $this->explicit = $explicit;
        $this->requireAll = $requireAll;
    }

    /**
     * Frame the top level: every node matching the frame, in `@id` order.
     *
     * @param  array<string, mixed>  $frame  the expanded frame object
     * @return list<array<string, mixed>>
     */
    public function frame(array $frame): array
    {
        $ids = array_keys($this->nodeMap);
        sort($ids, SORT_STRING);

        $embedded = [];
        $result = [];
        foreach ($ids as $id) {
            if ($this->matches($this->nodeMap[(string) $id], $frame)) {
                $result[] = $this->frameNode((string) $id, $frame, $this->embed, $embedded, []);
            }
        }

        return $result;
    }

    /**
     * Build the framed output for one node: embed it (per `@embed`) or emit a
     * bare `{@id}` reference, recursing into node-reference values.
     *
     * @param  array<string, mixed>  $frame
     * @param  string  $embed  the effective @embed mode for this node
     * @param  array<string, true>  $embedded  ids already embedded anywhere in this output (for @once)
     * @param  list<string>  $path  ids on the current ancestor path (cycle guard)
     * @return array<string, mixed>
     */
    private function frameNode(string $id, array $frame, string $embed, array &$embedded, array $path): array
    {
        // Emit a bare reference rather than embedding when: @never; @once and
        // this node was already embedded somewhere; or it is already on the
        // current ancestor path (cycle guard — @always / @last embed fully but
        // must not recurse into themselves).
        if (
            $embed === self::EMBED_NEVER
            || ($embed === self::EMBED_ONCE && isset($embedded[$id]))
            || in_array($id, $path, true)
        ) {
            return [Keyword::Id->value => $id];
        }

        $node = $this->nodeMap[$id] ?? [Keyword::Id->value => $id];
        $embedded[$id] = true;
        $path[] = $id;

        $output = [Keyword::Id->value => $id];
        if (isset($node[Keyword::Type->value])) {
            $output[Keyword::Type->value] = $node[Keyword::Type->value];
        }

        foreach ($node as $property => $values) {
            if ($property === Keyword::Id->value || $property === Keyword::Type->value) {
                continue;
            }
            if (str_starts_with($property, '@')) {
                $output[$property] = $values;

                continue;
            }

            $subFrame = $this->subFrame($frame, $property);

            // @explicit: only properties named in the frame survive.
            if ($this->explicit && $subFrame === null) {
                continue;
            }

            if (! is_array($values)) {
                $output[$property] = $values;

                continue;
            }

            // The sub-frame may override @embed for this property's values.
            $propEmbed = $this->embedFor($subFrame, $embed);
            $framedValues = [];
            foreach ($values as $value) {
                $framedValues[] = $this->frameValue($value, $subFrame, $propEmbed, $embedded, $path);
            }
            $output[$property] = $framedValues;
        }

        return $output;
    }

    /**
     * The effective `@embed` mode for a property's values: the sub-frame's own
     * `@embed` (accepting both the 1.0 boolean/`@last` and 1.1 `@once`/
     * `@always`/`@never` forms) or, absent one, the inherited mode.
     *
     * @param  array<string, mixed>|null  $subFrame
     */
    private function embedFor(?array $subFrame, string $inherited): string
    {
        if ($subFrame === null || ! array_key_exists(Keyword::Embed->value, $subFrame)) {
            return $inherited;
        }

        return match ($subFrame[Keyword::Embed->value]) {
            false, self::EMBED_NEVER => self::EMBED_NEVER,
            self::EMBED_ALWAYS => self::EMBED_ALWAYS,
            self::EMBED_LAST => self::EMBED_LAST,
            true, self::EMBED_ONCE => self::EMBED_ONCE,
            default => $inherited,
        };
    }

    /**
     * Frame a single property value: a node reference is embedded (recursively),
     * any other value object is copied verbatim.
     *
     * @param  array<string, mixed>|null  $subFrame
     * @param  string  $embed  the effective @embed mode for this value
     * @param  array<string, true>  $embedded
     * @param  list<string>  $path
     */
    private function frameValue(mixed $value, ?array $subFrame, string $embed, array &$embedded, array $path): mixed
    {
        if (is_array($value)
            && isset($value[Keyword::Id->value])
            && is_string($value[Keyword::Id->value])
            && ! array_key_exists(Keyword::Value->value, $value)
            && isset($this->nodeMap[$value[Keyword::Id->value]])
        ) {
            return $this->frameNode($value[Keyword::Id->value], $subFrame ?? [], $embed, $embedded, $path);
        }

        return $value;
    }

    /**
     * The sub-frame for a property: the frame's value for that property (a map),
     * or null when the frame doesn't mention it.
     *
     * @param  array<string, mixed>  $frame
     * @return array<string, mixed>|null
     */
    private function subFrame(array $frame, string $property): ?array
    {
        if (! array_key_exists($property, $frame)) {
            return null;
        }
        $value = $frame[$property];
        // The frame value is expanded to a list; the sub-frame is its first map.
        if (is_array($value) && array_is_list($value) && isset($value[0]) && is_array($value[0])) {
            /** @var array<string, mixed> $first */
            $first = $value[0];

            return $first;
        }
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        return [];
    }

    /**
     * Frame matching: a node matches when every frame condition holds (an empty
     * frame matches everything). `@requireAll` requires all listed properties;
     * otherwise any one suffices.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $frame
     */
    private function matches(array $node, array $frame): bool
    {
        // @id is a hard filter when the frame supplies concrete ids.
        if (array_key_exists(Keyword::Id->value, $frame) && ! $this->matchesId($node, $frame[Keyword::Id->value])) {
            return false;
        }

        // When the frame specifies @type, the match is decided by @type alone
        // (intersection / wildcard / match-none) — a frame's other properties
        // drive embedding, not the top-level match (so a `Library` frame does
        // not also pull in a `Book` merely because both have `contains`).
        if (array_key_exists(Keyword::Type->value, $frame)) {
            return $this->matchesType($node, $frame[Keyword::Type->value]);
        }

        // Otherwise match on the frame's properties: an empty frame matches
        // every node; @requireAll needs all properties present, else any.
        $conditions = [];
        foreach ($frame as $key => $pattern) {
            if (str_starts_with($key, '@')) {
                continue;
            }
            $conditions[] = isset($node[$key]);
        }

        if ($conditions === []) {
            return true;
        }

        return $this->requireAll ? ! in_array(false, $conditions, true) : in_array(true, $conditions, true);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function matchesType(array $node, mixed $pattern): bool
    {
        $frameTypes = array_values(array_filter(is_array($pattern) ? $pattern : [$pattern], 'is_string'));
        $nodeTypes = isset($node[Keyword::Type->value]) && is_array($node[Keyword::Type->value])
            ? array_values(array_filter($node[Keyword::Type->value], 'is_string'))
            : [];

        if ($frameTypes === []) {
            // @type with no concrete IRI is a wildcard: the node must have a type.
            return $nodeTypes !== [];
        }

        return array_intersect($frameTypes, $nodeTypes) !== [];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function matchesId(array $node, mixed $pattern): bool
    {
        $ids = array_values(array_filter(is_array($pattern) ? $pattern : [$pattern], 'is_string'));
        if ($ids === []) {
            return true; // wildcard @id
        }
        $nodeId = isset($node[Keyword::Id->value]) && is_string($node[Keyword::Id->value]) ? $node[Keyword::Id->value] : null;

        return $nodeId !== null && in_array($nodeId, $ids, true);
    }
}
