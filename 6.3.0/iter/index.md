# Iter

The `Iter` component provides utility functions for inspecting and reducing iterables. Unlike Vec and Dict, which eagerly produce arrays, Iter functions work directly with any `iterable` -- arrays, generators, and iterators -- making them ideal for querying data without creating intermediate collections.

## When to Use Iter vs Vec/Dict

Use **Vec** or **Dict** when you need a transformed array as output (mapping, filtering, sorting). Use **Iter** when you need to answer a question about an iterable (does it contain X? what is the first element? how many items?) or reduce it to a single value.

```php
use Psl\Iter;
use Psl\Vec;

// Vec: "give me a new array with doubled values"
Vec\map([1, 2, 3], fn($n) => $n * 2); // [2, 4, 6]

// Iter: "what is the sum of these values?"
Iter\reduce([1, 2, 3], fn($sum, $n) => $sum + $n, 0); // 6
```

## Querying

```php
use Psl\Iter;

// First and last element (null if empty)
Iter\first([10, 20, 30]); // 10
Iter\last([10, 20, 30]); // 30

// Check for emptiness
Iter\is_empty([]); // true
Iter\is_empty([1]); // false

// Count elements
Iter\count([1, 2, 3]); // 3

// Contains: check if a value exists (strict equality)
Iter\contains([1, 2, 3], 2); // true
Iter\contains([1, 2, 3], '2'); // false (strict)

// Contains key: check if a key exists
Iter\contains_key(['a' => 1, 'b' => 2], 'a'); // true

// Random: get a random element
Iter\random([10, 20, 30, 40]); // e.g. 30
```

## Predicates

```php
use Psl\Iter;

// All: do all elements satisfy the predicate? (short-circuits on false)
Iter\all([2, 4, 6], fn(int $n) => ($n % 2) === 0); // true
Iter\all([2, 3, 6], fn(int $n) => ($n % 2) === 0); // false

// Any: does at least one element satisfy the predicate? (short-circuits on true)
Iter\any([1, 3, 5], fn(int $n) => $n > 4); // true
Iter\any([1, 3, 5], fn(int $n) => $n > 9); // false
```

## Searching

```php
use Psl\Iter;
use Psl\Str;

// Search: find the first value matching a predicate
Iter\search(['foo', 'bar', 'baz'], fn(string $v) => Str\starts_with($v, 'ba'));
// 'bar'

Iter\search([1, 2, 3], fn(int $v) => $v > 10);

// null (not found)
```

## Reducing

```php
use Psl\Iter;

// Reduce: fold an iterable into a single value
Iter\reduce([1, 2, 3, 4], fn(int $carry, int $v) => $carry + $v, 0);
// 10

// Reduce with keys: the callback also receives the key
$cart = ['apple' => 2, 'banana' => 3];
$prices = ['apple' => 1.50, 'banana' => 0.75];

Iter\reduce_with_keys($cart, fn(float $total, string $item, int $qty) => $total + ($prices[$item] * $qty), 0.0);

// 5.25
```

## Side Effects

```php
use Psl\Iter;

// Apply: execute a function on each element (returns void)
Iter\apply(['alice', 'bob'], fn(string $name) => print "Hello, {$name}!\n");

// Prints:
//   Hello, alice!
//   Hello, bob!
```

## Joining Two Iterables

`Iter\merge_join_by` and `Iter\merge_join_by_key` perform a full outer join of two iterables, yielding an `EitherOrBoth` event for each element that is present on the left only, the right only, or both. The sorted variant is lazy and uses O(1) memory on first traversal; the keyed variant materializes the right-hand side into a lookup and streams the left. Both return a rewindable `Iter\Iterator` -- a second iteration replays the cached events without re-walking the inputs.

```php
use Psl\Comparison\Order;
use Psl\EitherOrBoth;
use Psl\Iter;
use Psl\Vec;

// -- Sorted inputs: merge_join_by
// Both inputs must already be sorted according to the comparator. Lazy, O(1) memory
// on first traversal. The returned `Iter\Iterator` is rewindable.
$events = Vec\map(
    Iter\merge_join_by([1, 2, 4], [2, 3, 4], static fn(int $a, int $b): Order => Order::from($a <=> $b)),
    static fn(EitherOrBoth\EitherOrBoth $e): string => $e->proceed(
        left: static fn(int $v): string => "left:{$v}",
        right: static fn(int $v): string => "right:{$v}",
        both: static fn(int $l, int $r): string => "both:{$l}={$r}",
    ),
);
// ['left:1', 'both:2=2', 'right:3', 'both:4=4']

// -- Keyed inputs: merge_join_by_key
// Inputs do not need to be sorted. O(|right|) memory. Ideal when records carry an id.

/** @var list<array{id: int, name: string}> $incoming */
$incoming = [['id' => 1, 'name' => 'Ada'], ['id' => 3, 'name' => 'Grace']];

/** @var list<array{id: int, name: string}> $current */
$current = [['id' => 1, 'name' => 'Ada (old)'], ['id' => 2, 'name' => 'Linus']];

$changelog = Vec\map(
    Iter\merge_join_by_key(
        $incoming,
        $current,
        /** @param array{id: int, name: string} $r */
        static fn(array $r): int => $r['id'],
    ),
    /** @param EitherOrBoth\EitherOrBoth<array{id: int, name: string}, array{id: int, name: string}> $e */
    static fn(EitherOrBoth\EitherOrBoth $e): string => $e->proceed(
        /** @param array{id: int, name: string} $r */
        left: static fn(array $r): string => "+ {$r['id']} {$r['name']}",
        /** @param array{id: int, name: string} $r */
        right: static fn(array $r): string => "- {$r['id']} {$r['name']}",
        /**
         * @param array{id: int, name: string} $n
         * @param array{id: int, name: string} $o
         */
        both: static fn(array $n, array $o): string => "~ {$n['id']} {$o['name']} -> {$n['name']}",
    ),
);

// ['~ 1 Ada (old) -> Ada', '- 2 Linus', '+ 3 Grace']
```

See the [EitherOrBoth](#either-or-both) page for the shape of the events and patterns for consuming them.

## Rewindable Iterators

Generators in PHP can only be iterated once. The `Iter\Iterator` class wraps a generator so it can be rewound and iterated multiple times without re-executing the generator.

```php
use Psl\Iter;

$gen = (function () {
    yield 'a';
    yield 'b';
    yield 'c';
})();

$iterator = Iter\rewindable($gen);

Iter\count($iterator); // 3
$iterator->rewind();
Iter\first($iterator); // 'a' -- still accessible after counting
```

See [src/Psl/Iter/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/iter/src/Psl/Iter/) for the full API.
