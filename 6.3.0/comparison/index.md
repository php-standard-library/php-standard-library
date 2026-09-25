# Comparison

The `Comparison` component provides interfaces and functions for comparing values in a type-safe, consistent way. Instead of relying on PHP's loose comparison rules or scattering `<=>` operators throughout your code, you implement a `Comparable` interface and get a full suite of comparison operations for free.

## Design

- **`Comparable<T>`** -- Interface for types that can be ordered relative to each other
- **`Equable<T>`** -- Interface for types that can be checked for equality
- **`Order`** -- Enum with three cases: `Less`, `Equal`, `Greater`
- **Comparison functions** -- `compare()`, `equal()`, `less()`, `greater()`, and more

## Usage

### The Order Enum

`Order` replaces magic integers (`-1`, `0`, `1`) with readable, type-safe cases:

```php
use Psl\Comparison\Order;
use Psl\IO;

// Order replaces magic integers (-1, 0, 1) with readable, type-safe cases
IO\write_error_line('Order::Less    = %d', Order::Less->value); // -1 - first value is smaller
IO\write_error_line('Order::Equal   = %d', Order::Equal->value); // 0  - values are equal
IO\write_error_line('Order::Greater = %d', Order::Greater->value); // 1  - first value is larger
```

### Comparing Values

The `compare()` function works with any values. For types implementing `Comparable`, it delegates to their `compare()` method. For everything else, it falls back to PHP's `<=>` operator:

```php
use Psl\Comparison;

Comparison\compare(1, 2); // Order::Less
Comparison\compare('b', 'a'); // Order::Greater
Comparison\equal(42, 42); // true
Comparison\less(1, 2); // true
Comparison\greater_or_equal(3, 3); // true
```

### Implementing Comparable

Make your classes sortable and comparable by implementing the `Comparable` interface:

```php
use Psl\Comparison;

final readonly class Money implements Comparison\Comparable, Comparison\Equable
{
    public function __construct(
        private int $cents,
        private string $currency,
    ) {}

    public function compare(mixed $other): Comparison\Order
    {
        if ($this->currency !== $other->currency) {
            throw Comparison\Exception\IncomparableException::fromValues(
                $this,
                $other,
                'Cannot compare different currencies',
            );
        }

        return Comparison\compare($this->cents, $other->cents);
    }

    public function equals(mixed $other): bool
    {
        return Comparison\equal($this, $other);
    }
}

$a = new Money(500, 'USD');
$b = new Money(1000, 'USD');

Comparison\less($a, $b); // true
Comparison\greater($b, $a); // true
Comparison\equal($a, $a); // true
```

### Sorting with Comparable

The `sort()` function returns an `int` suitable for PHP's `usort()` or PSL's `Vec\sort`:

```php
use Psl\Comparison;
use Psl\Vec;

/**
 * @implements Comparison\Comparable<Money>
 * @implements Comparison\Equable<Money>
 */
final readonly class Money implements Comparison\Comparable, Comparison\Equable
{
    public function __construct(
        private int $cents,
        private string $currency,
    ) {}

    /**
     * @param Money $other
     *
     * @throws Comparison\Exception\IncomparableException
     */
    public function compare(mixed $other): Comparison\Order
    {
        if ($this->currency !== $other->currency) {
            throw Comparison\Exception\IncomparableException::fromValues(
                $this,
                $other,
                'Cannot compare different currencies',
            );
        }

        return Comparison\compare($this->cents, $other->cents);
    }

    /**
     * @param Money $other
     */
    public function equals(mixed $other): bool
    {
        return Comparison\equal($this, $other);
    }
}

$prices = [new Money(1000, 'USD'), new Money(500, 'USD'), new Money(750, 'USD')];

$sorted = Vec\sort($prices, Comparison\sort(...));

// [Money(500), Money(750), Money(1000)]
```

### Available Comparison Functions

All functions work with both `Comparable` types and plain scalars:

| Function | Description |
|---|---|
| `compare($a, $b)` | Returns `Order` enum |
| `equal($a, $b)` | True if values are equal |
| `not_equal($a, $b)` | True if values differ |
| `less($a, $b)` | True if `$a < $b` |
| `greater($a, $b)` | True if `$a > $b` |
| `less_or_equal($a, $b)` | True if `$a <= $b` |
| `greater_or_equal($a, $b)` | True if `$a >= $b` |
| `sort($a, $b)` | Returns `int` for use as a sort callback |

See [src/Psl/Comparison/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/comparison/src/Psl/Comparison/) for the full API.
