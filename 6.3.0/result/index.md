# Result

The `Result` component represents a computation that either succeeded or failed. Instead of letting exceptions propagate uncontrolled, you capture the outcome as a value that can be inspected, transformed, and passed around.

This is useful when you want to defer error handling, aggregate results from multiple operations, or build transformation pipelines without scattering try/catch blocks throughout your code.

## Design

- **`Success<T>`** -- Wraps a successful return value of type `T`
- **`Failure<T>`** -- Wraps a `Throwable` from a failed operation
- **`ResultInterface<T>`** -- The common interface both implement

## Usage

### Wrapping Operations

The `wrap()` function executes a closure and captures the outcome:

```php
use Psl\Json;
use Psl\Result;
use Psl\Type;

$result = Result\wrap(
    /** @return array<string, string> */
    fn(): array => Json\typed('{"name":"Alice"}', Type\dict(Type\string(), Type\string())),
);

if ($result->isSucceeded()) {
    $data = $result->getResult();
} else {
    $error = $result->getThrowable();
}

// Get the value with a fallback
$data = $result->unwrapOr([]);
```

### Transforming Results

`map()` transforms a successful value, leaving failures untouched. `catch()` recovers from failures, leaving successes untouched:

```php
use Psl\File;
use Psl\Result;
use Psl\Str;

$lines = Result\wrap(fn() => File\read(__FILE__))
    ->map(fn(string $content) => Str\split($content, "\n"))
    ->catch(fn(Throwable $_e) => []);

// Success: maps content to lines
// Failure: recovers with empty array
```

`then()` handles both cases at once, returning a new `ResultInterface`:

```php
use Psl\Json;
use Psl\Result;

$normalized = Result\wrap(fn() => Json\decode('{"name":"Alice","age":30}'))
    ->then(fn(mixed $data) => ['status' => 'ok', 'data' => $data], fn(Throwable $e) => ['error' => $e->getMessage()]);

// $normalized is ResultInterface<array>, still wrapped
```

### Pattern Matching with proceed()

The `proceed()` method unwraps the result by calling the appropriate closure and returning the value directly (not wrapped in a Result):

```php
use Psl\Iter;
use Psl\Json;
use Psl\Result;
use Psl\Type;

$message = Result\wrap(fn(): array => Json\typed('{"a":1,"b":2,"c":3}', Type\dict(Type\string(), Type\int())))
    ->proceed(
        fn(array $data): string => 'Parsed: ' . Iter\count($data) . ' items',
        fn(Throwable $e): string => 'Failed: ' . $e->getMessage(),
    );
```

### Cleanup with always()

Run cleanup logic regardless of outcome:

```php
use Psl\File;
use Psl\Filesystem;
use Psl\Result;

$temp = Filesystem\create_temporary_file();
File\write($temp, 'temporary data');

$result = Result\wrap(fn() => File\read($temp))->always(fn() => Filesystem\delete_file($temp));
```

### Convenience Functions

`try_catch()` wraps and recovers in a single step:

```php
use Psl\Json;
use Psl\Result;

$_ = Result\try_catch(fn() => Json\decode('not valid json'), fn(Throwable $_e) => []);
```

`reflect()` converts a throwing closure into one that returns a Result:

```php
use Psl\Json;
use Psl\Result;

$safeParse = Result\reflect(fn() => Json\decode('{"key":"value"}'));
$result = $safeParse(); // ResultInterface, never throws
```

### Collecting Statistics

When processing batches, `collect_stats()` summarizes outcomes:

```php
use Psl\Json;
use Psl\Result;
use Psl\Vec;

$inputs = ['{"a":1}', 'invalid', '{"b":2}', '{bad}', '{"c":3}'];

$results = Vec\map($inputs, fn(string $input) => Result\wrap(fn() => Json\decode($input)));

$stats = Result\collect_stats($results);
$stats->total(); // total number of results
$stats->succeeded(); // number of successes
$stats->failed(); // number of failures
```

## When to Use Result

- **Batch processing**: Collect results from multiple operations without stopping at the first error
- **Pipeline transformations**: Chain `map()` calls to build data transformation pipelines
- **Deferred error handling**: Capture errors as values and handle them at the right level
- **API boundaries**: Return Result from service methods instead of throwing across layers

See [src/Psl/Result/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/result/src/Psl/Result/) for the full API.
