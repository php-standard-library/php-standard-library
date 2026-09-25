# Async

The `Async` component brings concurrency into PHP using [cooperative multitasking](https://en.wikipedia.org/wiki/Cooperative_multitasking).

> **Note**
>
> The Async component is built on top of [RevoltPHP](https://github.com/revoltphp/event-loop), which makes it compatible with [Amphp](https://github.com/amphp),
> and other libraries that use the same event loop.

## Quick Start

```php
use Psl\Async;
use Psl\IO;
use Psl\Shell;

$watcher = Async\Scheduler::onSignal(SIGINT, function (): never {
    IO\write_error_line('SIGINT received, stopping...');
    exit(0);
});

Async\Scheduler::unreference($watcher);

IO\write_error_line('Press Ctrl+C to stop');

Async\concurrently([
    static fn(): string => Shell\execute('sleep', ['3']),
    static fn(): string => Shell\execute('echo', ['Hello World!']),
    static fn(): string => Shell\execute('echo', ['Hello World!']),
]);

IO\write_error_line('Done!');
```

## Core Concepts

### Entry Point

`Async\main()` is the entry point for async applications. It executes a closure in the main fiber, then keeps the event loop running until all pending callbacks complete. The closure must return an integer exit code.

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;

Async\main(static function (): int {
    Async\Scheduler::delay(Duration::seconds(1), static function (): void {
        IO\write_line('hello');
    });

    return 0;
});

// Output:
// hello
```

### Running Async Operations

`Async\run()` creates a new fiber and returns an `Awaitable` that resolves to its result:

```php
use Psl\Async;
use Psl\DateTime\Duration;

$awaitable = Async\run(static function (): string {
    Async\sleep(Duration::seconds(1));
    return 'Hello world!';
});

$result = $awaitable->await(); // 'Hello world!'
```

### Awaitables

An `Awaitable` is a promise-like object representing a value that may not yet be available. It can be awaited, mapped, chained, and composed. The `await()` method accepts an optional `CancellationTokenInterface` to cancel the wait.

```php
use Psl\Async;
use Psl\Str;

$awaitable = Async\run(static fn() => 'hello');

// Chain transformations
$awaitable = $awaitable->map(static fn($result) => Str\format('%s world', $result));

$result = $awaitable->await(); // 'hello world'
```

Error handling works naturally with `catch()`:

```php
use Psl\Async;

$awaitable = Async\run(static function (): string {
    throw new Exception('Something went wrong!');
});

$awaitable = $awaitable->catch(static fn($error) => $error->getMessage());

$result = $awaitable->await(); // 'Something went wrong!'
```

You can also iterate awaitables in completion order, regardless of the order they were started:

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;

$handles = [
    Async\run(static function () {
        Async\sleep(Duration::seconds(1));
        return 'a';
    }),
    Async\run(static fn() => 'b'),
    Async\run(static function () {
        Async\sleep(Duration::milliseconds(300));
        return 'c';
    }),
    Async\run(static function () {
        Async\sleep(Duration::milliseconds(100));
        return 'd';
    }),
];

foreach (Async\Awaitable::iterate($handles) as $k => $awaitable) {
    $result = $awaitable->await();
    IO\write_line($k . ': ' . $result);
}

// Output:
// 1: b
// 3: d
// 2: c
// 0: a
```

## Combinators

### concurrently -- Run Tasks in Parallel

Runs all closures concurrently and returns their results in the original order:

```php
use Psl\Async;
use Psl\DateTime\Duration;

$results = Async\concurrently([
    static function (): string {
        Async\sleep(Duration::milliseconds(100));
        return 'users created';
    },
    static function (): string {
        Async\sleep(Duration::milliseconds(50));
        return 'organizations created';
    },
    static function (): string {
        Async\sleep(Duration::milliseconds(75));
        return 'roles created';
    },
]);
```

> **Warning**
>
> `concurrently(...)` is about kicking-off I/O functions concurrently, not about concurrent execution of code.
> If your functions do not use any timers or perform any non-blocking I/O, they will actually be executed in series.

Use `Psl\Result\reflect(...)` to continue execution even when individual tasks fail:

```php
use Psl\Async;
use Psl\Result;
use Psl\Shell;

[$version, $foo] = Async\concurrently([
    Result\reflect(static fn() => Shell\execute('php', ['-v'])),
    Result\reflect(static fn() => Shell\execute('php', ['-r', 'foo();'])),
]);

// $version->isSucceeded() === true
// $foo->isFailed() === true
```

### series -- Run Tasks Sequentially

Runs closures one after another. If any throws, execution stops:

```php
use Psl\Async;
use Psl\DateTime\Duration;

$results = Async\series([
    static function (): string {
        Async\sleep(Duration::milliseconds(50));
        return 'users created';
    },
    static function (): string {
        Async\sleep(Duration::milliseconds(50));
        return 'organizations created';
    },
    static function (): string {
        Async\sleep(Duration::milliseconds(50));
        return 'roles created';
    },
    static function (): string {
        Async\sleep(Duration::milliseconds(50));
        return 'user organization roles created';
    },
]);
```

### all -- Await Multiple Awaitables

Waits for all `Awaitable`s to complete. If multiple fail, throws `CompositeException`:

```php
use Psl\Async;
use Psl\Shell;

Async\all([
    Async\run(static fn() => Shell\execute('echo', ['tests passed'])),
    Async\run(static fn() => Shell\execute('echo', ['analysis passed'])),
    Async\run(static fn() => Shell\execute('echo', ['formatting ok'])),
]);
```

### any / first -- Race Awaitables

`any()` returns the first successful result. `first()` returns the first completed result regardless of success or failure:

```php
use Psl\Async;

// Returns 'hello' -- the first successful result
$result = Async\any([
    Async\Awaitable::error(new Exception('failed')),
    Async\Awaitable::complete('hello'),
]);
```

### sleep / later

`Async\sleep()` provides a non-blocking sleep. Multiple concurrent sleeps run in parallel:

```php
use Psl\Async;
use Psl\DateTime\Duration;

$time = time();

Async\concurrently([
    static fn() => Async\sleep(Duration::seconds(2)),
    static fn() => Async\sleep(Duration::seconds(2)),
    static fn() => Async\sleep(Duration::seconds(2)),
]);

// Total time: ~2 seconds, not 6
```

`sleep()` also accepts a `CancellationTokenInterface` to wake early, which is useful for interruptible retry delays:

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;

$token = new Async\SignalCancellationToken();

// Cancel the sleep after 50ms
Async\Scheduler::delay(Duration::milliseconds(50), static fn(string $_) => $token->cancel());

try {
    // Sleep for 5 seconds, but wake early if the token is cancelled
    Async\sleep(Duration::seconds(5), $token);
} catch (Async\Exception\CancelledException) {
    IO\write_line('Woke early!');
}
```

`Async\later()` reschedules the current fiber, allowing other pending callbacks to execute.

## Concurrency Control

### Semaphore

Limits the number of concurrent operations. All operations use the same processing function:

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;

$semaphore = new Async\Semaphore(2, static function (int $input): void {
    IO\write_error_line('> started : %d', $input);
    Async\sleep(Duration::seconds(1));
    IO\write_error_line('> finished: %d', $input);
});

Async\concurrently([
    fn() => $semaphore->waitFor(1),
    fn() => $semaphore->waitFor(2),
    fn() => $semaphore->waitFor(3),
]);

// Output:
// > started: 1
// > started: 2
// > finished: 1
// > started: 3
// > finished: 2
// > finished: 3

$semaphore->cancel(new Exception('shutting down'));
```

The semaphore provides methods to inspect state (`getPendingOperations()`, `getOngoingOperations()`, `hasPendingOperations()`) and to cancel pending work. Both `waitFor()` and `waitForPending()` accept an optional `CancellationTokenInterface`.

### KeyedSemaphore

Like `Semaphore`, but applies concurrency limits per key. This is useful when you want to limit concurrent access to individual resources:

```php
use Psl\Async;
use Psl\DateTime\Duration;

$semaphore = new Async\KeyedSemaphore(2, static function (string $_key, int $_input): void {
    Async\sleep(Duration::seconds(1));
});

Async\concurrently([
    fn() => $semaphore->waitFor('foo', 1), // starts immediately
    fn() => $semaphore->waitFor('foo', 2), // starts immediately (limit 2 for 'foo')
    fn() => $semaphore->waitFor('foo', 3), // waits for one 'foo' to finish
    fn() => $semaphore->waitFor('bar', 1), // starts immediately (separate key)
]);
```

Both `waitFor()` and `waitForPending()` accept an optional `CancellationTokenInterface`.

### Sequence

A specialized semaphore with a concurrency limit of 1 -- operations run one at a time:

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;

$sequence = new Async\Sequence(static function (int $input): void {
    IO\write_error_line('> started : %d', $input);
    Async\sleep(Duration::seconds(1));
    IO\write_error_line('> finished: %d', $input);
});

Async\concurrently([
    fn() => $sequence->waitFor(1),
    fn() => $sequence->waitFor(2),
    fn() => $sequence->waitFor(3),
]);

// Output:
// > started: 1
// > finished: 1
// > started: 2
// > finished: 2
// > started: 3
// > finished: 3
```

Both `waitFor()` and `waitForPending()` accept an optional `CancellationTokenInterface`.

### KeyedSequence

Like `Sequence`, but applies the sequential constraint per key. Different keys can run concurrently while the same key is serialized:

```php
use Psl\Async;
use Psl\DateTime\Duration;

$sequence = new Async\KeyedSequence(static function (string $_key, int $_input): void {
    Async\sleep(Duration::seconds(1));
});

Async\concurrently([
    fn() => $sequence->waitFor('foo', 1), // starts immediately
    fn() => $sequence->waitFor('foo', 2), // waits for foo:1
    fn() => $sequence->waitFor('bar', 1), // starts immediately (different key)
]);
```

Both `waitFor()` and `waitForPending()` accept an optional `CancellationTokenInterface`.

### TaskGroup

`TaskGroup` lets you defer multiple closures for concurrent execution and await them all at once. If any task throws, the exception is propagated after all tasks finish. If multiple tasks throw, a `CompositeException` is raised:

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;

$results = [];
$group = new Async\TaskGroup();

$group->defer(static function () use (&$results): void {
    Async\sleep(Duration::milliseconds(20));
    $results[] = 'slow';
});

$group->defer(static function () use (&$results): void {
    $results[] = 'fast';
});

$group->awaitAll();

IO\write_line('Order: %s', Psl\Str\join($results, ', ')); // "fast, slow"
```

`awaitAll()` accepts an optional `CancellationTokenInterface`. The task list is cleared after each `awaitAll()` call, so the group is reusable.

### WaitGroup

`WaitGroup` is a counter-based synchronization primitive inspired by Go's `sync.WaitGroup`. Call `add()` before starting work, `done()` when work completes, and `wait()` to block until the counter reaches zero:

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;

$wg = new Async\WaitGroup();

for ($i = 0; $i < 3; $i++) {
    $wg->add();
    Async\run(static function () use ($wg, $i): void {
        Async\sleep(Duration::milliseconds(10 * ($i + 1)));
        IO\write_line('Task %d done', $i);
        $wg->done();
    })->ignore();
}

$wg->wait();
IO\write_line('All tasks done');
```

`wait()` accepts an optional `CancellationTokenInterface`. Multiple fibers can wait on the same `WaitGroup` concurrently.

## Deferred

> **Warning**
>
> The `Deferred` API is an advanced API that many applications probably don't need.
> Use `run(...)` and other combinators when possible.

`Deferred` is the low-level primitive for resolving future values. It produces an `Awaitable` that is completed manually:

```php
use Psl\Async;
use Psl\DateTime\Duration;

/**
 * @return Async\Awaitable<'hello'>
 */
function get_message(): Async\Awaitable
{
    /** @var Async\Deferred<'hello'> $deferred */
    $deferred = new Async\Deferred();

    // Complete the deferred with 'hello' after 2 seconds.
    Async\Scheduler::delay(Duration::seconds(2), static fn() => $deferred->complete('hello'));

    return $deferred->getAwaitable();
}

get_message()->await(); // 'hello'
```

The `Deferred` and `Awaitable` are intentionally separated: always return `$deferred->getAwaitable()` to API consumers. If you're passing `Deferred` objects around, you're probably doing something wrong.

## Scheduler

The `Scheduler` is a wrapper around the Revolt event loop. It provides static methods for registering callbacks:

- `Scheduler::defer($callback)` -- execute on next tick
- `Scheduler::delay($duration, $callback)` -- execute after a delay
- `Scheduler::repeat($interval, $callback)` -- execute repeatedly
- `Scheduler::onSignal($signal, $callback)` -- execute on OS signal
- `Scheduler::onReadable($stream, $callback)` -- execute when stream is readable
- `Scheduler::onWritable($stream, $callback)` -- execute when stream is writable
- `Scheduler::queue($callback)` -- queue a microtask

All registration methods return a string identifier that can be used with `cancel()`, `enable()`, `disable()`, `reference()`, and `unreference()`.

See [revolt.run](https://revolt.run/) for more information on the underlying event loop.

## Cancellation

Cancellation tokens allow you to cancel in-flight async operations from external code. Every suspension point in PSL (`await()`, `read()`, `write()`, `waitFor()`, `connect()`, etc.) accepts an optional `CancellationTokenInterface`.

### CancellationTokenInterface

The base interface. Implementations provide:

- `subscribe(Closure $callback): string` -- register a callback invoked on cancellation
- `unsubscribe(string $id): void` -- remove a callback
- `isCancelled(): bool` -- check cancellation state
- `throwIfCancelled(): void` -- throw `CancelledException` if cancelled

### SignalCancellationToken

Manually triggered. Call `cancel()` from any fiber to cancel all subscribed operations:

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;

$token = new Async\SignalCancellationToken();

// Simulate cancelling after 50ms
Async\Scheduler::delay(Duration::milliseconds(50), static fn() => $token->cancel(
    new RuntimeException('Client disconnected'),
));

$deferred = new Async\Deferred();

try {
    // This will be cancelled before the deferred completes
    $deferred->getAwaitable()->await($token);
} catch (Async\Exception\CancelledException $e) {
    IO\write_line('Cancelled: %s', $e->getPrevious()?->getMessage() ?? 'no cause');
    IO\write_line('Token type: %s', $e->getToken()::class);
}
```

### TimeoutCancellationToken

Auto-cancels after a duration. Replaces the old `Duration $timeout` pattern:

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;

// TimeoutCancellationToken auto-cancels after the given duration
$token = new Async\TimeoutCancellationToken(Duration::milliseconds(50));

$deferred = new Async\Deferred();

// Keep the loop alive
Async\run(static function () use ($deferred): void {
    Async\sleep(Duration::seconds(5));
    $deferred->complete('too late');
})->ignore();

try {
    $deferred->getAwaitable()->await($token);
} catch (Async\Exception\CancelledException $e) {
    $cause = $e->getPrevious();
    IO\write_line('Timed out: %s', $cause !== null ? $cause::class : 'unknown'); // Psl\Async\Exception\TimeoutException
}
```

### LinkedCancellationToken

Combines two tokens, cancelled when either fires. This is useful for layering a request-scoped token with an operation-specific timeout:

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;

// Simulate a request-scoped token that cancels when the client disconnects
$requestToken = new Async\SignalCancellationToken();

// Combine with a per-operation timeout
$linked = new Async\LinkedCancellationToken(
    $requestToken,
    new Async\TimeoutCancellationToken(Duration::milliseconds(50)),
);

$deferred = new Async\Deferred();

Async\run(static function () use ($deferred): void {
    Async\sleep(Duration::seconds(5));
    $deferred->complete('too late');
})->ignore();

try {
    // Cancelled by whichever fires first
    $deferred->getAwaitable()->await($linked);
} catch (Async\Exception\CancelledException $e) {
    $cause = $e->getToken();
    IO\write_line('Cancelled by: %s', $cause::class);
}
```

The `CancelledException` thrown by a linked token is forwarded directly from the inner token that fired -- `getToken()` returns the actual token that triggered cancellation (e.g., the `TimeoutCancellationToken` or `SignalCancellationToken`), not the linked wrapper.

### NullCancellationToken

A no-op token that is never cancelled. Used as the default parameter value -- you never need to construct it explicitly.

### Cancelling Semaphore/Sequence Waits

All concurrency primitives accept cancellation tokens. If a token fires while waiting for a slot, the wait is cancelled without affecting other pending operations:

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;

$semaphore = new Async\Semaphore(1, static function (string $task): string {
    Async\sleep(Duration::milliseconds(100));

    return $task . ' done';
});

// First task starts immediately
Async\run(static function () use ($semaphore): void {
    $result = $semaphore->waitFor('task-1');
    IO\write_line($result);
})->ignore();

// Second task must wait - but we cancel it after 50ms
$token = new Async\TimeoutCancellationToken(Duration::milliseconds(50));

try {
    $semaphore->waitFor('task-2', $token);
} catch (Async\Exception\CancelledException) {
    IO\write_line('task-2 cancelled while waiting for semaphore slot');
}
```

### CancelledException

When a token fires, `CancelledException` is thrown. It carries:

- `getPrevious()` -- the cause (e.g., `TimeoutException` for timeout tokens, or a custom exception passed to `SignalCancellationToken::cancel()`)
- `getToken()` -- the token that triggered the cancellation

## Error Handling

- **`CancelledException`** -- thrown when a cancellation token is triggered. Use `$e->getToken()` to identify the source and `$e->getPrevious()` for the cause.
- **`CompositeException`** -- wraps multiple exceptions when several concurrent operations fail. Use `$e->getReasons()` to get all underlying exceptions.
- **`TimeoutException`** -- used internally as the cause inside `CancelledException` when a `TimeoutCancellationToken` fires.
- **`UnhandledAwaitableException`** -- thrown by the scheduler when a failed `Awaitable` is never awaited or handled. Use `$awaitable->ignore()` to suppress this.

See [src/Psl/Async/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/async/src/Psl/Async/) for the full API.
