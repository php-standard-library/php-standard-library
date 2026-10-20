<?php

declare(strict_types=1);

namespace Psl\Graph;

use function array_key_exists;
use function array_values;

/**
 * Immutable directed graph using adjacency list representation.
 *
 * Supports any node type (scalars, objects, arrays, resources, etc.).
 *
 * @template TNode
 * @template TWeight
 *
 * @implements GraphInterface<TNode, TWeight>
 *
 * @api
 */
final readonly class DirectedGraph implements GraphInterface
{
    /**
     * @param array<non-empty-string, TNode> $nodes Map from node key to node
     * @param array<non-empty-string, list<Edge<TNode, TWeight>>> $edges Map from node key to edges
     *
     * @internal Use Graph\directed() to create instances
     */
    public function __construct(
        private array $nodes = [],
        private array $edges = [],
    ) {}

    /**
     * Returns all nodes in the graph.
     *
     * @return list<TNode>
     *
     * @pure
     */
    public function getNodes(): array
    {
        return array_values($this->nodes);
    }

    /**
     * Returns all edges from a given node.
     *
     * @param TNode $from
     *
     * @return list<Edge<TNode, TWeight>>
     *
     * @pure
     */
    public function getEdgesFrom(mixed $from): array
    {
        $key = Internal\get_node_key($from);
        return $this->edges[$key] ?? [];
    }

    /**
     * Checks if a node exists in the graph.
     *
     * @param TNode $node
     *
     * @pure
     */
    public function hasNode(mixed $node): bool
    {
        $key = Internal\get_node_key($node);
        // `isset()` would report a `null` node as missing.
        return array_key_exists($key, $this->nodes);
    }

    /**
     * Checks if an edge exists from one node to another.
     *
     * @param TNode $from
     * @param TNode $to
     *
     * @pure
     */
    public function hasEdge(mixed $from, mixed $to): bool
    {
        $key = Internal\get_node_key($from);
        if (!isset($this->edges[$key])) {
            return false;
        }

        foreach ($this->edges[$key] as $edge) {
            if ($edge->to !== $to) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Returns a new graph with the node added.
     *
     * @param TNode $node
     *
     * @return DirectedGraph<TNode, TWeight>
     *
     * @internal
     *
     * @pure
     */
    public function withNode(mixed $node): DirectedGraph
    {
        if ($this->hasNode($node)) {
            return $this;
        }

        $key = Internal\get_node_key($node);
        $nodes = $this->nodes;
        $edges = $this->edges;
        $nodes[$key] = $node;
        $edges[$key] = [];

        return new DirectedGraph($nodes, $edges);
    }

    /**
     * Returns a new graph with the edge added.
     *
     * @param Edge<TNode, TWeight> $edge
     * @param TNode $from
     *
     * @return DirectedGraph<TNode, TWeight>
     *
     * @internal
     *
     * @pure
     */
    public function withEdge(mixed $from, Edge $edge): DirectedGraph
    {
        $key = Internal\get_node_key($from);
        $edges = $this->edges;
        $edges[$key] ??= [];
        $edges[$key][] = $edge;

        return new DirectedGraph($this->nodes, $edges);
    }

    /**
     * Checks if the graph contains a cycle.
     *
     * Uses DFS with recursion stack to detect back edges.
     * A cycle exists if we encounter a node that's in the current recursion stack.
     *
     * @pure
     */
    public function hasCycle(): bool
    {
        $visited = [];
        $recursionStack = [];

        $dfsCheck =
            /**
             * @param TNode $node
             */
            function (mixed $node) use (&$visited, &$recursionStack, &$dfsCheck): bool {
                $key = Internal\get_node_key($node);
                $visited[$key] = true;
                $recursionStack[$key] = true;

                foreach ($this->getEdgesFrom($node) as $edge) {
                    $neighborKey = Internal\get_node_key($edge->to);

                    if (!isset($visited[$neighborKey])) {
                        if ($dfsCheck($edge->to)) {
                            return true;
                        }

                        continue;
                    }

                    if (isset($recursionStack[$neighborKey])) {
                        return true;
                    }
                }

                unset($recursionStack[$key]);

                return false;
            };

        foreach ($this->getNodes() as $node) {
            $key = Internal\get_node_key($node);
            if (!isset($visited[$key])) {
                if ($dfsCheck($node)) {
                    return true;
                }
            }
        }

        return false;
    }
}
