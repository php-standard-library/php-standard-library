# Vec

The `Vec` component provides functions for creating and transforming sequential, 0-indexed arrays (`list<T>`). Every Vec function returns a re-indexed list, making them a type-safe, predictable replacement for PHP's `array_map`, `array_filter`, `array_values`, and similar functions.

Unlike PHP's built-in array functions, Vec functions guarantee consistent argument order (iterable first, callback second), never preserve keys, and always produce a `list<T>`.

## Transforming Values

```php
use Psl\Vec;

// Map: transform every element
Vec\map([1, 2, 3], fn(int $n) => $n * 2);
// [2, 4, 6]

// Map with key: the callback receives both key and value
Vec\map_with_key(['a', 'b', 'c'], fn(int $i, string $v) => $i . ':' . $v);
// ['0:a', '1:b', '2:c']

// Filter: keep elements matching a predicate
Vec\filter([1, 2, 3, 4, 5], fn(int $n) => $n > 3);
// [4, 5]

// Filter nulls: remove null values from a list
Vec\filter_nulls([1, null, 3, null, 5]);
// [1, 3, 5]

// Filter nonnull by: keep values where the callback returns non-null (original values are preserved)
Vec\filter_nonnull_by(['hello', '', 'world'], fn(string $v) => $v !== '' ? $v : null);
// ['hello', 'world']

// Map nonnull: transform values and discard null results
Vec\map_nonnull([1, 2, 3], fn(int $v) => $v > 1 ? $v * 2 : null);

// [4, 6]
```

## Sorting

```php
use Psl\Str;
use Psl\Vec;

// Sort in ascending order
Vec\sort([3, 1, 2]);
// [1, 2, 3]

// Sort with a custom comparator
Vec\sort(['banana', 'apple', 'cherry'], fn($a, $b) => Str\length($a) <=> Str\length($b));
// ['apple', 'banana', 'cherry']

// Sort by a derived value
$users = [['name' => 'Charlie', 'age' => 30], ['name' => 'Alice', 'age' => 25]];
Vec\sort_by($users, fn($u) => $u['age']);

// [['name' => 'Alice', 'age' => 25], ['name' => 'Charlie', 'age' => 30]]
```

## Combining and Splitting

```php
use Psl\Str;
use Psl\Vec;

// Concat: join multiple iterables into one list
Vec\concat([1, 2], [3, 4], [5]);
// [1, 2, 3, 4, 5]

// Flatten: merge a list of lists into a single list
Vec\flatten([[1, 2], [3], [4, 5]]);
// [1, 2, 3, 4, 5]

// Flat map: map then flatten in one step
Vec\flat_map(['hello world', 'foo bar'], fn($s) => Str\split($s, ' '));
// ['hello', 'world', 'foo', 'bar']

// Zip: pair up elements from two lists
Vec\zip(['a', 'b', 'c'], [1, 2, 3]);
// [['a', 1], ['b', 2], ['c', 3]]

// Chunk: split into groups of a given size
Vec\chunk([1, 2, 3, 4, 5], 2);
// [[1, 2], [3, 4], [5]]

// Partition: split into two lists based on a predicate
Vec\partition([1, 2, 3, 4, 5], fn($n) => ($n % 2) === 0);

// [[2, 4], [1, 3, 5]]
```

## Generating and Reshaping

```php
use Psl\Vec;

// Range: generate a sequence of numbers
Vec\range(1, 5); // [1, 2, 3, 4, 5]
Vec\range(0.0, 1.0, 0.5); // [0.0, 0.5, 1.0]

// Reproduce: generate values using a factory
Vec\reproduce(3, fn(int $i) => $i * $i);
// [1, 4, 9]

// Unique: remove duplicate values
Vec\unique([1, 2, 2, 3, 3, 3]);
// [1, 2, 3]

// Reverse: flip the order
Vec\reverse([1, 2, 3]);
// [3, 2, 1]

// Enumerate: convert key-value pairs into a list of [key, value] tuples
Vec\enumerate(['a' => 1, 'b' => 2]);
// [['a', 1], ['b', 2]]

// Keys/Values: extract keys or values as a list
Vec\keys(['x' => 10, 'y' => 20]); // ['x', 'y']
Vec\values(['x' => 10, 'y' => 20]); // [10, 20]
```

## Key Difference from Dict

Vec always re-indexes the result. If you filter an associative array with `Vec\filter`, the keys are discarded and the result is a sequential `list<T>`. If you need to preserve keys, use `Dict\filter` instead.

```php
use Psl\Dict;
use Psl\Vec;

$data = ['a' => 1, 'b' => 2, 'c' => 3];

Vec\filter($data, fn($v) => $v > 1);
// [2, 3]  -- keys dropped, re-indexed as list

Dict\filter($data, fn($v) => $v > 1);

// ['b' => 2, 'c' => 3]  -- keys preserved
```

See [src/Psl/Vec/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/vec/src/Psl/Vec/) for the full API.
