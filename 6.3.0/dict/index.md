# Dict

The `Dict` component provides functions for creating and transforming associative arrays (`array<Tk, Tv>`). Unlike Vec, Dict functions preserve keys, making them the right choice when the relationship between keys and values matters.

Dict functions are type-safe replacements for PHP's `array_map`, `array_filter`, `array_combine`, `array_merge`, and similar functions, with consistent argument order and predictable behavior.

## Transforming Values and Keys

```php
use Psl\Dict;
use Psl\Str;

// Map: transform values, keep keys intact
Dict\map(['a' => 1, 'b' => 2, 'c' => 3], fn(int $v) => $v * 10);
// ['a' => 10, 'b' => 20, 'c' => 30]

// Map keys: transform keys, keep values intact
Dict\map_keys(['a' => 1, 'b' => 2], fn(string $k) => Str\uppercase($k));
// ['A' => 1, 'B' => 2]

// Map with key: callback receives both key and value
Dict\map_with_key(['width' => 100, 'height' => 50], fn(string $k, int $v) => $k . '=' . $v);

// ['width' => 'width=100', 'height' => 'height=50']
```

## Filtering

```php
use Psl\Dict;
use Psl\Str;

// Filter by value (keys are preserved)
Dict\filter(['a' => 1, 'b' => 0, 'c' => 3], fn(int $v) => $v > 0);
// ['a' => 1, 'c' => 3]

// Filter by key
Dict\filter_keys(['admin' => true, 'guest' => false], fn(string $k) => $k !== 'guest');
// ['admin' => true]

// Filter with both key and value
Dict\filter_with_key(['foo' => 1, 'bar' => 2, 'baz' => 3], fn(string $k, int $v) => $v > 1 && Str\contains($k, 'a'));
// ['bar' => 2, 'baz' => 3]

// Filter nonnull by: keep values where the callback returns non-null (original values are preserved)
Dict\filter_nonnull_by(['a' => 'hello', 'b' => '', 'c' => 'world'], fn(string $v) => $v !== '' ? $v : null);
// ['a' => 'hello', 'c' => 'world']

// Map nonnull: transform values and discard null results (keys are preserved)
Dict\map_nonnull([1, 2, 3], fn(int $v) => $v > 1 ? $v * 2 : null);

// [1 => 4, 2 => 6]
```

## Building Dicts

```php
use Psl\Dict;
use Psl\Str;

// Associate: zip keys and values into a dict
Dict\associate(['x', 'y', 'z'], [10, 20, 30]);
// ['x' => 10, 'y' => 20, 'z' => 30]

// From entries: build from [key, value] tuples
Dict\from_entries([['name', 'Alice'], ['role', 'admin']]);
// ['name' => 'Alice', 'role' => 'admin']

// From keys: generate values from keys using a function
Dict\from_keys(
    ['sm', 'md', 'lg'],
    /**
     * @param 'sm'|'md'|'lg' $size
     *
     * @return 576|768|992
     */
    fn(string $size) => match ($size) {
        'sm' => 576,
        'md' => 768,
        'lg' => 992,
    },
);
// ['sm' => 576, 'md' => 768, 'lg' => 992]

// Reindex: re-key an iterable using a function applied to each value
$users = [['id' => 42, 'name' => 'Alice'], ['id' => 7, 'name' => 'Bob']];
Dict\reindex($users, fn($user) => $user['id']);
// [42 => ['id' => 42, 'name' => 'Alice'], 7 => ['id' => 7, 'name' => 'Bob']]

// Pull: build a dict by deriving both new keys and new values
Dict\pull(
    ['Alice', 'Bob'],
    fn(string $name) => Str\length($name), // value function
    fn(string $name) => Str\lowercase($name), // key function
);

// ['alice' => 5, 'bob' => 3]
```

## Merging and Selecting

```php
use Psl\Dict;

// Merge: combine multiple dicts (later values overwrite earlier ones)
Dict\merge(['a' => 1, 'b' => 2], ['b' => 99, 'c' => 3]);
// ['a' => 1, 'b' => 99, 'c' => 3]

// Select keys: pick only the specified keys
Dict\select_keys(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4], ['a', 'c']);
// ['a' => 1, 'c' => 3]

// Flip: swap keys and values
Dict\flip(['a' => 1, 'b' => 2]);
// [1 => 'a', 2 => 'b']

// Unique: remove entries with duplicate values (keeps the first occurrence's key)
Dict\unique([1 => 'a', 2 => 'b', 3 => 'a']);

// [1 => 'a', 2 => 'b']
```

## Sorting

```php
use Psl\Dict;

// Sort by values (keys are preserved)
Dict\sort(['b' => 3, 'a' => 1, 'c' => 2]);
// ['a' => 1, 'c' => 2, 'b' => 3]

// Sort by keys
Dict\sort_by_key(['banana' => 2, 'apple' => 1, 'cherry' => 3]);

// ['apple' => 1, 'banana' => 2, 'cherry' => 3]
```

## Grouping

```php
use Psl\Dict;

// Group by: partition values into buckets based on a key function
$words = ['apple', 'avocado', 'banana', 'blueberry', 'cherry'];
Dict\group_by($words, fn(string $w) => $w[0]);
// ['a' => ['apple', 'avocado'], 'b' => ['banana', 'blueberry'], 'c' => ['cherry']]

// Returning null from the key function excludes that element
Dict\group_by([1, 2, 3, 4, 5], static function (int $n): null|string {
    if ($n <= 2) {
        return null; // exclude 1 and 2
    }

    if (($n % 2) === 0) {
        return 'even';
    }

    return 'odd';
});

// ['odd' => [3, 5], 'even' => [4]]
```

See [src/Psl/Dict/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/dict/src/Psl/Dict/) for the full API.
