# Collection

The `Collection` component provides generic, object-oriented collection types as an alternative to PHP arrays. Collections are strongly typed, support generics via doc-block annotations, and offer a rich API for filtering, mapping, and slicing.

Each collection type comes in two variants: an immutable (`readonly`) version and a mutable version.

| Type | Immutable | Mutable |
|------|-----------|---------|
| Ordered list | `Vector<T>` | `MutableVector<T>` |
| Key-value map | `Map<Tk, Tv>` | `MutableMap<Tk, Tv>` |
| Unique values | `Set<T>` | `MutableSet<T>` |

## Vector

A `Vector` is an ordered, integer-indexed sequence of values -- similar to a PHP `list<T>`.

```php
use Psl\Collection\MutableVector;
use Psl\Collection\Vector;
use Psl\Str;

// Immutable vector
$names = Vector::fromArray(['Alice', 'Bob', 'Charlie']);
$names->count(); // 3
$names->at(0); // 'Alice'
$names->first(); // 'Alice'
$names->last(); // 'Charlie'
$names->toArray(); // ['Alice', 'Bob', 'Charlie']

// Filtering and mapping return new immutable vectors
$short = $names->filter(fn(string $n): bool => Str\length($n) <= 3);
$upper = $names->map(Str\uppercase(...));

/**
 * Mutable vector -- supports in-place modification
 *
 * @var MutableVector<string> $tasks
 */
$tasks = MutableVector::fromArray(['eat', 'sleep']);
$tasks->add('code');
$tasks->remove(0); // removes 'eat', re-indexes
$tasks->toArray(); // ['sleep', 'code']
```

## Map

A `Map` is an ordered collection of key-value pairs. Keys must be `int` or `string`.

```php
use Psl\Collection\Map;
use Psl\Collection\MutableMap;

/**
 * Immutable map
 *
 * @var Map<string, int> $scores
 */
$scores = Map::fromArray(['alice' => 95, 'bob' => 82, 'charlie' => 91]);
$scores->at('alice'); // 95
$scores->get('unknown'); // null (no exception)
$scores->contains('bob'); // true
$scores->keys(); // Vector<string>
$scores->values(); // Vector<int>

// Filter to passing scores
$passing = $scores->filter(fn(int $s): bool => $s >= 90);
$passing->toArray(); // ['alice' => 95, 'charlie' => 91]

/**
 * Mutable map
 *
 * @var MutableMap<string, string> $config
 */
$config = MutableMap::fromArray(['debug' => 'false']);
$config->add('version', '2.0'); // adds or overwrites
$config->set('debug', 'true'); // overwrites existing key (throws if missing)
$config->remove('debug');
```

## Set

A `Set` stores unique values with no duplicate entries. Values must be `int` or `string` (array-key types).

```php
use Psl\Collection\MutableSet;
use Psl\Collection\Set;

/**
 * A set is a collection of unique values. It can be either mutable or immutable.
 *
 * @var Set<string> $tags
 */
$tags = Set::fromArray(['php', 'psl', 'php']); // duplicates removed
$tags->count(); // 2
$tags->contains('php'); // true
$tags->contains('rust'); // false

/**
 * Mutable set -- supports in-place modification
 *
 * @var MutableSet<string> $visited
 */
$visited = MutableSet::fromArray([]);
$visited->add('page-a');
$visited->add('page-b');
$visited->add('page-a'); // no-op, already present
$visited->count(); // 2
$visited->remove('page-a');
```

## Common Operations

All collection types share a consistent API for reading, transforming, and slicing:

```php
use Psl\Collection\Vector;

$v = Vector::fromArray([10, 20, 30]);
$v->at(1); // 20 (throws OutOfBoundsException if missing)
$v->get(1); // 20 (returns null if missing)
$v->linearSearch(20); // 1 (index of the value)

// Slicing operations return new collections
$v = Vector::fromArray([1, 2, 3, 4, 5]);
$v->take(3)->toArray(); // [1, 2, 3]
$v->drop(2)->toArray(); // [3, 4, 5]
$v->slice(1, 3)->toArray(); // [2, 3, 4]
$v->chunk(2); // Vector<Vector<int>> of [[1,2], [3,4], [5]]
```

All collections implement `IteratorAggregate` for use in `foreach`, and `JsonSerializable` for JSON encoding. Maps always serialize as JSON objects (`{}`), vectors and sets as JSON arrays (`[]`).

Mutable collections also implement `ArrayAccess`:

```php
use Psl\Collection\MutableVector;

/**
 * @var MutableVector<int> $v
 */
$v = MutableVector::fromArray([10, 20, 30]);
$v[] = 40; // appends
$v[1] = 99; // sets index 1
unset($v[0]); // removes index 0
```

See [src/Psl/Collection/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/collection/src/Psl/Collection/) for the full API.
