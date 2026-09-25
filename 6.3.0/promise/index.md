# Promise

The `Promise` component provides a promise interface for deferred computations. A promise represents a value that will be available in the future -- either resolved with a success value or rejected with an exception. You attach callbacks to react to the outcome without blocking.

## Design

`PromiseInterface<T>` defines four methods for composing asynchronous operations:

- **`then()`** -- Handle both success and failure in one call
- **`map()`** -- Transform the resolved value
- **`catch()`** -- Recover from rejection
- **`always()`** -- Run cleanup logic regardless of outcome

Every method returns a new `PromiseInterface`, so you can chain them into pipelines.

## Usage

### Transforming a Resolved Value

`map()` applies a function to the resolved value. If the promise is rejected, the callback is skipped and the rejection propagates:

```php
use Psl\Async;
use Psl\Str;

/** @var Async\Awaitable<string> $promise */
$promise = Async\run(static fn() => 'hello');

$upper = $promise->map(fn(string $body) => Str\uppercase($body));
// If $promise resolves with 'hello', $upper resolves with 'HELLO'
// If $promise is rejected, $upper is also rejected with the same exception

$upper->await(); // 'HELLO'
```

### Recovering from Failure

`catch()` attaches a callback that is only invoked when the promise is rejected. The returned value becomes the new resolved value:

```php
use Psl\Async;

/** @var Async\Awaitable<string> $promise */
$promise = Async\run(static fn() => throw new Exception('oh no'));

$safe = $promise->catch(fn(Throwable $_) => 'default response');
// If $promise is rejected, $safe resolves with 'default response'
// If $promise succeeds, $safe resolves with the original value

$safe->await(); // 'default response'
```

### Handling Both Cases with then()

`then()` is a shortcut for chaining `map()` and `catch()`:

```php
use Psl\Async;

/** @var Async\Awaitable<string> $promise */
$promise = Async\run(static fn() => 'hello world');

$result = $promise->then(fn(string $value) => ['value' => $value], fn(Throwable $e) => ['error' => $e->getMessage()]);

$result->await(); // ['value' => 'hello world']
```

This is equivalent to:

```php
use Psl\Async;

/** @var Async\Awaitable<string> $promise */
$promise = Async\run(static fn() => 'hello world');

$result = $promise->map(fn(string $value) => ['value' => $value])->catch(fn(Throwable $e) => [
    'error' => $e->getMessage(),
]);

$result->await(); // ['value' => 'hello world']
```

### Cleanup with always()

`always()` runs a callback when the promise settles, regardless of whether it resolved or was rejected. The promise's value passes through unchanged (unless the callback throws):

```php
use Psl\Async;
use Psl\IO;
use Psl\Str;

/** @var Async\Awaitable<string> $promise */
$promise = Async\run(static fn() => 'hello');

$promise
    ->map(fn(string $content) => Str\uppercase($content))
    ->always(fn() => IO\write_error_line('cleanup complete'))
    ->await();
```

### Building Pipelines

Because every method returns a new promise, you can compose multi-step async workflows:

```php
use Psl\Async;
use Psl\IO;
use Psl\Json;
use Psl\Type;

/** @var Async\Awaitable<string> $promise */
$promise = Async\run(static fn() => '{"name": "psl", "version": "3.0"}');

$processed = $promise
    ->map(fn(string $raw) => Json\decode($raw))
    ->map(
        fn(mixed $data) => Type\shape([
            'name' => Type\string(),
            'version' => Type\string(),
        ])->coerce($data),
    )
    ->map(fn(array $valid) => ['project' => $valid['name'], 'v' => $valid['version']])
    ->catch(fn(Throwable $e) => ['error' => $e->getMessage()])
    ->always(fn() => IO\write_error_line('pipeline complete'));

$processed->await();
```

## When to Use Promise

- **Async operations** -- React to the outcome of concurrent tasks
- **Pipeline composition** -- Chain transformations that may fail at any step
- **Error boundaries** -- Catch and recover from failures at specific points in the chain
- **Resource cleanup** -- Use `always()` to guarantee cleanup runs

See [src/Psl/Promise/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/promise/src/Psl/Promise/) for the full API.
