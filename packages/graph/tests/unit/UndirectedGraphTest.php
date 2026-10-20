<?php

declare(strict_types=1);

namespace Psl\Graph\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Graph;

final class UndirectedGraphTest extends TestCase
{
    public function testEmptyUndirectedGraph(): void
    {
        $graph = new Graph\UndirectedGraph([], []);

        static::assertSame([], $graph->getNodes());
        static::assertSame([], $graph->getEdgesFrom('any-node'));
    }

    public function testGetNodes(): void
    {
        $graph = new Graph\UndirectedGraph(['s:A' => 'A', 's:B' => 'B', 's:C' => 'C'], [
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
        $graph = new Graph\UndirectedGraph(['s:A' => 'A'], ['s:A' => $edges]);

        static::assertSame($edges, $graph->getEdgesFrom('A'));
    }

    public function testGetEdgesFromNonExistentNode(): void
    {
        $graph = new Graph\UndirectedGraph(['s:A' => 'A'], ['s:A' => []]);

        static::assertSame([], $graph->getEdgesFrom('B'));
    }

    public function testHasNode(): void
    {
        $graph = new Graph\UndirectedGraph(['s:A' => 'A', 's:B' => 'B'], ['s:A' => [], 's:B' => []]);

        static::assertTrue($graph->hasNode('A'));
        static::assertTrue($graph->hasNode('B'));
        static::assertFalse($graph->hasNode('C'));
    }

    public function testHasEdge(): void
    {
        $graph = new Graph\UndirectedGraph(['s:A' => 'A', 's:B' => 'B', 's:C' => 'C'], [
            's:A' => [new Graph\Edge('B'), new Graph\Edge('C')],
            's:B' => [new Graph\Edge('A')],
            's:C' => [new Graph\Edge('A')],
        ]);

        static::assertTrue($graph->hasEdge('A', 'B'));
        static::assertTrue($graph->hasEdge('A', 'C'));
        static::assertTrue($graph->hasEdge('B', 'A'));
        static::assertFalse($graph->hasEdge('B', 'C'));
    }

    public function testHasEdgeFromNonExistentNode(): void
    {
        $graph = new Graph\UndirectedGraph(['s:A' => 'A'], ['s:A' => []]);

        static::assertFalse($graph->hasEdge('B', 'A'));
    }

    public function testHasCycleOnEmptyGraph(): void
    {
        $graph = new Graph\UndirectedGraph([], []);

        static::assertFalse($graph->hasCycle());
    }

    public function testHasCycleOnSingleNodeGraph(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_node($graph, 'A');

        static::assertFalse($graph->hasCycle());
    }

    public function testHasCycleOnGraphWithoutCycle(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'B', 'C');
        $graph = Graph\add_edge($graph, 'C', 'D');

        static::assertFalse($graph->hasCycle());
    }

    public function testHasCycleOnGraphWithSimpleCycle(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'B', 'C');
        $graph = Graph\add_edge($graph, 'C', 'A');

        static::assertTrue($graph->hasCycle());
    }

    public function testHasCycleOnGraphWithSelfLoop(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_edge($graph, 'A', 'A');

        static::assertTrue($graph->hasCycle());
    }

    public function testHasCycleOnDisconnectedGraphWithCycleInFirstComponent(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'B', 'C');
        $graph = Graph\add_edge($graph, 'C', 'A');
        $graph = Graph\add_edge($graph, 'D', 'E');

        static::assertTrue($graph->hasCycle());
    }

    public function testHasCycleOnDisconnectedGraphWithCycleInSecondComponent(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'C', 'D');
        $graph = Graph\add_edge($graph, 'D', 'E');
        $graph = Graph\add_edge($graph, 'E', 'C');

        static::assertTrue($graph->hasCycle());
    }

    public function testHasCycleOnDisconnectedGraphWithoutCycle(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'C', 'D');
        $graph = Graph\add_edge($graph, 'E', 'F');

        static::assertFalse($graph->hasCycle());
    }

    public function testHasCycleOnTreeStructure(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'A', 'C');
        $graph = Graph\add_edge($graph, 'B', 'D');
        $graph = Graph\add_edge($graph, 'B', 'E');
        $graph = Graph\add_edge($graph, 'C', 'F');

        static::assertFalse($graph->hasCycle());
    }

    public function testHasCycleOnComplexGraphWithCycle(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'B', 'C');
        $graph = Graph\add_edge($graph, 'C', 'D');
        $graph = Graph\add_edge($graph, 'D', 'E');
        $graph = Graph\add_edge($graph, 'E', 'B');

        static::assertTrue($graph->hasCycle());
    }

    public function testAddDuplicateNodeReturnsSameGraph(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_node($graph, 'A');
        $graph2 = Graph\add_node($graph, 'A');

        static::assertSame($graph, $graph2);
    }

    public function testHasCycleDetectsTriangleCycleRequiringVisitedFlag(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'B', 'C');
        $graph = Graph\add_edge($graph, 'C', 'A');

        static::assertTrue($graph->hasCycle());

        $linear = Graph\undirected();
        $linear = Graph\add_edge($linear, 'X', 'Y');
        $linear = Graph\add_edge($linear, 'Y', 'Z');

        static::assertFalse($linear->hasCycle());
    }

    public function testNullNode(): void
    {
        $graph = Graph\undirected();
        static::assertFalse($graph->hasNode(null));

        $graph = Graph\add_node($graph, null);
        static::assertTrue($graph->hasNode(null));
        static::assertSame([null], $graph->getNodes());
    }

    public function testAddingANullNodeAgainKeepsItsEdges(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_edge($graph, null, 'A');
        $graph = Graph\add_edge($graph, null, 'B');
        $graph = Graph\add_node($graph, null);

        static::assertTrue($graph->hasEdge(null, 'A'));
        static::assertTrue($graph->hasEdge(null, 'B'));
        static::assertSame(['A', 'B'], Graph\neighbors($graph, null));
        static::assertSame([null, 'A', 'B'], Graph\bfs($graph, null));
    }
}
