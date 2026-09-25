# Foundation

The `Foundation` package provides the base exceptions, `Ref`, and `invariant()` utilities used across all PSL components.

## Usage

```php
use Psl\Ref;

// Assert invariants: throws InvariantViolationException when the condition is false
$items = ['apple', 'banana', 'cherry'];

Psl\invariant($items !== [], 'Item list must not be empty.');

// Mutable reference wrapper: pass by reference without PHP's & syntax
$counter = new Ref(0);

$increment =
    /**
     * @param Ref<int> $counter
     */
    static function (Ref $counter, int $amount): void {
        $counter->value += $amount;
    };

$increment($counter, 5);
$increment($counter, 3);

echo $counter->value; // 8
```

## Key Concepts

`invariant()` is PSL's assertion function. Unlike PHP's `assert()`, it is always evaluated (never stripped in production) and throws a typed exception (`InvariantViolationException`) rather than triggering an error. Use it to enforce preconditions that must never be violated.

`Ref` is a generic mutable reference wrapper. It exists because PHP closures capture variables by value by default. Wrapping a value in `Ref` lets you share mutable state across closures without using `&` references.

See [src/Psl/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/foundation/src/Psl/) for the full API.
