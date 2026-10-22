<?php

declare(strict_types=1);

namespace Psl\Async\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use RuntimeException;

use function gc_collect_cycles;

final class LinkedCancellationTokenTest extends TestCase
{
    public function testIsNotCancelledByDefault(): void
    {
        $a = new Async\SignalCancellationToken();
        $b = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($a, $b);

        static::assertFalse($linked->isCancelled());
    }

    public function testCancelledWhenFirstTokenCancels(): void
    {
        $a = new Async\SignalCancellationToken();
        $b = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($a, $b);

        $a->cancel();

        static::assertTrue($linked->isCancelled());
        static::assertFalse($b->isCancelled());
    }

    public function testCancelledWhenSecondTokenCancels(): void
    {
        $a = new Async\SignalCancellationToken();
        $b = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($a, $b);

        $b->cancel();

        static::assertTrue($linked->isCancelled());
        static::assertFalse($a->isCancelled());
    }

    public function testThrowIfCancelledThrowsAfterCancel(): void
    {
        $a = new Async\SignalCancellationToken();
        $b = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($a, $b);

        $a->cancel();

        $this->expectException(Async\Exception\CancelledException::class);

        $linked->throwIfCancelled();
    }

    public function testThrowIfCancelledDoesNotThrowBeforeCancel(): void
    {
        $a = new Async\SignalCancellationToken();
        $b = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($a, $b);

        $linked->throwIfCancelled();

        static::assertFalse($linked->isCancelled());
    }

    public function testSubscriberCalledOnCancel(): void
    {
        $a = new Async\SignalCancellationToken();
        $b = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($a, $b);

        $called = false;
        $linked->subscribe(static function (Async\Exception\CancelledException $e) use (&$called): void {
            $called = true;
        });

        $a->cancel();

        static::assertTrue($called);
    }

    public function testUnsubscribePreventsCallback(): void
    {
        $a = new Async\SignalCancellationToken();
        $b = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($a, $b);

        $called = false;
        $id = $linked->subscribe(static function (Async\Exception\CancelledException $e) use (&$called): void {
            $called = true;
        });

        $linked->unsubscribe($id);
        $a->cancel();

        static::assertFalse($called);
    }

    public function testGetTokenReturnsInnerTokenThatFired(): void
    {
        $a = new Async\SignalCancellationToken();
        $b = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($a, $b);

        $a->cancel();

        try {
            $linked->throwIfCancelled();
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException $e) {
            static::assertSame($a, $e->getToken());
        }
    }

    public function testGetTokenReturnsSecondInnerTokenWhenItFires(): void
    {
        $a = new Async\SignalCancellationToken();
        $b = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($a, $b);

        $b->cancel();

        try {
            $linked->throwIfCancelled();
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException $e) {
            static::assertSame($b, $e->getToken());
        }
    }

    public function testCauseIsPreserved(): void
    {
        $a = new Async\SignalCancellationToken();
        $b = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($a, $b);

        $cause = new RuntimeException('connection lost');
        $a->cancel($cause);

        try {
            $linked->throwIfCancelled();
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException $e) {
            static::assertSame($cause, $e->getPrevious());
        }
    }

    public function testSecondCancelIsIgnored(): void
    {
        $a = new Async\SignalCancellationToken();
        $b = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($a, $b);

        $count = 0;
        $linked->subscribe(static function (Async\Exception\CancelledException $e) use (&$count): void {
            $count++;
        });

        $a->cancel();
        $b->cancel();

        static::assertSame(1, $count);
    }

    public function testSubscribeAfterCancelInvokesImmediately(): void
    {
        $a = new Async\SignalCancellationToken();
        $b = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($a, $b);

        $a->cancel();

        $called = false;
        $id = $linked->subscribe(static function (Async\Exception\CancelledException $e) use (&$called): void {
            $called = true;
        });

        static::assertTrue($called);
        static::assertSame('null', $id);
    }

    public function testLinkedWithTimeoutToken(): void
    {
        $result = Async\run(static function (): bool {
            $signal = new Async\SignalCancellationToken();
            $timeout = new Async\TimeoutCancellationToken(Duration::milliseconds(10));
            $linked = new Async\LinkedCancellationToken($signal, $timeout);

            Async\sleep(Duration::milliseconds(50));

            return $linked->isCancelled();
        })->await();

        static::assertTrue($result);
    }

    public function testLinkedWithAlreadyCancelledToken(): void
    {
        $a = new Async\SignalCancellationToken();
        $a->cancel();

        $b = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($a, $b);

        static::assertTrue($linked->isCancelled());
    }

    public function testAwaitableWithLinkedToken(): void
    {
        $this->expectException(Async\Exception\CancelledException::class);

        Async\run(static function (): void {
            $signal = new Async\SignalCancellationToken();
            $timeout = new Async\TimeoutCancellationToken(Duration::milliseconds(10));
            $linked = new Async\LinkedCancellationToken($signal, $timeout);

            $deferred = new Async\Deferred();

            Async\run(static function () use ($deferred): void {
                Async\sleep(Duration::seconds(5));
                $deferred->complete(null);
            })->ignore();

            $deferred->getAwaitable()->await($linked);
        })->await();
    }

    public function testWeakReferenceDroppedBeforeInnerCancels(): void
    {
        $a = new Async\SignalCancellationToken();
        $b = new Async\SignalCancellationToken();

        $linked = new Async\LinkedCancellationToken($a, $b);
        unset($linked);

        gc_collect_cycles();

        // Cancel inner token after linked is gone
        // The handler should see null from WeakReference::get() and no-op
        $a->cancel();

        // If we got here without error, the WeakReference null check worked
        static::addToAssertionCount(1);
    }

    public function testLinkedRunsEveryCallbackWhenOneThrows(): void
    {
        $signal = new Async\SignalCancellationToken();
        $linked = new Async\LinkedCancellationToken($signal, new Async\SignalCancellationToken());
        $failure = new RuntimeException('first');
        $calls = [];

        $linked->subscribe(static function () use (&$calls, $failure): void {
            $calls[] = 'first';

            throw $failure;
        });

        $linked->subscribe(static function () use (&$calls): void {
            $calls[] = 'second';
        });

        $thrown = null;
        try {
            $signal->cancel();
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        static::assertSame($failure, $thrown);

        static::assertSame(['first', 'second'], $calls);
        static::assertTrue($linked->isCancelled());
    }
}
