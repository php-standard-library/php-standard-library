<?php

declare(strict_types=1);

namespace Psl\Graph\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Graph;

final class ShortestPathByTest extends TestCase
{
    public function testShortestPathByWithFloatWeights(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B', 1.5);
        $graph = Graph\add_edge($graph, 'B', 'C', 2.3);
        $graph = Graph\add_edge($graph, 'A', 'C', 5.1);

        // Convert float to int by multiplying by 10
        $path = Graph\shortest_path_by($graph, 'A', 'C', static fn(float $w): int => (int) ($w * 10));

        static::assertSame(['A', 'B', 'C'], $path);
    }

    public function testShortestPathByWithFloatWeightsHighPrecision(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B', 1.001);
        $graph = Graph\add_edge($graph, 'B', 'C', 1.002);
        $graph = Graph\add_edge($graph, 'A', 'C', 2.5);

        // Convert float to int by multiplying by 1000 for higher precision
        $path = Graph\shortest_path_by($graph, 'A', 'C', static fn(float $w): int => (int) ($w * 1000));

        static::assertSame(['A', 'B', 'C'], $path);
    }

    public function testShortestPathByWithComplexGraph(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B', 4.5);
        $graph = Graph\add_edge($graph, 'A', 'C', 2.0);
        $graph = Graph\add_edge($graph, 'B', 'C', 1.5);
        $graph = Graph\add_edge($graph, 'B', 'D', 3.0);
        $graph = Graph\add_edge($graph, 'C', 'D', 5.5);

        $path = Graph\shortest_path_by($graph, 'A', 'D', static fn(float $w): int => (int) ($w * 10));

        // Both A->B->D (7.5) and A->C->D (7.5) have the same cost, either is valid
        static::assertContains($path, [['A', 'B', 'D'], ['A', 'C', 'D']]);
    }

    public function testShortestPathByNoPath(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B', 1.5);
        $graph = Graph\add_edge($graph, 'C', 'D', 2.0);

        $path = Graph\shortest_path_by($graph, 'A', 'D', static fn(float $w): int => (int) ($w * 10));

        static::assertNull($path);
    }

    public function testShortestPathByNonExistentNodes(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B', 1.5);

        $path = Graph\shortest_path_by($graph, 'A', 'Z', static fn(float $w): int => (int) ($w * 10));

        static::assertNull($path);
    }

    public function testShortestPathBySameNode(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B', 1.5);

        $path = Graph\shortest_path_by($graph, 'A', 'A', static fn(float $w): int => (int) ($w * 10));

        static::assertSame(['A'], $path);
    }

    public function testShortestPathByUnweightedGraph(): void
    {
        // Unweighted graph (all weights are null) should use BFS
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B');
        $graph = Graph\add_edge($graph, 'B', 'C');
        $graph = Graph\add_edge($graph, 'A', 'C');

        $path = Graph\shortest_path_by($graph, 'A', 'C', static fn(null|float $w): int => $w === null
            ? 1
            : (int) ($w * 10));

        static::assertSame(['A', 'C'], $path);
    }

    public function testShortestPathByUndirectedGraph(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_edge($graph, 'A', 'B', 1.5);
        $graph = Graph\add_edge($graph, 'B', 'C', 2.0);
        $graph = Graph\add_edge($graph, 'A', 'C', 5.0);

        $path = Graph\shortest_path_by($graph, 'A', 'C', static fn(float $w): int => (int) ($w * 10));

        static::assertSame(['A', 'B', 'C'], $path);
    }

    public function testShortestPathByWithStringWeights(): void
    {
        // Demonstrate that any weight type can be used
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', 'B', 'low');
        $graph = Graph\add_edge($graph, 'B', 'C', 'medium');
        $graph = Graph\add_edge($graph, 'A', 'C', 'high');

        $weightMap = ['low' => 1, 'medium' => 2, 'high' => 5];
        $path = Graph\shortest_path_by($graph, 'A', 'C', static fn(string $w): int => $weightMap[$w]);

        static::assertSame(['A', 'B', 'C'], $path);
    }

    public function testUnweightedPathThroughNullNode(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', null);
        $graph = Graph\add_edge($graph, null, 'C');

        static::assertSame(['A', null, 'C'], Graph\shortest_path_by($graph, 'A', 'C', static fn(int $w): int => $w));
        static::assertSame([null, 'C'], Graph\shortest_path_by($graph, null, 'C', static fn(int $w): int => $w));
        static::assertSame(['A', null], Graph\shortest_path_by($graph, 'A', null, static fn(int $w): int => $w));
    }

    public function testWeightedPathThroughNullNode(): void
    {
        $graph = Graph\directed();
        $graph = Graph\add_edge($graph, 'A', null, 1);
        $graph = Graph\add_edge($graph, null, 'C', 1);
        $graph = Graph\add_edge($graph, 'A', 'C', 5);

        static::assertSame(['A', null, 'C'], Graph\shortest_path_by($graph, 'A', 'C', static fn(int $w): int => $w));
        static::assertSame([null, 'C'], Graph\shortest_path_by($graph, null, 'C', static fn(int $w): int => $w));
        static::assertSame(['A', null], Graph\shortest_path_by($graph, 'A', null, static fn(int $w): int => $w));
    }

    public function testShortestPathThroughNullNode(): void
    {
        $graph = Graph\undirected();
        $graph = Graph\add_edge($graph, 1, null, 2);
        $graph = Graph\add_edge($graph, null, 3, 2);
        $graph = Graph\add_edge($graph, 1, 3, 10);

        static::assertSame([1, null, 3], Graph\shortest_path($graph, 1, 3));
        static::assertSame([3, null, 1], Graph\shortest_path($graph, 3, 1));
    }
}
