# Graph

The `Graph` component provides immutable graph data structures and algorithms for working with directed and undirected graphs.

Graphs are fundamental data structures for modeling relationships and networks. This component supports directed and undirected graphs, weighted and unweighted edges, traversal (BFS, DFS), shortest path finding, topological sorting, and cycle detection. All operations are immutable and return new graph instances.

## Design

The Graph component uses an adjacency list representation:
- **`DirectedGraph<TNode, TWeight>`**: Directed graph with edges from node A to node B
- **`UndirectedGraph<TNode, TWeight>`**: Undirected graph with bidirectional edges
- **`Edge<TNode, TWeight>`**: Represents an edge with optional weight
- **Flexible node types**: Nodes can be any type (objects, arrays, scalars, resources, etc.)

## Usage

### Building Graphs

```php
use Psl\Graph;

// Directed graph
$graph = Graph\directed();
$graph = Graph\add_node($graph, 'A');
$graph = Graph\add_edge($graph, 'A', 'B');
$graph = Graph\add_edge($graph, 'B', 'C');

// Undirected graph (edges go in both directions)
$graph = Graph\undirected();
$graph = Graph\add_edge($graph, 'A', 'B'); // adds edges in both directions

// Weighted edges
$graph = Graph\directed();
$graph = Graph\add_edge($graph, 'A', 'B', 5);
$graph = Graph\add_edge($graph, 'A', 'C', 10);
$graph = Graph\add_edge($graph, 'B', 'C', 2);
```

### Traversal

```php
use Psl\Graph;

$graph = Graph\directed();
$graph = Graph\add_edge($graph, 'A', 'B');
$graph = Graph\add_edge($graph, 'A', 'C');
$graph = Graph\add_edge($graph, 'B', 'D');

Graph\bfs($graph, 'A'); // ['A', 'B', 'C', 'D'] -- breadth-first
Graph\dfs($graph, 'A'); // ['A', 'B', 'D', 'C'] -- depth-first
```

### Shortest Path

For unweighted graphs, BFS is used. For weighted graphs, Dijkstra's algorithm is used.

```php
use Psl\Graph;

/** @var Graph\DirectedGraph<string, int> $graph */
$graph = Graph\directed();
$graph = Graph\add_edge($graph, 'NYC', 'Boston', 215);
$graph = Graph\add_edge($graph, 'NYC', 'Philadelphia', 95);
$graph = Graph\add_edge($graph, 'Philadelphia', 'Boston', 310);

$path = Graph\shortest_path($graph, 'NYC', 'Boston');
// ['NYC', 'Boston'] (cost: 215, shorter than via Philadelphia)

/**
 * For non-integer weights, use shortest_path_by with a converter
 *
 * @var Graph\DirectedGraph<string, float> $graph
 */
$graph = Graph\directed();
$graph = Graph\add_edge($graph, 'A', 'B', 1.5);
$graph = Graph\add_edge($graph, 'B', 'C', 2.3);
$path = Graph\shortest_path_by($graph, 'A', 'C', fn(float $w): int => (int) ($w * 1000));
```

### Cycle Detection and Topological Sort

```php
use Psl\Graph;

// Topological sort for DAGs (dependency ordering)
$graph = Graph\directed();
$graph = Graph\add_edge($graph, 'libc', 'gcc');
$graph = Graph\add_edge($graph, 'gcc', 'app');
$graph = Graph\add_edge($graph, 'libc', 'app');

$buildOrder = Graph\topological_sort($graph);
// ['libc', 'gcc', 'app']

// Cycle detection
$graph = Graph\directed();
$graph = Graph\add_edge($graph, 'A', 'B');
$graph = Graph\add_edge($graph, 'B', 'C');
$graph = Graph\add_edge($graph, 'C', 'A');

Graph\has_cycle($graph); // true
```

### Object Nodes

Nodes can be any type, including objects:

```php
use Psl\Graph;

class Task
{
    public function __construct(
        public readonly string $name,
        public readonly int $duration,
    ) {}
}

$graph = Graph\directed();
$compile = new Task('compile', 5);
$test = new Task('test', 3);
$deploy = new Task('deploy', 2);

$graph = Graph\add_edge($graph, $compile, $test);
$graph = Graph\add_edge($graph, $test, $deploy);

$executionOrder = Graph\topological_sort($graph);

// [$compile, $test, $deploy]
```

## Use Cases

- **Dependency Resolution**: Topological sort for build systems, package managers
- **Route Finding**: Shortest path for navigation, network routing
- **Social Networks**: Friend relationships, recommendations
- **State Machines**: Transitions between states
- **Data Flow**: Pipeline dependencies, task scheduling

See [src/Psl/Graph/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/graph/src/Psl/Graph/) for the full API.
