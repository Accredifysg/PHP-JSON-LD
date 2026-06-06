<?php

declare(strict_types=1);

namespace Accredify\JsonLd\Algorithms;

use Accredify\JsonLd\Enums\Keyword;
use Accredify\JsonLd\Exceptions\JsonLdException;
use Accredify\JsonLd\Internal\BlankNodeIssuer;

/**
 * Node Map Generation
 * ({@link https://www.w3.org/TR/json-ld11-api/#node-map-generation §7.2}).
 *
 * Walks an expanded JSON-LD document and collapses it into a "node map": a
 * map from graph name → subject id → node object, with blank node identifiers
 * issued deterministically via a shared {@see BlankNodeIssuer}. This is the
 * flattening core that the toRdf algorithm consumes.
 *
 * The output shape is:
 *
 *   [
 *     '@default' => [
 *        '<subject-iri-or-bnode>' => [
 *           '@id'        => '<subject>',
 *           '@type'      => ['<type-iri>', …],          // optional
 *           '<predicate>'=> [ <value object|node ref|list object>, … ],
 *           …
 *        ],
 *        …
 *     ],
 *     '<named-graph>' => [ … ],
 *   ]
 */
final class NodeMap
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $nodeMap = ['@default' => []];

    public function __construct(
        private readonly BlankNodeIssuer $issuer,
    ) {}

    /**
     * @param  array<mixed>  $expanded  The expanded JSON-LD document.
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function generate(array $expanded): array
    {
        $noList = null;
        $this->generateNodeMap($expanded, '@default', null, null, $noList);

        return $this->nodeMap;
    }

    /**
     * @param  string|array<string, mixed>|null  $activeSubject  A subject id,
     *                                                           a referenced-node map (reverse case), or null.
     * @param  array{'@list': list<mixed>}|null  $list
     */
    private function generateNodeMap(
        mixed $element,
        string $activeGraph,
        string|array|null $activeSubject,
        ?string $activeProperty,
        ?array &$list,
    ): void {
        if (is_array($element) && array_is_list($element)) {
            foreach ($element as $item) {
                $this->generateNodeMap($item, $activeGraph, $activeSubject, $activeProperty, $list);
            }

            return;
        }

        if (! is_array($element)) {
            return;
        }

        if (! isset($this->nodeMap[$activeGraph])) {
            $this->nodeMap[$activeGraph] = [];
        }

        // Relabel blank-node @type values up front so every later reference is
        // consistent.
        if (isset($element[Keyword::Type->value]) && is_array($element[Keyword::Type->value])) {
            $element[Keyword::Type->value] = array_map(
                fn (mixed $t): mixed => is_string($t) && str_starts_with($t, '_:') ? $this->issuer->getId($t) : $t,
                $element[Keyword::Type->value],
            );
        }

        // Value object.
        if (array_key_exists(Keyword::Value->value, $element)) {
            if ($list === null) {
                if (is_string($activeSubject) && $activeProperty !== null) {
                    $this->addToNode($activeGraph, $activeSubject, $activeProperty, $element, false);
                }
            } else {
                $list[Keyword::List->value][] = $element;
            }

            return;
        }

        // List object. The expanded inner contents are gathered into a fresh
        // list object; that list object is then attached either to the
        // enclosing list (nested @list) or to the parent node's property.
        if (array_key_exists(Keyword::List->value, $element)) {
            $result = [Keyword::List->value => []];
            $inner = $element[Keyword::List->value];
            $this->generateNodeMap(is_array($inner) ? $inner : [$inner], $activeGraph, $activeSubject, $activeProperty, $result);
            if ($list !== null) {
                $list[Keyword::List->value][] = $result;
            } elseif (is_string($activeSubject) && $activeProperty !== null) {
                $this->addToNode($activeGraph, $activeSubject, $activeProperty, $result, true);
            }

            return;
        }

        // Node object.
        $id = $this->resolveNodeId($element);
        unset($element[Keyword::Id->value]);

        if (! isset($this->nodeMap[$activeGraph][$id])) {
            $this->nodeMap[$activeGraph][$id] = [Keyword::Id->value => $id];
        }

        // Link this node to its parent. Three cases:
        //  - reverse: active subject is a referenced-node map → link it into us.
        //  - inside an @list: append our reference to the list.
        //  - otherwise: append our reference to the parent's property.
        if (is_array($activeSubject) && $activeProperty !== null) {
            $this->addToNode($activeGraph, $id, $activeProperty, $activeSubject, false);
        } elseif ($activeProperty !== null) {
            $reference = [Keyword::Id->value => $id];
            if ($list !== null) {
                $list[Keyword::List->value][] = $reference;
            } elseif (is_string($activeSubject)) {
                $this->addToNode($activeGraph, $activeSubject, $activeProperty, $reference, false);
            }
        }

        if (isset($element[Keyword::Type->value])) {
            foreach ((array) $element[Keyword::Type->value] as $type) {
                $this->addToNode($activeGraph, $id, Keyword::Type->value, $type, false);
            }
            unset($element[Keyword::Type->value]);
        }

        if (array_key_exists(Keyword::Index->value, $element)) {
            $existing = $this->nodeMap[$activeGraph][$id][Keyword::Index->value] ?? null;
            if ($existing !== null && $existing != $element[Keyword::Index->value]) {
                throw new JsonLdException('conflicting indexes');
            }
            $this->nodeMap[$activeGraph][$id][Keyword::Index->value] = $element[Keyword::Index->value];
            unset($element[Keyword::Index->value]);
        }

        if (isset($element[Keyword::Reverse->value]) && is_array($element[Keyword::Reverse->value])) {
            $referencedNode = [Keyword::Id->value => $id];
            $noList = null;
            foreach ($element[Keyword::Reverse->value] as $reverseProperty => $values) {
                foreach ((is_array($values) ? $values : [$values]) as $value) {
                    $this->generateNodeMap($value, $activeGraph, $referencedNode, (string) $reverseProperty, $noList);
                }
            }
            unset($element[Keyword::Reverse->value]);
        }

        if (isset($element[Keyword::Graph->value])) {
            $noList = null;
            $graphValue = $element[Keyword::Graph->value];
            $this->generateNodeMap(is_array($graphValue) ? $graphValue : [$graphValue], $id, null, null, $noList);
            unset($element[Keyword::Graph->value]);
        }

        if (isset($element[Keyword::Included->value])) {
            $noList = null;
            $includedValue = $element[Keyword::Included->value];
            $this->generateNodeMap(is_array($includedValue) ? $includedValue : [$includedValue], $activeGraph, null, null, $noList);
            unset($element[Keyword::Included->value]);
        }

        // Remaining properties, iterated in lexical order so blank-node
        // identifiers are allocated deterministically.
        $properties = array_keys($element);
        sort($properties, SORT_STRING);
        foreach ($properties as $property) {
            $property = (string) $property;
            $value = $element[$property];

            if (str_starts_with($property, '_:')) {
                $property = $this->issuer->getId($property);
            }

            if (! isset($this->nodeMap[$activeGraph][$id][$property])) {
                $this->nodeMap[$activeGraph][$id][$property] = [];
            }

            $noList = null;
            $this->generateNodeMap($value, $activeGraph, $id, $property, $noList);
        }
    }

    /**
     * @param  array<array-key, mixed>  $element
     */
    private function resolveNodeId(array $element): string
    {
        if (! array_key_exists(Keyword::Id->value, $element)) {
            return $this->issuer->getId();
        }

        $id = $element[Keyword::Id->value];
        if (! is_string($id)) {
            return $this->issuer->getId();
        }

        return str_starts_with($id, '_:') ? $this->issuer->getId($id) : $id;
    }

    private function addToNode(string $graph, string $subject, string $property, mixed $value, bool $allowDuplicate): void
    {
        if (! isset($this->nodeMap[$graph][$subject])) {
            $this->nodeMap[$graph][$subject] = [Keyword::Id->value => $subject];
        }

        $node = &$this->nodeMap[$graph][$subject];
        if (! array_key_exists($property, $node) || ! is_array($node[$property])) {
            $node[$property] = [];
        }

        if (! $allowDuplicate) {
            foreach ($node[$property] as $existing) {
                if ($existing == $value) {
                    unset($node);

                    return;
                }
            }
        }

        $node[$property][] = $value;
        unset($node);
    }
}
