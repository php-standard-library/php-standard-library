<?php

declare(strict_types=1);

namespace Psl\Async\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl;
use Psl\Async;
use Psl\Async\Awaitable;
use Psl\Async\Exception\UnhandledAwaitableException;
use Psl\Async\Internal\State;
use Psl\DateTime;
use Psl\Dict;
use Psl\Exception\InvariantViolationException;
use Psl\Str;
use Revolt\EventLoop\UncaughtThrowable;
use RuntimeException;
use Throwable;
use WeakReference;

use function gc_collect_cycles;

final class AwaitableTest extends TestCase
{
    public function testCompleteAwait(): void
    {
        $state = new State();
        $awaitable = new Awaitable($state);

        static::assertFalse($awaitable->isComplete());
        $state->complete('hello');
        static::assertTrue($awaitable->isComplete());

        $result = $awaitable->await();

        static::assertSame('hello', $result);
    }

    public function testErroredAwait(): void
    {
        $state = new State();
        $awaitable = new Awaitable($state);

        static::assertFalse($awaitable->isComplete());
        $state->error(new InvariantViolationException('foo'));
        static::assertTrue($awaitable->isComplete());

        $this->expectException(InvariantViolationException::class);
        $this->expectExceptionMessage('foo');

        $awaitable->await();
    }

    public function testDiscardedAwaitableError(): void
    {
        $state = new State();
        $awaitable = new Awaitable($state);

        static::assertFalse($awaitable->isComplete());
        $state->error(new InvariantViolationException('foo'));
        static::assertTrue($awaitable->isComplete());

        try {
            unset($awaitable, $state);

            Async\Scheduler::run();

            static::fail('Expected exception to be thrown');
        } catch (UncaughtThrowable $throwable) {
            $previous = $throwable->getPrevious();
            static::assertInstanceOf(UnhandledAwaitableException::class, $previous);
            static::assertSame(
                'Unhandled awaitable error "Psl\Exception\InvariantViolationException", make sure to call `Awaitable::await()` before the awaitable is destroyed, or call `Awaitable::ignore()` to ignore exceptions.',
                $previous->getMessage(),
            );
        }
    }

    public function testDiscardedIgnoredAwaitableError(): void
    {
        $state = new State();
        $awaitable = new Awaitable($state);

        static::assertFalse($awaitable->isComplete());
        $state->error(new InvariantViolationException('foo'));
        static::assertTrue($awaitable->isComplete());

        $awaitable->ignore();

        unset($awaitable, $state);

        Async\later();
    }

    public function testIterate(): void
    {
        $iterator = Awaitable::iterate([
            'foo' => Awaitable::complete('foo'),
            'bar' => Awaitable::error(new InvariantViolationException('bar')),
            'baz' => Async\run(static function (): never {
                Async\sleep(DateTime\Duration::milliseconds(1));

                throw new InvariantViolationException('baz');
            }),
            'qux' => Async\run(static function (): string {
                Async\sleep(DateTime\Duration::milliseconds(30));

                return 'qux';
            }),
        ]);

        static::assertSame('foo', $iterator->key());
        static::assertSame('foo', $iterator->current()->await());

        $iterator->next();

        static::assertSame('bar', $iterator->key());

        try {
            $iterator->current()->await();
            static::fail();
        } catch (InvariantViolationException) {
            $this->addToAssertionCount(1);
        }

        $iterator->next();

        static::assertSame('baz', $iterator->key());

        try {
            $iterator->current()->await();
            static::fail();
        } catch (InvariantViolationException) {
            $this->addToAssertionCount(1);
        }

        $iterator->next();

        static::assertSame('qux', $iterator->key());
        static::assertSame('qux', $iterator->current()->await());
    }

    public function testIterateGenerator(): void
    {
        $generator1 = Async\run(static function (): iterable {
            yield 'foo' => 'foo';

            Async\sleep(DateTime\Duration::milliseconds(3));

            yield 'bar' => 'bar';
        });

        $generator2 = Async\run(static function (): iterable {
            yield 'baz' => 'baz';

            Async\sleep(DateTime\Duration::milliseconds(1));

            yield 'qux' => 'qux';
        });

        $generator3 = Async\run(static function () use ($generator1, $generator2): iterable {
            yield 'gen1' => $generator1;

            Async\sleep(DateTime\Duration::milliseconds(2));

            yield 'gen2' => $generator2;
        })->await();

        $values = [];
        // Awaitable::iterate() to throw the first error based on completion order instead of argument order
        foreach (Awaitable::iterate($generator3) as $index => $awaitable) {
            $values[$index] = $awaitable->await();
        }

        static::assertArrayHasKey('gen1', $values);
        static::assertArrayHasKey('gen2', $values);

        $values['gen1'] = Dict\from_iterable($values['gen1']);
        $values['gen2'] = Dict\from_iterable($values['gen2']);

        static::assertSame(['foo' => 'foo', 'bar' => 'bar'], $values['gen1']);
        static::assertSame(['baz' => 'baz', 'qux' => 'qux'], $values['gen2']);
    }

    public function testThenOnSuccess(): void
    {
        $awaitable = Async\run(static fn(): string => 'hello');

        $awaitable = $awaitable
            ->then(Str\reverse(...), static fn(Throwable $_): never => exit(0))
            ->then(
                static fn(string $result): never => throw new InvariantViolationException($result),
                static fn(Throwable $_): never => exit(0),
            )
            ->then(static fn(mixed $_): never => exit(0), static fn(Throwable $exception): never => throw $exception)
            ->then(
                static fn(mixed $_): never => exit(0),
                static fn(Throwable $exception): string => $exception->getMessage(),
            );

        static::assertSame('olleh', $awaitable->await());
    }

    public function testMap(): void
    {
        $awaitable = Async\run(static fn(): string => 'hello');

        $ref = new Psl\Ref('');
        $awaitable = $awaitable
            ->map(Str\reverse(...))
            ->map(static fn(string $result): never => throw new InvariantViolationException($result))
            ->catch(static fn(InvariantViolationException $exception): string => $exception->getMessage())
            ->always(static fn(): string => $ref->value = 'hello');

        static::assertSame('olleh', $awaitable->await());
        static::assertSame('hello', $ref->value);
    }

    public function testAwaitWithCancellationTokenCompletes(): void
    {
        $awaitable = Async\run(static fn(): string => 'hello');
        $token = new Async\TimeoutCancellationToken(DateTime\Duration::seconds(5));

        static::assertSame('hello', $awaitable->await($token));
    }

    public function testAwaitThrowsCancelledExceptionOnTimeout(): void
    {
        $this->expectException(Async\Exception\CancelledException::class);

        Async\run(static function (): void {
            $deferred = new Async\Deferred();
            $token = new Async\TimeoutCancellationToken(DateTime\Duration::milliseconds(10));

            // Keep the loop alive long enough for the timeout to fire
            Async\run(static function () use ($deferred): void {
                Async\sleep(DateTime\Duration::seconds(5));
                $deferred->complete(null);
            })->ignore();

            $deferred->getAwaitable()->await($token);
        })->await();
    }

    public function testAwaitThrowsCancelledExceptionOnSignal(): void
    {
        $this->expectException(Async\Exception\CancelledException::class);

        Async\run(static function (): void {
            $deferred = new Async\Deferred();
            $token = new Async\SignalCancellationToken();

            Async\Scheduler::delay(DateTime\Duration::milliseconds(10), static fn(string $_) => $token->cancel());

            $deferred->getAwaitable()->await($token);
        })->await();
    }

    public function testAwaitWithAlreadyCancelledToken(): void
    {
        $token = new Async\SignalCancellationToken();
        $token->cancel();

        $awaitable = Awaitable::complete('hello');

        $this->expectException(Async\Exception\CancelledException::class);

        $awaitable->await($token);
    }

    public function testAlwaysWithFailedAwaitable(): void
    {
        $called = false;
        $awaitable = Awaitable::error(new RuntimeException('fail'));
        $result = $awaitable->always(static function () use (&$called): void {
            $called = true;
        });

        try {
            $result->await();
            static::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            static::assertSame('fail', $e->getMessage());
        }

        static::assertTrue($called);
    }

    public function testAwaitUnsubscribesOnCompletion(): void
    {
        $result = Async\run(static function (): string {
            $token = new Async\SignalCancellationToken();
            $awaitable = Async\run(static fn(): string => 'done');

            $value = $awaitable->await($token);

            static::assertFalse($token->isCancelled());

            return $value;
        })->await();

        static::assertSame('done', $result);
    }

    public function testCancelledAwaitDoesNotKeepTheTokenAlive(): void
    {
        $deferred = new Async\Deferred();
        $token = new Async\SignalCancellationToken();
        $reference = WeakReference::create($token);

        $waiter = Async\run(static function () use ($deferred, $token): bool {
            try {
                $deferred->getAwaitable()->await($token);
            } catch (Async\Exception\CancelledException) {
                return true;
            }

            return false;
        });

        Async\later();
        $token->cancel();

        static::assertTrue($waiter->await());

        unset($token, $waiter);
        gc_collect_cycles();

        // The operation is still pending, and it no longer holds the callback of the cancelled wait.
        static::assertFalse($deferred->isComplete());
        static::assertNull($reference->get());
    }

    public function testCancelledAwaitKeepsTheLaterFailureHandled(): void
    {
        $deferred = new Async\Deferred();
        $token = new Async\SignalCancellationToken();

        $waiter = Async\run(static function () use ($deferred, $token): void {
            try {
                $deferred->getAwaitable()->await($token);
            } catch (Async\Exception\CancelledException) {
                $deferred->error(new RuntimeException('failed after the wait was cancelled'));
            }
        });

        Async\later();
        $token->cancel();
        $waiter->await();

        unset($deferred, $waiter);
        gc_collect_cycles();

        // As before, a wait counts as handling the awaitable's failure, even once cancelled: nothing reaches
        // the event loop.
        Async\later();

        static::addToAssertionCount(1);
    }
}
