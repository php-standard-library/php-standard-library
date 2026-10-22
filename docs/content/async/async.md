# Async

The `Async` component brings concurrency into PHP using [cooperative multitasking](https://en.wikipedia.org/wiki/Cooperative_multitasking).

> **Note**
>
> The Async component is built on top of [RevoltPHP](https://github.com/revoltphp/event-loop), which makes it compatible with [Amphp](https://github.com/amphp),
> and other libraries that use the same event loop.

## Quick Start

@example('async/async-quickstart.php')

## Core Concepts

### Entry Point

`Async\main()` is the entry point for async applications. It executes a closure in the main fiber, then keeps the event loop running until all pending callbacks complete. The closure must return an integer exit code.

@example('async/async-main.php')

### Running Async Operations

`Async\run()` creates a new fiber and returns an `Awaitable` that resolves to its result:

@example('async/async-run.php')

### Awaitables

An `Awaitable` is a promise-like object representing a value that may not yet be available. It can be awaited, mapped, chained, and composed. The `await()` method accepts an optional `CancellationTokenInterface` to cancel the wait.

@example('async/async-awaitables.php')

Error handling works naturally with `catch()`:

@example('async/async-catch.php')

You can also iterate awaitables in completion order, regardless of the order they were started:

@example('async/async-iterate.php')

## Combinators

### concurrently -- Run Tasks in Parallel

Runs all closures concurrently and returns their results in the original order:

@example('async/async-concurrently.php')

No closure and no failure is dropped when the iterable repeats a key: each closure runs, and its failure is handled as it would be under a distinct key. On success, the result of the last closure with that key is returned under it.

> **Warning**
>
> `concurrently(...)` is about kicking-off I/O functions concurrently, not about concurrent execution of code.
> If your functions do not use any timers or perform any non-blocking I/O, they will actually be executed in series.

Use `Psl\Result\reflect(...)` to continue execution even when individual tasks fail:

@example('async/async-reflect.php')

### series -- Run Tasks Sequentially

Runs closures one after another. If any throws, execution stops:

@example('async/async-series.php')

### all -- Await Multiple Awaitables

Waits for all `Awaitable`s to complete. If multiple fail, throws `CompositeException`:

@example('async/async-all.php')

### any / first -- Race Awaitables

`any()` returns the first successful result. `first()` returns the first completed result regardless of success or failure:

@example('async/async-any.php')

### sleep / later

`Async\sleep()` provides a non-blocking sleep. Multiple concurrent sleeps run in parallel:

@example('async/async-sleep.php')

`sleep()` also accepts a `CancellationTokenInterface` to wake early, which is useful for interruptible retry delays:

@example('async/async-sleep-cancellation.php')

`Async\later()` reschedules the current fiber, allowing other pending callbacks to execute.

## Concurrency Control

### Semaphore

Limits the number of concurrent operations. All operations use the same processing function:

@example('async/async-semaphore.php')

The semaphore provides methods to inspect state (`getPendingOperations()`, `getOngoingOperations()`, `hasPendingOperations()`) and to cancel pending work. Both `waitFor()` and `waitForPending()` accept an optional `CancellationTokenInterface`.

### KeyedSemaphore

Like `Semaphore`, but applies concurrency limits per key. This is useful when you want to limit concurrent access to individual resources:

@example('async/async-keyed-semaphore.php')

Both `waitFor()` and `waitForPending()` accept an optional `CancellationTokenInterface`.

### Sequence

A specialized semaphore with a concurrency limit of 1 -- operations run one at a time:

@example('async/async-sequence.php')

Both `waitFor()` and `waitForPending()` accept an optional `CancellationTokenInterface`.

### KeyedSequence

Like `Sequence`, but applies the sequential constraint per key. Different keys can run concurrently while the same key is serialized:

@example('async/async-keyed-sequence.php')

Both `waitFor()` and `waitForPending()` accept an optional `CancellationTokenInterface`.

### TaskGroup

`TaskGroup` lets you defer multiple closures for concurrent execution and await them all at once. If any task throws, the exception is propagated after all tasks finish. If multiple tasks throw, a `CompositeException` is raised:

@example('async/async-task-group.php')

`awaitAll()` accepts an optional `CancellationTokenInterface`. The task list is cleared after each `awaitAll()` call, so the group is reusable.

Several fibers can call `awaitAll()` at once: every call waiting when the last task finishes gets the same exception (or the same `CompositeException`). Only exceptions are collected; an `Error` thrown by a task reaches the event loop error handler.

### WaitGroup

`WaitGroup` is a counter-based synchronization primitive inspired by Go's `sync.WaitGroup`. Call `add()` before starting work, `done()` when work completes, and `wait()` to block until the counter reaches zero:

@example('async/async-wait-group.php')

`wait()` accepts an optional `CancellationTokenInterface`. Multiple fibers can wait on the same `WaitGroup` concurrently.

## Deferred

> **Warning**
>
> The `Deferred` API is an advanced API that many applications probably don't need.
> Use `run(...)` and other combinators when possible.

`Deferred` is the low-level primitive for resolving future values. It produces an `Awaitable` that is completed manually:

@example('async/async-deferred.php')

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

@example('async/async-cancellation-signal.php')

### TimeoutCancellationToken

Auto-cancels after a duration. Replaces the old `Duration $timeout` pattern:

@example('async/async-cancellation-timeout.php')

### LinkedCancellationToken

Combines two tokens, cancelled when either fires. This is useful for layering a request-scoped token with an operation-specific timeout:

@example('async/async-cancellation-linked.php')

The `CancelledException` thrown by a linked token is forwarded directly from the inner token that fired -- `getToken()` returns the actual token that triggered cancellation (e.g., the `TimeoutCancellationToken` or `SignalCancellationToken`), not the linked wrapper.

### NullCancellationToken

A no-op token that is never cancelled. Used as the default parameter value -- you never need to construct it explicitly.

### Cancelling Semaphore/Sequence Waits

All concurrency primitives accept cancellation tokens. If a token fires while waiting for a slot, the wait is cancelled without affecting other pending operations:

@example('async/async-cancellation-semaphore.php')

### CancelledException

When a token fires, `CancelledException` is thrown. It carries:

- `getPrevious()` -- the cause (e.g., `TimeoutException` for timeout tokens, or a custom exception passed to `SignalCancellationToken::cancel()`)
- `getToken()` -- the token that triggered the cancellation

## Error Handling

- **`CancelledException`** -- thrown when a cancellation token is triggered. Use `$e->getToken()` to identify the source and `$e->getPrevious()` for the cause.
- **`CompositeException`** -- wraps multiple exceptions when several concurrent operations fail. Use `$e->getReasons()` to get all underlying exceptions.
- **`TimeoutException`** -- used internally as the cause inside `CancelledException` when a `TimeoutCancellationToken` fires.
- **`UnhandledAwaitableException`** -- thrown by the scheduler when a failed `Awaitable` is never awaited or handled. Use `$awaitable->ignore()` to suppress this.

See `src/Psl/Async/` for the full API.
