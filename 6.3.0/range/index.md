# Range

The `Range` component provides a way to create a range of integer values.

Ranges represent sets of integer values with optional lower and upper bounds. They support containment checks, iteration, and bound manipulation.

## Usage

### Creating Ranges

There are four types of ranges, created with constructor functions:

```php
use Psl\Range;

$range = Range\from(0); // 0..   (from 0 to infinity)
$range = Range\to(10); // ..10  (up to 10, exclusive)
$range = Range\to(10, true); // ..=10 (up to 10, inclusive)
$range = Range\between(0, 10); // 0..10 (from 0 to 10, exclusive)
$range = Range\between(0, 10, true); // 0..=10 (from 0 to 10, inclusive)
$range = Range\full(); // ..    (all integers)
```

### Containment Checks

All range types support `contains()`:

```php
use Psl\Range;

$range = Range\between(0, 10);
$range->contains(5); // true
$range->contains(100); // false

$range = Range\from(0);
$range->contains(5); // true
$range->contains(100); // true

$range = Range\full();
$range->contains(5); // true
$range->contains(100); // true
```

### Iterating Over Ranges

Ranges with a lower bound (`FromRange` and `BetweenRange`) are iterable:

```php
use Psl\Range;

$range = Range\between(0, 5);

$range = Range\between(0, 5, true);

// FromRange is iterable but infinite -- use with care
$range = Range\from(0);
foreach ($range as $value) {
    // $value is 0, 1, 2, 3, ...
    if ($value > 100) {
        break;
    }
}
```

### Manipulating Bounds

Ranges can be combined with new bounds to create different range types:

```php
use Psl\Range;

// Add an upper bound to a FromRange
$from = Range\from(0);
$between = $from->withUpperBoundInclusive(10);
// Now a BetweenRange: 0..=10

// Remove the lower bound
$to = $between->withoutLowerBound();
// Now a ToRange: ..=10

// Add a lower bound to a ToRange
$between = $to->withLowerBound(5);

// Now a BetweenRange: 5..=10
```

See [src/Psl/Range/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/range/src/Psl/Range/) for the full API.
