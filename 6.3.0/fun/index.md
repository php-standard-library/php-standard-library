# Fun

The `Fun` component provides functional programming utilities for composing, decorating, and controlling function execution. These small combinators let you build data pipelines, inject side effects without disrupting flow, and defer expensive computations until they are actually needed.

## Usage

### Piping Values Through Functions

`pipe()` chains closures left-to-right. Each function receives the output of the previous one:

```php
use Psl\Fun;
use Psl\Str;

$transform = Fun\pipe(
    static fn(string $s): string => Str\trim($s),
    static fn(string $s): string => Str\lowercase($s),
    static fn(string $s): string => Str\replace($s, ' ', '-'),
);

$transform('  Hello World  '); // 'hello-world'
```

### Composing Two Functions

`after()` connects two functions where the output type of the first can differ from the input type of the second:

```php
use Psl\Fun;
use Psl\Str;

$strlen = Fun\after(static fn(string $s): string => Str\trim($s), static fn(string $s): int => Str\length($s));

$strlen('  hi  '); // 2
```

### Side Effects with tap()

`tap()` runs a callback for its side effect and returns the original value unchanged -- useful for logging or debugging inside a pipeline:

```php
use Psl\Fun;
use Psl\IO;
use Psl\Str;

$process = Fun\pipe(
    Fun\tap(static fn(string $v) => IO\write_error_line('input: %s', $v)),
    static fn(string $s): string => Str\uppercase($s),
    Fun\tap(static fn(string $v) => IO\write_error_line('output: %s', $v)),
);

$process('hello'); // 'HELLO' (logs are emitted as a side effect)
```

### Lazy Evaluation

`lazy()` wraps an initializer so the value is computed once on first access, then cached:

```php
use Psl\Fun;
use Psl\Vec;

$config = Fun\lazy(static fn(): array => Vec\fill(1000, 'value'));

$config(); // computes the array
$config(); // returns cached result
```

### Conditional Logic

`when()` returns one of two results based on a predicate:

```php
use Psl\Fun;

$classify = Fun\when(
    static fn(int $n): bool => $n >= 18,
    static fn(int $_n): string => 'adult',
    static fn(int $_n): string => 'minor',
);

$classify(21); // 'adult'
$classify(12); // 'minor'
```

### Utility Functions

`identity()` returns a closure that passes its argument through unchanged -- handy as a default transformer or no-op callback:

```php
use Psl\Fun;

$fn = Fun\identity();
$fn(42); // 42
```

`rethrow()` returns a closure that rethrows any exception it receives -- useful as an error callback:

```php
use Psl\Fun;

$handler = Fun\rethrow();

try {
    $handler(new RuntimeException('boom'));
} catch (RuntimeException $e) {
    echo $e->getMessage(); // 'boom'
}
```

See [src/Psl/Fun/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/fun/src/Psl/Fun/) for the full API.
