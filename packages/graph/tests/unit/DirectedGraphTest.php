<?php

declare(strict_types=1);

namespace Psl\Graph\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Graph;

final class DirectedGraphTest extends TestCase
{
    public function testEmptyDirectedGraph(): void
    {
        $graph = new Graph\DirectedGraph([], []);

        static::assertSame([], $graph->getNodes());
        static::assertSame([], $graph->getEdgesFrom('any-node'));
    }

    public function testGetNodes(): void
    {
        $graph = new Graph\DirectedGraph(['s:A' => 'A', 's:B' => 'B', 's:C' => 'C'], [
            's:A' => [],
            's:B' => [],
            's:C' => [],
        ]);

        static::assertSame(['A', 'B', 'C'], $graph->getNodes());
    }

    public function testGetEdgesFrom(): void
    {
        $edges = [
            new Graph\Edge('B'),
            new Graph\Edge('C', 5),
        ];
        $graph = new Graph\DirectedGraph(['s:A' => 'A'], ['s:A' => $edges]);

        static::assertSame($edges, $graph->getEdgesFrom('A'));
    }

    public function testGetEdgesFromNonExistentNode(): void
    {
        $graph = new Graph\DirectedGraph(['s:A' => 'A'], ['s:A' => []]);

        static::assertSame([], $graph->getEdgesFrom('B'));
    }

    public function testHasNode(): void
    {
        $graph = new Graph\DirectedGraph(['s:A' => 'A', 's:B' => 'B'], ['s:A' => [], 's:B' => []]);

        static::assertTrue($graph->hasNode('A'));
        static::assertTrue($graph->hasNode('B'));
        static::assertFalse($graph->hasNode('C'));
    }

    public function testHasEdge(): void
    {
        $graph = new Graph\DirectedGraph(['s:A' => 'A', 's:B' => 'B', 's:C' => 'C'], [
            's:A' => [new Graph\Edge('B'), new Graph\Edge('C')],
            's:B' => [],
            's:C' => [],
        ]);

        static::assertTrue($graph->hasEdge('A', 'B'));
        static::assertTrue($graph->hasEdge('A', 'C'));
        static::assertFalse($graph->hasEdge('B', 'A'));
        static::assertFalse($graph->hasEdge('B', 'C'));
    }

    public function testHasEdgeFromNonExistentNode(): void
    {
        $graph = new Graph\DirectedGraph(['s:A' => 'A'], ['s:A' => []]);

        static::assertFalse($graph->hasEdge('B', 'A'));
    }

    public function testHasCycleOnEmptyGraph(): void
    {
        $graph = new Graph\DirectedGraph([], []);

        static::assertFalse($graph->hasCycle());
    }

    public function testHasCycleOnGraphWithoutCycle(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'B', 'C');
        $graph = Graph\add_edge($graph, 'A', 'C');

        static::assertFalse($graph->hasCycle());
    }

    public function testHasCycleOnGraphWithSimpleCycle(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'B', 'C');
        $graph = Graph\add_edge($graph, 'C', 'A');

        static::assertTrue($graph->hasCycle());
    }

    public function testHasCycleOnGraphWithSelfLoop(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'A');

        static::assertTrue($graph->hasCycle());
    }

    public function testHasCycleOnDisconnectedGraphWithCycleInFirstComponent(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'B', 'A');
        $graph = Graph\add_edge($graph, 'C', 'D');

        static::assertTrue($graph->hasCycle());
    }

    public function testHasCycleOnDisconnectedGraphWithCycleInSecondComponent(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'C', 'D');
        $graph = Graph\add_edge($graph, 'D', 'C');

        static::assertTrue($graph->hasCycle());
    }

    public function testHasCycleOnComplexGraphWithMultiplePathsNoCycle(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'A', 'C');
        $graph = Graph\add_edge($graph, 'B', 'D');
        $graph = Graph\add_edge($graph, 'C', 'D');
        $graph = Graph\add_edge($graph, 'D', 'E');

        static::assertFalse($graph->hasCycle());
    }

    public function testHasCycleOnGraphWithNestedCycle(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'B', 'C');
        $graph = Graph\add_edge($graph, 'C', 'D');
        $graph = Graph\add_edge($graph, 'D', 'B');
        $graph = Graph\add_edge($graph, 'C', 'E');

        static::assertTrue($graph->hasCycle());
    }

    public function testHasCycleDetectsDirectCycleCorrectly(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'B', 'C');
        $graph = Graph\add_edge($graph, 'C', 'A');

        static::assertTrue($graph->hasCycle());

        $acyclic = Graph\directed();
        $acyclic = Graph\add_edge($acyclic, 'X', 'Y');
        $acyclic = Graph\add_edge($acyclic, 'Y', 'Z');
        static::assertFalse($acyclic->hasCycle());
    }

    public function testHasCycleWithNodeHavingMultipleEdgesFirstNeighborVisited(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'A', 'C');
        $graph = Graph\add_edge($graph, 'B', 'D');
        $graph = Graph\add_edge($graph, 'C', 'D');

        static::assertFalse($graph->hasCycle());
    }

    public function testHasCycleWithMultipleEdgesContinueVsBreak(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'B', 'C');
        $graph = Graph\add_edge($graph, 'B', 'D');
        $graph = Graph\add_edge($graph, 'C', 'D');
        $graph = Graph\add_edge($graph, 'C', 'A');

        static::assertTrue($graph->hasCycle());
    }

    public function testNullNode(): void
    {
        $graph = Graph\directed();
        static::assertFalse($graph->hasNode(null));

        $graph = Graph\add_node($graph, null);
        static::assertTrue($graph->hasNode(null));
        static::assertSame([null], $graph->getNodes());
    }

    public function testAddingANullNodeAgainKeepsItsEdges(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, null, 'A');
        $graph = Graph\add_edge($graph, null, 'B');
        $graph = Graph\add_node($graph, null);

        static::assertTrue($graph->hasEdge(null, 'A'));
        static::assertTrue($graph->hasEdge(null, 'B'));
        static::assertSame(['A', 'B'], Graph\neighbors($graph, null));
        static::assertSame([null, 'A', 'B'], Graph\bfs($graph, null));
    }
}
