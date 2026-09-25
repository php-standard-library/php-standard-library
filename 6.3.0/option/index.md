# Option

The `Option` component represents a value that may or may not be present. It replaces nullable types (`?T`) with explicit `Some`/`None` semantics, making the absence of a value intentional and impossible to ignore.

Instead of returning `null` and hoping callers remember to check, you return an `Option` that forces the caller to handle both cases.

## Design

`Option<T>` is a single `readonly` class with two states:

- **`Option::some($value)`** -- A value is present
- **`Option::none()`** -- No value is present

Convenience functions `some()`, `none()`, and `from_nullable()` are available in the `Psl\Option` namespace.

## Usage

### Creating Options

```php
use Psl\Option;

$present = Option\some(42);
$absent = Option\none();

// Bridge from nullable values
$option = Option\from_nullable('hello');
// null becomes None, anything else becomes Some

$fromNull = Option\from_nullable(null);

// $fromNull is None
```

### Checking and Unwrapping

```php
use Psl\Option;

$opt = Option\some(42);
$opt->isSome(); // true
$opt->isNone(); // false
$opt->isSomeAnd(fn(int $v) => $v > 10); // true
$opt->contains(42); // true

// Unwrap the value
$opt->unwrap(); // 42 (throws NoneException if None)
$opt->unwrapOr(0); // 42 (returns default if None)
$opt->unwrapOrElse(fn() => 0); // 42 (lazy default if None)
```

### Transforming Values

```php
use Psl\Option;

$opt = Option\some(5);

// map: transform the inner value
$opt->map(fn(int $v) => $v * 2); // Some(10)
Option\none()->map(fn(int $v) => $v * 2); // None

// andThen: chain operations that return Options (flatMap)
Option\some('hello')
    ->andThen(fn(string $v) => Option\from_nullable($v !== '' ? $v : null))
    ->andThen(fn(string $v) => Option\some(strlen($v)));
// Some(5) if non-empty string, None otherwise

// mapOr / mapOrElse: transform with a default
Option\none()->mapOr(fn(int $v) => $v * 2, 0); // Some(0)
```

### Pattern Matching with proceed()

Handle both cases and return a unified result:

```php
use Psl\Option;

$username = 'Alice';

$greeting = Option\from_nullable($username)->proceed(
    fn(string $name) => "Welcome back, {$name}!",
    fn() => 'Welcome, guest!',
);
```

### Side Effects with apply()

Run a closure on the value without changing the Option:

```php
use Psl\Option;

$result = Option\some('admin')
    ->apply(fn(string $role) => print "Found role: {$role}\n")
    ->map(fn(string $role) => strtoupper($role));
```

### Filtering

```php
use Psl\Option;

Option\some(42)->filter(fn(int $v) => $v > 10); // Some(42)
Option\some(42)->filter(fn(int $v) => $v > 100); // None
```

### Combining Options

```php
use Psl\Option;

$a = Option\some(1);
$b = Option\some(2);
$none = Option\none();

$a->and($b); // Some(2) -- returns second if both Some
$none->or($b); // Some(2) -- returns first Some found
$none->orElse(fn() => Option\some(99)); // Some(99)

// Zip: combine into a tuple
$a->zip($b); // Some([1, 2])
$a->zip($none); // None

// ZipWith: combine with a function
$a->zipWith($b, fn(int $x, int $y) => $x + $y); // Some(3)

// Unzip: split a tuple Option
Option\some([1, 2])->unzip(); // [Some(1), Some(2)]
Option\none()->unzip(); // [None, None]
```

## When to Use Option

- **Repository lookups**: `find_by_id()` returns `Option<Entity>` instead of `?Entity`
- **Configuration**: `get_setting()` returns `Option<string>` -- absence is explicit
- **Chained lookups**: Use `andThen()` to chain fallible lookups without nested null checks
- **Safe data access**: Parse user input with `from_nullable()` and transform with `map()`

See [src/Psl/Option/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/option/src/Psl/Option/) for the full API.
