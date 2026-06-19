<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Enums\Keyword;
use Accredify\JsonLd\Exceptions\JsonLdException;
use Accredify\JsonLd\JsonLdProcessor;

/**
 * JSON-LD 1.1 Framing Algorithm
 * ({@link https://www.w3.org/TR/json-ld11-framing/#framing-algorithm}).
 *
 * A faithful port of the reference algorithm (jsonld.js `frame.js`): it runs
 * over the per-graph node map produced from the expanded input and an expanded
 * frame, emitting expanded framed output (matching subjects, referenced nodes
 * embedded inline per the `@embed` flag, defaults injected behind `@preserve`).
 *
 * The pipeline is:
 *   1. build/merge the node map (a frame without `@graph` frames the *merged*
 *      graph; one with `@graph` frames the default graph);
 *   2. recursively frame each matching subject ({@see frameInternal});
 *   3. prune singly-referenced blank-node `@id`s and unwrap `@preserve`
 *      ({@see cleanupPreserve}).
 * Compaction with the frame's `@context` and the post-compaction `@null`
 * cleanup ({@see cleanupNull}) are done by {@see JsonLdProcessor::frame()}.
 *
 * The PHP associative-array document model cannot tell a JSON `{}` from `[]`
 * (both decode to `[]`), so a wildcard `{}` is carried as the
 * {@see Expansion::FRAME_WILDCARD} sentinel through frame expansion, while a
 * bare empty array `[]` is treated as `match none`.
 */
final class Framing
{
    public const EMBED_ONCE = '@once';

    public const EMBED_FIRST = '@first';

    public const EMBED_LAST = '@last';

    public const EMBED_ALWAYS = '@always';

    public const EMBED_NEVER = '@never';

    public const EMBED_LINK = '@link';

    /** @var array<string, array<string, array<string, mixed>>> graph name => subject id => node */
    private array $graphMap;

    private string $embed;

    private bool $explicit;

    private bool $requireAll;

    private bool $omitDefault;

    private bool $pruneBnodes;

    /**
     * The top-level (merged or default) subject map — used for reverse framing
     * and node-pattern matching, which always look at the outermost graph even
     * while recursing into a named `@graph`.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $subjects = [];

    /**
     * Per top-level result tree, the ids already embedded in the current graph
     * (drives `@once`: embed the first occurrence, reference the rest).
     *
     * @var array<string, array<string, true>>
     */
    private array $uniqueEmbeds = [];

    /** @var list<array{id: string, graph: string}> ancestor stack (circular-embed guard) */
    private array $subjectStack = [];

    /** @var array<string, int> blank-node id => number of output objects bearing it (for pruning) */
    private array $bnodeMap = [];

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $graphMap  full node map (all graphs)
     */
    public function __construct(
        array $graphMap,
        ?string $embed,
        bool $explicit,
        bool $requireAll,
        bool $omitDefault = false,
        bool $pruneBnodes = true,
    ) {
        $this->graphMap = $graphMap;
        $this->embed = $embed ?? self::EMBED_ONCE;
        $this->explicit = $explicit;
        $this->requireAll = $requireAll;
        $this->omitDefault = $omitDefault;
        $this->pruneBnodes = $pruneBnodes;
    }

    /**
     * Frame the expanded input, returning the expanded framed output with
     * `@preserve` unwrapped and singly-referenced blank nodes pruned.
     *
     * @param  list<mixed>  $expandedFrame  the expanded frame (a single-object list)
     * @return list<mixed>
     */
    public function frame(array $expandedFrame): array
    {
        $frameObject = $expandedFrame[0] ?? [];
        if (! is_array($frameObject)) {
            throw new JsonLdException('Invalid JSON-LD frame: a frame must be a single object');
        }
        $this->validateFrame($frameObject);

        // A frame without a top-level @graph frames the merged graph (all named
        // graphs folded together); one with @graph frames the default graph.
        if (array_key_exists(Keyword::Graph->value, $frameObject)) {
            $topGraph = Keyword::Default->value;
        } else {
            $this->graphMap['@merged'] = $this->mergeNodeMaps($this->graphMap);
            $topGraph = '@merged';
        }
        $this->subjects = $this->graphMap[$topGraph] ?? [];

        $ids = array_keys($this->subjects);
        sort($ids, SORT_STRING);

        $results = [];
        $this->frameInternal($ids, [$frameObject], $results, null, $topGraph, false);

        $bnodesToClear = $this->pruneBnodes
            ? array_keys(array_filter($this->bnodeMap, static fn (int $count): bool => $count === 1))
            : [];

        /** @var list<mixed> $clean */
        $clean = $this->cleanupPreserve($results, $bnodesToClear);

        return $clean;
    }

    /**
     * The recursive framing core (jsonld.js `api.frame`). Filters `$subjects`
     * against `$frameList`, then for each match builds an output node — emitting
     * a bare reference instead when embedding is disallowed — and appends it to
     * `$parent` (the list to push into).
     *
     * @param  list<string>  $subjects  candidate ids in `$graph`
     * @param  list<mixed>  $frameList  a single-object frame list
     * @param  list<mixed>  $parent  output list, appended to in place
     * @param  string  $graph  current graph name (for `graphMap` lookups)
     */
    private function frameInternal(array $subjects, array $frameList, array &$parent, ?string $property, string $graph, bool $embedded): void
    {
        $frame = $frameList[0] ?? [];
        if (! is_array($frame)) {
            $frame = [];
        }

        $flagEmbed = $this->getEmbedFlag($frame);
        $flagExplicit = $this->getBoolFlag($frame, Keyword::Explicit->value, $this->explicit);
        $flagRequireAll = $this->getBoolFlag($frame, Keyword::RequireAll->value, $this->requireAll);

        // Subjects matching the frame, in lexicographic @id order.
        $matches = [];
        foreach ($subjects as $id) {
            $node = $this->graphMap[$graph][$id] ?? [Keyword::Id->value => $id];
            if ($this->filterSubject($node, $frame, $flagRequireAll)) {
                $matches[$id] = $node;
            }
        }
        $ids = array_keys($matches);
        sort($ids, SORT_STRING);

        foreach ($ids as $id) {
            $id = (string) $id;
            $subject = $matches[$id];

            // Each top-level match is its own compartment for @once tracking.
            if ($property === null) {
                $this->uniqueEmbeds = [$graph => []];
            } elseif (! isset($this->uniqueEmbeds[$graph])) {
                $this->uniqueEmbeds[$graph] = [];
            }

            $output = [Keyword::Id->value => $id];
            if (str_starts_with($id, '_:')) {
                $this->bnodeMap[$id] = ($this->bnodeMap[$id] ?? 0) + 1;
            }

            $alreadyEmbedded = isset($this->uniqueEmbeds[$graph][$id]);

            if (! $embedded) {
                // Already embedded elsewhere in this tree → drop from the top level.
                if ($alreadyEmbedded) {
                    continue;
                }
            } else {
                // @never, or an embed that would form a cycle → bare reference.
                if ($flagEmbed === self::EMBED_NEVER || $this->createsCircularReference($id, $graph)) {
                    $parent[] = $output;

                    continue;
                }
                // @once/@first already embedded once → bare reference.
                if (($flagEmbed === self::EMBED_ONCE || $flagEmbed === self::EMBED_FIRST) && $alreadyEmbedded) {
                    $parent[] = $output;

                    continue;
                }
            }

            $this->uniqueEmbeds[$graph][$id] = true;
            $this->subjectStack[] = ['id' => $id, 'graph' => $graph];

            // The subject is also a graph name → frame its contents under @graph.
            if (isset($this->graphMap[$id])) {
                $hasGraphFrame = array_key_exists(Keyword::Graph->value, $frame);
                if ($hasGraphFrame) {
                    $graphFrameList = $frame[Keyword::Graph->value];
                    $subframe = (is_array($graphFrameList) && isset($graphFrameList[0]) && is_array($graphFrameList[0]))
                        ? $graphFrameList[0]
                        : [];
                    $recurse = ! ($id === '@merged' || $id === Keyword::Default->value);
                } else {
                    $subframe = [];
                    $recurse = ($graph !== '@merged');
                }

                if ($recurse) {
                    $graphIds = array_keys($this->graphMap[$id]);
                    sort($graphIds, SORT_STRING);
                    $graphChild = [];
                    $this->frameInternal($graphIds, [$subframe], $graphChild, Keyword::Graph->value, $id, false);
                    if ($graphChild !== []) {
                        $output[Keyword::Graph->value] = $graphChild;
                    }
                }
            }

            // @included sub-frame: frame the same subjects into @included.
            if (array_key_exists(Keyword::Included->value, $frame)) {
                $includedChild = [];
                $this->frameInternal($subjects, $this->asFrameList($frame[Keyword::Included->value]), $includedChild, Keyword::Included->value, $graph, false);
                if ($includedChild !== []) {
                    $output[Keyword::Included->value] = $includedChild;
                }
            }

            $this->frameProperties($subject, $frame, $output, $flagEmbed, $flagExplicit, $flagRequireAll, $graph);
            $this->injectDefaults($frame, $output);
            $this->frameReverse($frame, $output, $id, $graph, $property);

            $parent[] = $output;
            array_pop($this->subjectStack);
        }
    }

    /**
     * Copy/recurse each of the subject's properties into `$output`: keywords are
     * copied, node references are framed (recursively embedded), `@list`s are
     * rebuilt element-by-element, and plain values are kept when they match the
     * sub-frame's value pattern. `@explicit` drops properties absent from the
     * frame.
     *
     * @param  array<string, mixed>  $subject
     * @param  array<array-key, mixed>  $frame
     * @param  array<string, mixed>  $output  modified in place
     */
    private function frameProperties(array $subject, array $frame, array &$output, string $flagEmbed, bool $flagExplicit, bool $flagRequireAll, string $graph): void
    {
        $properties = array_keys($subject);
        sort($properties, SORT_STRING);

        foreach ($properties as $prop) {
            $prop = (string) $prop;
            $values = $subject[$prop];

            if (str_starts_with($prop, '@')) {
                $output[$prop] = $values;
                if ($prop === Keyword::Type->value) {
                    foreach (is_array($values) ? $values : [$values] as $type) {
                        if (is_string($type) && str_starts_with($type, '_:')) {
                            $this->bnodeMap[$type] = ($this->bnodeMap[$type] ?? 0) + 1;
                        }
                    }
                }

                continue;
            }

            // @explicit: keep only properties named in the frame.
            if ($flagExplicit && ! array_key_exists($prop, $frame)) {
                continue;
            }

            $subframe = array_key_exists($prop, $frame)
                ? $this->asFrameList($frame[$prop])
                : $this->createImplicitFrame($flagEmbed, $flagExplicit, $flagRequireAll);

            $subframePattern = is_array($subframe[0] ?? null) ? $subframe[0] : [];

            $childList = [];
            foreach (is_array($values) && array_is_list($values) ? $values : [$values] as $object) {
                if ($this->isListObject($object) && is_array($object)) {
                    $propFrame = $frame[$prop] ?? null;
                    $listFrame = is_array($propFrame) && isset($propFrame[0]) && is_array($propFrame[0]) ? $propFrame[0] : null;
                    $listSubframe = ($listFrame !== null && isset($listFrame[Keyword::List->value]))
                        ? $this->asFrameList($listFrame[Keyword::List->value])
                        : $this->createImplicitFrame($flagEmbed, $flagExplicit, $flagRequireAll);

                    $listValues = [];
                    foreach ((array) $object[Keyword::List->value] as $listItem) {
                        $listItemId = is_array($listItem) ? ($listItem[Keyword::Id->value] ?? null) : null;
                        if ($this->isSubjectReference($listItem) && is_string($listItemId)) {
                            $this->frameInternal([$listItemId], $listSubframe, $listValues, Keyword::List->value, $graph, true);
                        } else {
                            $listValues[] = $listItem;
                        }
                    }
                    $childList[] = [Keyword::List->value => $listValues];
                } elseif ($this->isSubjectReference($object) && is_array($object) && is_string($object[Keyword::Id->value] ?? null)) {
                    $this->frameInternal([$object[Keyword::Id->value]], $subframe, $childList, $prop, $graph, true);
                } elseif ($this->valueMatch($subframePattern, $object)) {
                    $childList[] = $object;
                }
            }

            if ($childList !== []) {
                $output[$prop] = $childList;
            }
        }
    }

    /**
     * Inject defaults for frame properties missing from the output. Unless the
     * omit-default flag applies, a missing property gets a `@preserve`-wrapped
     * copy of its `@default` (or the `@null` sentinel when none is declared),
     * which {@see cleanupPreserve} / {@see cleanupNull} later resolve.
     *
     * @param  array<array-key, mixed>  $frame
     * @param  array<string, mixed>  $output  modified in place
     */
    private function injectDefaults(array $frame, array &$output): void
    {
        $properties = array_keys($frame);
        sort($properties, SORT_STRING);

        foreach ($properties as $prop) {
            $prop = (string) $prop;
            if ($prop === Keyword::Type->value) {
                $typeFrameList = $frame[Keyword::Type->value] ?? null;
                $typeFrame = is_array($typeFrameList) ? ($typeFrameList[0] ?? null) : null;
                if (! (is_array($typeFrame) && array_key_exists(Keyword::Default->value, $typeFrame))) {
                    continue;
                }
            } elseif (str_starts_with($prop, '@')) {
                continue;
            }

            $propFrameList = $frame[$prop] ?? null;
            $next = is_array($propFrameList) && isset($propFrameList[0]) && is_array($propFrameList[0]) ? $propFrameList[0] : [];
            $omit = $this->getBoolFlag($next, Keyword::OmitDefault->value, $this->omitDefault);

            if (! $omit && ! array_key_exists($prop, $output)) {
                $preserve = array_key_exists(Keyword::Default->value, $next) ? $next[Keyword::Default->value] : '@null';
                if (! (is_array($preserve) && array_is_list($preserve))) {
                    $preserve = [$preserve];
                }
                $output[$prop] = [['@preserve' => $preserve]];
            }
        }
    }

    /**
     * Reverse framing: for each `@reverse` sub-frame, embed every node in the
     * top-level subject map that references this id via the reverse property.
     *
     * @param  array<array-key, mixed>  $frame
     * @param  array<string, mixed>  $output  modified in place
     * @param  ?string  $property  the active property of the carrying node, passed
     *                             through so a nested `@reverse` does not reset the
     *                             top-level `@once` embed compartment
     */
    private function frameReverse(array $frame, array &$output, string $id, string $graph, ?string $property): void
    {
        if (! isset($frame[Keyword::Reverse->value]) || ! is_array($frame[Keyword::Reverse->value])) {
            return;
        }

        $reverse = $frame[Keyword::Reverse->value];
        $reverseProps = array_keys($reverse);
        sort($reverseProps, SORT_STRING);

        $subjectIds = array_keys($this->subjects);
        sort($subjectIds, SORT_STRING);

        foreach ($reverseProps as $reverseProp) {
            $reverseProp = (string) $reverseProp;
            $subframe = $this->asFrameList($reverse[$reverseProp]);

            $reverseValues = [];
            foreach ($subjectIds as $sid) {
                $sid = (string) $sid;
                $references = false;
                foreach ($this->getValues($this->subjects[$sid], $reverseProp) as $value) {
                    if (is_array($value) && ($value[Keyword::Id->value] ?? null) === $id) {
                        $references = true;
                        break;
                    }
                }
                if ($references) {
                    $this->frameInternal([$sid], $subframe, $reverseValues, $property, $graph, true);
                }
            }

            if ($reverseValues !== []) {
                if (! isset($output[Keyword::Reverse->value]) || ! is_array($output[Keyword::Reverse->value])) {
                    $output[Keyword::Reverse->value] = [];
                }
                $output[Keyword::Reverse->value][$reverseProp] = $reverseValues;
            }
        }
    }

    /**
     * Validate a frame: its `@id` / `@type` must not name a blank node (a frame
     * matches on stable identifiers, never on a blank node label).
     *
     * @param  array<array-key, mixed>  $frame
     */
    private function validateFrame(array $frame): void
    {
        foreach ([Keyword::Id->value, Keyword::Type->value] as $key) {
            if (! array_key_exists($key, $frame)) {
                continue;
            }
            $values = $frame[$key];
            foreach (is_array($values) && array_is_list($values) ? $values : [$values] as $value) {
                if (is_string($value) && str_starts_with($value, '_:')) {
                    throw new JsonLdException("Invalid JSON-LD frame: {$key} must not include a blank node identifier");
                }
            }
        }
    }

    /**
     * Frame Matching ({@link https://www.w3.org/TR/json-ld11-framing/#frame-matching}).
     * A subject matches when the frame has no match conditions (wildcard) or it
     * satisfies them — all of them with `@requireAll`, otherwise any one. `@id`
     * and `@type` short-circuit the result when `@requireAll` is off.
     *
     * @param  array<string, mixed>  $subject
     * @param  array<array-key, mixed>  $frame
     */
    private function filterSubject(array $subject, array $frame, bool $requireAll): bool
    {
        $wildcard = true;
        $matchesSome = false;

        foreach ($frame as $key => $frameValue) {
            $key = (string) $key;
            $matchThis = false;
            $nodeValues = $this->getValues($subject, $key);
            $frameValues = $this->getValues($frame, $key);
            $isEmpty = $frameValues === [];

            if ($key === Keyword::Id->value) {
                $frameIds = is_array($frameValue) ? $frameValue : [$frameValue];
                // A wildcard @id ({}, which expands to an empty IRI list) matches
                // any node; otherwise the node's id must be one of the listed IRIs.
                $matchThis = $frameIds === [] || in_array($nodeValues[0] ?? null, $frameIds, true);
                if (! $requireAll) {
                    return $matchThis;
                }
            } elseif ($key === Keyword::Type->value) {
                $wildcard = false;
                $frameTypes = is_array($frameValue) ? $frameValue : [$frameValue];
                if ($isEmpty) {
                    // `@type: []` is match none: the node must have no type.
                    $matchThis = $nodeValues === [];
                } elseif (count($frameTypes) === 1 && $this->isWildcard($frameTypes[0])) {
                    // `@type: {}` is a wildcard: the node must have some type.
                    $matchThis = $nodeValues !== [];
                } else {
                    foreach ($frameTypes as $type) {
                        if (is_array($type) && array_key_exists(Keyword::Default->value, $type)) {
                            $matchThis = true;
                        } else {
                            $matchThis = $matchThis || in_array($type, $nodeValues, true);
                        }
                    }
                }
                if (! $requireAll) {
                    return $matchThis;
                }
            } elseif (str_starts_with($key, '@')) {
                continue;
            } else {
                $thisFrame = $frameValues[0] ?? null;
                $hasDefault = is_array($thisFrame) && array_key_exists(Keyword::Default->value, $thisFrame);
                $wildcard = false;

                // Absent value + a declared default → not a disqualifier.
                if ($nodeValues === [] && $hasDefault) {
                    continue;
                }

                if ($isEmpty) {
                    // `prop: []` is match none: the node must have no value here.
                    if ($nodeValues !== []) {
                        return false;
                    }
                    $matchThis = true;
                } elseif ($this->isListObject($thisFrame) && is_array($thisFrame)) {
                    $thisFrameList = $thisFrame[Keyword::List->value];
                    $listValue = is_array($thisFrameList) ? ($thisFrameList[0] ?? null) : null;
                    $firstNode = $nodeValues[0] ?? null;
                    if ($this->isListObject($firstNode) && is_array($firstNode)) {
                        $nodeListValues = (array) $firstNode[Keyword::List->value];
                        if ($this->isValueObject($listValue)) {
                            $matchThis = $this->someValueMatch($listValue, $nodeListValues);
                        } elseif (is_array($listValue) && (array_key_exists(Keyword::Id->value, $listValue) || ! array_key_exists(Keyword::Value->value, $listValue))) {
                            $matchThis = $this->someNodeMatch($listValue, $nodeListValues, $requireAll);
                        }
                    }
                } elseif ($this->isValueObject($thisFrame)) {
                    $matchThis = $this->someValueMatch($thisFrame, $nodeValues);
                } elseif ($this->isSubjectReference($thisFrame)) {
                    $matchThis = $this->someNodeMatch($thisFrame, $nodeValues, $requireAll);
                } elseif (is_array($thisFrame)) {
                    // A wildcard ({}) or a node-pattern object → match if present.
                    $matchThis = $nodeValues !== [];
                }
            }

            if (! $matchThis && $requireAll) {
                return false;
            }
            $matchesSome = $matchesSome || $matchThis;
        }

        return $wildcard || $matchesSome;
    }

    /**
     * Value Pattern Matching ({@link https://www.w3.org/TR/json-ld11-framing/#value-matching}).
     *
     * @param  array<array-key, mixed>  $pattern
     */
    private function valueMatch(array $pattern, mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        $v1 = $value[Keyword::Value->value] ?? null;
        $t1 = $value[Keyword::Type->value] ?? null;
        $l1 = $value[Keyword::Language->value] ?? null;
        $v2 = $this->patternList($pattern, Keyword::Value->value);
        $t2 = $this->patternList($pattern, Keyword::Type->value);
        $l2 = $this->patternList($pattern, Keyword::Language->value);

        if ($v2 === [] && $t2 === [] && $l2 === []) {
            return true;
        }
        if (! (in_array($v1, $v2, true) || $this->isWildcard($v2[0] ?? null))) {
            return false;
        }
        if (! (($t1 === null && $t2 === []) || in_array($t1, $t2, true) || ($t1 !== null && $this->isWildcard($t2[0] ?? null)))) {
            return false;
        }
        if (! (($l1 === null && $l2 === []) || in_array($l1, $l2, true) || ($l1 !== null && $this->isWildcard($l2[0] ?? null)))) {
            return false;
        }

        return true;
    }

    /**
     * A node reference matches a node pattern when its target node matches the
     * pattern as a frame.
     *
     * @param  array<array-key, mixed>  $pattern
     */
    private function nodeMatch(array $pattern, mixed $value, bool $requireAll): bool
    {
        if (! is_array($value) || ! isset($value[Keyword::Id->value]) || ! is_string($value[Keyword::Id->value])) {
            return false;
        }
        $node = $this->subjects[$value[Keyword::Id->value]] ?? null;

        return is_array($node) && $this->filterSubject($node, $pattern, $requireAll);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function someValueMatch(mixed $pattern, array $values): bool
    {
        if (! is_array($pattern)) {
            return false;
        }
        foreach ($values as $value) {
            if ($this->valueMatch($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function someNodeMatch(mixed $pattern, array $values, bool $requireAll): bool
    {
        if (! is_array($pattern)) {
            return false;
        }
        foreach ($values as $value) {
            if ($this->nodeMatch($pattern, $value, $requireAll)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merge Node Maps ({@link https://www.w3.org/TR/json-ld11-api/#merge-node-maps}):
     * fold every named graph into a single id-keyed map, unioning property
     * values (keywords other than `@type` are copied verbatim).
     *
     * @param  array<string, array<string, array<string, mixed>>>  $graphMap
     * @return array<string, array<string, mixed>>
     */
    private function mergeNodeMaps(array $graphMap): array
    {
        $merged = [];
        $names = array_keys($graphMap);
        sort($names, SORT_STRING);

        foreach ($names as $name) {
            $graph = $graphMap[$name];
            $ids = array_keys($graph);
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $id = (string) $id;
                $node = $graph[$id];
                if (! isset($merged[$id])) {
                    $merged[$id] = [Keyword::Id->value => $id];
                }
                $properties = array_keys($node);
                sort($properties, SORT_STRING);
                foreach ($properties as $property) {
                    $property = (string) $property;
                    if (str_starts_with($property, '@') && $property !== Keyword::Type->value) {
                        $merged[$id][$property] = $node[$property];

                        continue;
                    }
                    foreach (is_array($node[$property]) ? $node[$property] : [$node[$property]] as $value) {
                        $this->addValueNoDuplicate($merged[$id], $property, $value);
                    }
                }
            }
        }

        return $merged;
    }

    /**
     * Post-framing cleanup (jsonld.js `_cleanupPreserve`): unwrap
     * `{@preserve: [x]}` to `x`, leave value/`@list` objects intact, and drop
     * the `@id` of blank nodes that are referenced only once.
     *
     * @param  list<string>  $bnodesToClear
     */
    private function cleanupPreserve(mixed $input, array $bnodesToClear): mixed
    {
        if (is_array($input) && array_is_list($input)) {
            return array_map(fn (mixed $value): mixed => $this->cleanupPreserve($value, $bnodesToClear), $input);
        }

        if (! is_array($input)) {
            return $input;
        }

        // A wildcard sentinel that reached the output (e.g. via `@default: {}`)
        // becomes the empty object it stood for.
        if (array_key_exists(Expansion::FRAME_WILDCARD, $input)) {
            return [];
        }

        if (array_key_exists('@preserve', $input)) {
            $preserved = $input['@preserve'];
            $first = is_array($preserved) && array_key_exists(0, $preserved) ? $preserved[0] : $preserved;

            return $this->cleanupPreserve($first, $bnodesToClear);
        }

        if (array_key_exists(Keyword::Value->value, $input)) {
            return $input;
        }

        if (array_key_exists(Keyword::List->value, $input)) {
            $input[Keyword::List->value] = $this->cleanupPreserve($input[Keyword::List->value], $bnodesToClear);

            return $input;
        }

        $result = [];
        foreach ($input as $key => $value) {
            if ($key === Keyword::Id->value && is_string($value) && in_array($value, $bnodesToClear, true)) {
                continue;
            }
            $result[$key] = $this->cleanupPreserve($value, $bnodesToClear);
        }

        return $result;
    }

    /**
     * Post-compaction cleanup (jsonld.js `_cleanupNull`): replace the `@null`
     * sentinel with `null` and drop nulls from arrays (so an absent `@set`
     * default collapses to `[]`).
     */
    public static function cleanupNull(mixed $input): mixed
    {
        if (is_array($input) && array_is_list($input)) {
            $result = [];
            foreach ($input as $value) {
                $cleaned = self::cleanupNull($value);
                if ($cleaned !== null) {
                    $result[] = $cleaned;
                }
            }

            return $result;
        }

        if ($input === '@null') {
            return null;
        }

        if (is_array($input)) {
            $result = [];
            foreach ($input as $key => $value) {
                $result[$key] = self::cleanupNull($value);
            }

            return $result;
        }

        return $input;
    }

    private function createsCircularReference(string $id, string $graph): bool
    {
        for ($i = count($this->subjectStack) - 1; $i >= 0; $i--) {
            if ($this->subjectStack[$i]['graph'] === $graph && $this->subjectStack[$i]['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * The effective `@embed` for a frame: its own `@embed` (normalising the 1.0
     * boolean form, `true`→`@once` / `false`→`@never`) or the inherited default.
     *
     * @param  array<array-key, mixed>  $frame
     */
    private function getEmbedFlag(array $frame): string
    {
        $raw = array_key_exists(Keyword::Embed->value, $frame) ? $frame[Keyword::Embed->value] : $this->embed;
        if (is_array($raw) && array_is_list($raw)) {
            $raw = $raw[0] ?? null;
        }
        if ($raw === true) {
            $raw = self::EMBED_ONCE;
        } elseif ($raw === false) {
            $raw = self::EMBED_NEVER;
        }

        if (! in_array($raw, [self::EMBED_ALWAYS, self::EMBED_NEVER, self::EMBED_LINK, self::EMBED_FIRST, self::EMBED_LAST, self::EMBED_ONCE], true)) {
            throw new JsonLdException('Invalid JSON-LD frame: invalid value of @embed');
        }

        return $raw;
    }

    /**
     * @param  array<array-key, mixed>  $frame
     */
    private function getBoolFlag(array $frame, string $flag, bool $default): bool
    {
        if (! array_key_exists($flag, $frame)) {
            return $default;
        }
        $raw = $frame[$flag];
        if (is_array($raw) && array_is_list($raw)) {
            $raw = $raw[0] ?? $default;
        }

        return (bool) $raw;
    }

    /**
     * The implicit wildcard sub-frame used when a property has no explicit
     * frame: it carries the inherited flags and matches any value.
     *
     * @return list<array<string, mixed>>
     */
    private function createImplicitFrame(string $embed, bool $explicit, bool $requireAll): array
    {
        return [[
            Keyword::Embed->value => $embed,
            Keyword::Explicit->value => $explicit,
            Keyword::RequireAll->value => $requireAll,
        ]];
    }

    /**
     * Normalise a frame entry to a single-object list (expanded property values
     * are already lists; a bare map is wrapped).
     *
     * @return list<mixed>
     */
    private function asFrameList(mixed $value): array
    {
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }

        return [$value];
    }

    /**
     * The values of `$key` in `$map` as a list (a scalar/map becomes a
     * single-element list; an absent key becomes the empty list).
     *
     * @return list<mixed>
     */
    private function getValues(mixed $map, string $key): array
    {
        if (! is_array($map) || ! array_key_exists($key, $map)) {
            return [];
        }
        $value = $map[$key];
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }

        return [$value];
    }

    /**
     * A value-object pattern field (`@value`/`@type`/`@language`) as a list of
     * candidates, or the empty list when absent.
     *
     * @param  array<array-key, mixed>  $pattern
     * @return list<mixed>
     */
    private function patternList(array $pattern, string $key): array
    {
        if (! array_key_exists($key, $pattern) || $pattern[$key] === null) {
            return [];
        }
        $value = $pattern[$key];
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }

        return [$value];
    }

    /**
     * Whether a frame value is a wildcard (`{}`), carried as the
     * {@see Expansion::FRAME_WILDCARD} sentinel by frame expansion.
     */
    private function isWildcard(mixed $value): bool
    {
        return is_array($value) && array_key_exists(Expansion::FRAME_WILDCARD, $value);
    }

    private function isValueObject(mixed $value): bool
    {
        return is_array($value) && array_key_exists(Keyword::Value->value, $value);
    }

    private function isListObject(mixed $value): bool
    {
        return is_array($value) && array_key_exists(Keyword::List->value, $value);
    }

    private function isSubjectReference(mixed $value): bool
    {
        return is_array($value) && count($value) === 1 && array_key_exists(Keyword::Id->value, $value);
    }

    /**
     * Append `$value` to `$node[$property]` (as an array) unless an equal value
     * is already present.
     *
     * @param  array<string, mixed>  $node  modified in place
     */
    private function addValueNoDuplicate(array &$node, string $property, mixed $value): void
    {
        if (! isset($node[$property]) || ! is_array($node[$property]) || ! array_is_list($node[$property])) {
            $node[$property] = isset($node[$property]) ? [$node[$property]] : [];
        }
        foreach ($node[$property] as $existing) {
            if ($this->deepEquals($existing, $value)) {
                return;
            }
        }
        $node[$property][] = $value;
    }

    private function deepEquals(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (! array_key_exists($key, $b) || ! $this->deepEquals($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }
}
