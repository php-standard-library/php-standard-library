# Tree

The `Tree` component provides immutable tree data structures and operations for hierarchical data manipulation.

Trees are fundamental data structures for representing hierarchical relationships. This component provides immutable tree nodes (`LeafNode` and `TreeNode`), pure constructor functions, functional operations (map, filter, reduce, fold), tree traversal algorithms, and search utilities.

## Design

The Tree component has a simple design:
- **`NodeInterface<T>`**: Base interface with `getValue(): T`
- **`LeafNode<T>`**: Leaf nodes (no children)
- **`TreeNode<T>`**: Tree nodes with `getChildren(): list<NodeInterface<T>>`

All tree operations are immutable -- they return new trees rather than modifying existing ones.

## Usage

### Building Trees

Use the `tree()` and `leaf()` constructor functions to build trees:

```php
use Psl\Tree;

$tree = Tree\tree('root', [
    Tree\leaf('child1'),
    Tree\leaf('child2'),
    Tree\tree('child3', [
        Tree\leaf('grandchild1'),
    ]),
]);
```

You can also build trees from nested arrays or flat lists with parent references, which is especially useful for database records:

```php
use Psl\Tree;

// From a nested array
$tree = Tree\from_array([
    'value' => 'root',
    'children' => [
        ['value' => 'child1', 'children' => []],
        ['value' => 'child2', 'children' => []],
    ],
]);

// From database records with parent_id relationships
$records = [
    ['id' => 1, 'name' => 'Root', 'parent_id' => null],
    ['id' => 2, 'name' => 'Child A', 'parent_id' => 1],
    ['id' => 3, 'name' => 'Child B', 'parent_id' => 1],
    ['id' => 4, 'name' => 'Grandchild', 'parent_id' => 2],
];

$tree = Tree\from_list(
    $records,
    fn($record) => $record['id'],
    fn($record) => $record['parent_id'],
    fn($record) => $record['name'],
);
```

### Transforming Trees

```php
use Psl\Math;
use Psl\Tree;

$tree = Tree\tree(1, [Tree\leaf(2), Tree\leaf(3)]);

// Map: apply a function to every node value
$doubled = Tree\map($tree, fn(int $x): int => $x * 2);
// Result: tree(2, [leaf(4), leaf(6)])

// Filter: keep only nodes matching a predicate
$filtered = Tree\filter($tree, fn(int $x): bool => $x >= 2);
// Result: tree(2, [leaf(3)]) -- root must match or null is returned

// Reduce: collapse the tree to a single value (pre-order)
$sum = Tree\reduce($tree, fn(int $acc, int $x): int => $acc + $x, 0);
// Result: 6

// Fold: post-order fold with access to children results
$result = Tree\fold(
    $tree,
    /**
     * @param int $value
     * @param list<int> $children
     * @return int
     */
    fn(int $value, array $children): int => $value + Math\sum($children),
);

// Result: 6
```

### Traversal

Three traversal orders are available:

```php
use Psl\Tree;

$tree = Tree\tree(1, [Tree\tree(2, [Tree\leaf(3)]), Tree\leaf(4)]);

Tree\pre_order($tree); // [1, 2, 3, 4] -- root first, then children
Tree\post_order($tree); // [3, 2, 4, 1] -- children first, then root
Tree\level_order($tree); // [1, 2, 4, 3] -- breadth-first
```

### Searching and Paths

```php
use Psl\Str;
use Psl\Tree;

$tree = Tree\tree('a', [
    Tree\tree('b', [Tree\leaf('c')]),
    Tree\leaf('d'),
]);

// Find the path from root to a matching node
$path = Tree\path_to($tree, fn($x) => $x === 'c');
// Result: ['a', 'b', 'c']

// Navigate by index path
Tree\at_index($tree, [0, 0]); // 'c'
Tree\at_index($tree, [1]); // 'd'

// Search for values
Tree\find($tree, fn($x) => $x === 'c'); // 'c'
Tree\contains($tree, 'd'); // true
Tree\any($tree, fn($x) => $x === 'b'); // true
Tree\all($tree, fn($x) => Str\length($x) === 1); // true
```

### Custom Serialization

The `traverse()` function gives you full control over how the tree is serialized:

```php
use Psl\Iter;
use Psl\Str;
use Psl\Tree;

$tree = Tree\tree('root', [Tree\leaf('a'), Tree\leaf('b')]);

// Build a custom string representation
$result = Tree\traverse(
    $tree,
    /**
     * @param non-empty-string $value
     * @param Closure(): list<non-empty-string> $traverse
     *
     * @return non-empty-string
     */
    function (string $value, Closure $traverse): string {
        $children = $traverse();
        return Iter\is_empty($children) ? $value : $value . '(' . Str\join($children, ',') . ')';
    },
);

// Result: 'root(a,b)'
```

See [src/Psl/Tree/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/tree/src/Psl/Tree/) for the full API.
