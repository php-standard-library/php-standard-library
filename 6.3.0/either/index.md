# Either

The `Either` component represents a value that is one of two possible types: `Left` or `Right`. Unlike `Result` (which specifically models success/failure with exceptions), `Either` is a general-purpose disjoint union -- both sides carry arbitrary values.

This is useful when an operation has two equally valid outcomes that aren't naturally "success" or "error", such as returning cached data vs. fresh data, or routing a value down one of two processing paths.

By convention, `Left` represents the secondary/error case and `Right` represents the primary/success case, but this is only a convention -- both sides are first-class values.

## Design

- **`Left<TLeft>`** -- Holds a value of the left type
- **`Right<TRight>`** -- Holds a value of the right type
- **`Either<TLeft, TRight>`** -- The common interface both implement

## Usage

### Creating Either Values

```php
use Psl\Either;

$left = new Either\Left('not found');
$right = new Either\Right(42);

$right->isRight(); // true
$left->isLeft(); // true
```

### Extracting Values

```php
use Psl\Either;

$either = new Either\Right('hello');

// Direct access (throws if wrong side)
$either->getRight(); // 'hello'
// $either->getLeft();        // throws RightException

// Safe access with defaults
$either->getRightOr('fallback'); // 'hello'
$either->getLeftOr('fallback'); // 'fallback'

// Lazy default -- only computed if needed
$either->getRightOrElse(static fn(string $left): string => 'computed from: ' . $left);

// Convert to Option
$either->unwrapRight(); // Some('hello')
$either->unwrapLeft(); // None
```

### Transforming Values

`mapRight()` transforms the right value, leaving a left untouched. `mapLeft()` does the opposite. `map()` transforms whichever side is present:

```php
use Psl\Either;

$parsed = new Either\Right('42');

$result = $parsed->mapRight(static fn(string $v): int => (int) $v)->mapRight(static fn(int $v): int => $v * 2);
// Right(84)

$error = new Either\Left('invalid input');
$error->mapRight(static fn(string $v): int => (int) $v);

// Left('invalid input') -- unchanged
```

### Chaining with flatMap

When a transformation itself returns an `Either`, use `flatMapRight()` or `flatMapLeft()` to avoid nested `Either` values:

```php
use Psl\Either;

/**
 * @return Either\Either<string, int>
 */
function parse_int(string $input): Either\Either
{
    return is_numeric($input) ? new Either\Right((int) $input) : new Either\Left("'{$input}' is not a number");
}

$result = new Either\Right('42')->flatMapRight(static fn(string $v): Either\Either => parse_int($v));

// Right(42)
```

### Pattern Matching with proceed()

Handle both cases and return a unified result. The right (happy-path) closure comes first:

```php
use Psl\Either;

/** @var Either\Either<string, int> $either */
$either = new Either\Right(42);

$message = $either->proceed(
    static fn(int $value): string => "Got value: {$value}",
    static fn(string $error): string => "Error: {$error}",
);

// 'Got value: 42'
```

### Swapping Sides

`swap()` flips a `Left` into a `Right` and vice versa:

```php
use Psl\Either;

$left = new Either\Left('hello');
$right = $left->swap(); // Right('hello')
```

### Side Effects with apply()

Run a closure on the contained value without changing the `Either`:

```php
use Psl\Either;
use Psl\IO;
use Psl\Str;

$either = new Either\Right('some data')
    ->apply(static fn(string $v): mixed => IO\write_error_line('Processing value: %s', $v))
    ->mapRight(static fn(string $v): string => Str\uppercase($v));

// Right('SOME DATA')
```

## When to Use Either vs Result

- **Result** -- The operation can succeed or throw. You want to capture exceptions as values.
- **Either** -- The operation has two valid outcomes. Both sides carry meaningful domain values, not exceptions.

## When to Use Either vs EitherOrBoth

If both sides can be present at the same time (for example, three-way diff events on two partially-overlapping collections), reach for [EitherOrBoth](#either-or-both) instead. `Either` is the right type when exactly one side is present; `EitherOrBoth` is the right type when either or both can be.

See [src/Psl/Either/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/either/src/Psl/Either/) for the full API.
