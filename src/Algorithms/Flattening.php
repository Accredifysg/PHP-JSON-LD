<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Enums\Keyword;
use Accredify\JsonLd\Internal\BlankNodeIssuer;
use Accredify\JsonLd\JsonLdProcessor;

/**
 * JSON-LD 1.1 Flattening Algorithm
 * ({@link https://www.w3.org/TR/json-ld11-api/#flattening-algorithm §4.6}).
 *
 * Collects every node object of an expanded document into a single flat array
 * in the default graph — labelling blank nodes deterministically and folding
 * each named graph into a `@graph` entry on its graph-name node — and drops
 * free-floating node references (objects carrying nothing but an `@id`).
 *
 * The heavy lifting (node-map generation, blank-node issuing, list / reverse /
 * `@graph` / `@included` handling) is delegated to {@see NodeMap}, exactly as
 * {@see ToRdf} does; this class only assembles the node map into the flattened
 * output shape. The optional final compaction step (flattening with a context)
 * is performed by {@see JsonLdProcessor::flatten()}, which
 * reuses the Compaction algorithm.
 */
final class Flattening
{
    /**
     * @param  array<mixed>  $expanded  The expanded JSON-LD document.
     * @return list<array<string, mixed>> Flattened node objects, ordered by @id.
     */
    public function flatten(array $expanded): array
    {
        $nodeMap = (new NodeMap(new BlankNodeIssuer))->generate($expanded);

        // The default graph accumulates every node; each named graph is folded
        // in as a `@graph` entry on its graph-name node (§4.6 step 4).
        $defaultGraph = $nodeMap[Keyword::Default->value] ?? [];

        $graphNames = array_keys($nodeMap);
        sort($graphNames, SORT_STRING);
        foreach ($graphNames as $graphName) {
            $graphName = (string) $graphName;
            if ($graphName === Keyword::Default->value) {
                continue;
            }

            // A named graph whose name never appeared as a subject still needs a
            // node to hang its @graph off.
            if (! isset($defaultGraph[$graphName])) {
                $defaultGraph[$graphName] = [Keyword::Id->value => $graphName];
            }

            $defaultGraph[$graphName][Keyword::Graph->value] = $this->collectNodes($nodeMap[$graphName]);
        }

        return $this->collectNodes($defaultGraph);
    }

    /**
     * Returns a graph's nodes ordered by `@id`, dropping free-floating node
     * references (§4.6 steps 4.4 / 6).
     *
     * @param  array<string, array<string, mixed>>  $graph
     * @return list<array<string, mixed>>
     */
    private function collectNodes(array $graph): array
    {
        $ids = array_keys($graph);
        sort($ids, SORT_STRING);

        $nodes = [];
        foreach ($ids as $id) {
            $node = $graph[(string) $id];
            if ($this->isNodeReference($node)) {
                continue;
            }
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * A free-floating node reference: an object whose only key is `@id`.
     *
     * @param  array<string, mixed>  $node
     */
    private function isNodeReference(array $node): bool
    {
        return count($node) === 1 && array_key_exists(Keyword::Id->value, $node);
    }
}
