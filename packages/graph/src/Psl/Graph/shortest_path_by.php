<?php

declare(strict_types=1);

namespace Psl\Graph;

use Closure;
use Psl\DataStructure\PriorityQueue;
use Psl\DataStructure\Queue;

use const PHP_INT_MAX;

/**
 * Finds the shortest path between two nodes with custom weight conversion.
 *
 * For unweighted graphs (all edges have null weight), uses BFS.
 * For weighted graphs, uses Dijkstra's algorithm with a user-provided weight converter.
 *
 * The weight converter function takes the edge weight and returns an integer priority
 * for the priority queue. This allows using any weight type (e.g., float, custom objects)
 * while ensuring correct priority queue ordering.
 *
 * Returns the path as a list of nodes, or null if no path exists.
 *
 * Example:
 *
 *      $graph = Graph\directed();
 *      $graph = Graph\add_edge($graph, 'A', 'B', 1.5);
 *      $graph = Graph\add_edge($graph, 'B', 'C', 2.3);
 *      $graph = Graph\add_edge($graph, 'A', 'C', 5.1);
 *      // Convert float weights to int by multiplying by 1000
 *      Graph\shortest_path_by($graph, 'A', 'C', fn($w) => (int)($w * 1000)) // ['A', 'B', 'C']
 *
 * @template TNode
 * @template TWeight
 *
 * @param DirectedGraph<TNode, TWeight>|UndirectedGraph<TNode, TWeight> $graph
 * @param TNode $from
 * @param TNode $to
 * @param (Closure(TWeight): int) $weightConverter Function to convert edge weight to int priority
 *
 * @return list<TNode>|null
 *
 * @pure
 *
 * @api
 */
function shortest_path_by(
    DirectedGraph|UndirectedGraph $graph,
    mixed $from,
    mixed $to,
    Closure $weightConverter,
): null|array {
    if (!$graph->hasNode($from) || !$graph->hasNode($to)) {
        return null;
    }

    if ($from === $to) {
        return [$from];
    }

    // Check if graph is weighted
    $isWeighted = false;
    foreach (namespace\nodes($graph) as $node) {
        foreach ($graph->getEdgesFrom($node) as $edge) {
            if (null === $edge->weight) {
                continue;
            }

            $isWeighted = true;
            break 2;
        }
    }

    if (!$isWeighted) {
        // BFS for unweighted graphs
        $parent = [];
        $visited = [];
        $queue = new Queue();
        $queue->enqueue($from);
        $fromKey = Internal\get_node_key($from);
        $visited[$fromKey] = true;

        while ($queue->count() !== 0) {
            $node = $queue->dequeue();

            if ($node === $to) {
                return Internal\build_path($parent, $to);
            }

            foreach (namespace\neighbors($graph, $node) as $neighbor) {
                $neighborKey = Internal\get_node_key($neighbor);
                if (!isset($visited[$neighborKey])) {
                    $visited[$neighborKey] = true;
                    $parent[$neighborKey] = $node;
                    $queue->enqueue($neighbor);
                }
            }
        }

        return null;
    }

    // Dijkstra's algorithm for weighted graphs
    $distances = [];
    $parent = [];
    $visited = [];

    /** @var PriorityQueue<array{0: int, 1: TNode}> $pq */
    $pq = new PriorityQueue();

    foreach (namespace\nodes($graph) as $node) {
        $distances[Internal\get_node_key($node)] = PHP_INT_MAX;
    }

    $fromKey = Internal\get_node_key($from);
    $distances[$fromKey] = 0;
    $pq->enqueue([0, $from], 0);

    while ($pq->count() !== 0) {
        [, $node] = $pq->dequeue();
        $nodeKey = Internal\get_node_key($node);

        if (isset($visited[$nodeKey])) {
            continue;
        }

        $visited[$nodeKey] = true;

        if ($node === $to) {
            return Internal\build_path($parent, $to);
        }

        foreach ($graph->getEdgesFrom($node) as $edge) {
            $neighborKey = Internal\get_node_key($edge->to);
            if (isset($visited[$neighborKey])) {
                continue;
            }

            $weight = $weightConverter($edge->weight ?? 1);
            $newDist = $distances[$nodeKey] + $weight;

            if ($newDist < $distances[$neighborKey]) {
                $distances[$neighborKey] = $newDist;
                $parent[$neighborKey] = $node;
                $pq->enqueue([$newDist, $edge->to], -$newDist);
            }
        }
    }

    return null;
}
