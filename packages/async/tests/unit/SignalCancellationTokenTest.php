<?php

declare(strict_types=1);

namespace Psl\Async\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use RuntimeException;

final class SignalCancellationTokenTest extends TestCase
{
    public function testIsNotCancelledByDefault(): void
    {
        $token = new Async\SignalCancellationToken();

        static::assertFalse($token->isCancelled());
    }

    public function testCancelMarksCancelled(): void
    {
        $token = new Async\SignalCancellationToken();

        $token->cancel();

        static::assertTrue($token->isCancelled());
    }

    public function testThrowIfCancelledThrowsAfterCancel(): void
    {
        $token = new Async\SignalCancellationToken();
        $token->cancel();

        $this->expectException(Async\Exception\CancelledException::class);

        $token->throwIfCancelled();
    }

    public function testThrowIfCancelledDoesNotThrowBeforeCancel(): void
    {
        $token = new Async\SignalCancellationToken();

        $token->throwIfCancelled();

        static::assertFalse($token->isCancelled());
    }

    public function testCancelInvokesSubscribers(): void
    {
        $token = new Async\SignalCancellationToken();
        $called = false;

        $token->subscribe(static function (Async\Exception\CancelledException $e) use (&$called): void {
            $called = true;
        });

        $token->cancel();

        static::assertTrue($called);
    }

    public function testCancelWithCauseSetsPreviousException(): void
    {
        $token = new Async\SignalCancellationToken();
        $cause = new RuntimeException('connection lost');
        $received = null;

        $token->subscribe(static function (Async\Exception\CancelledException $e) use (&$received): void {
            $received = $e;
        });

        $token->cancel($cause);

        static::assertNotNull($received);
        static::assertSame($cause, $received->getPrevious());
    }

    public function testUnsubscribePreventsCallback(): void
    {
        $token = new Async\SignalCancellationToken();
        $called = false;

        $id = $token->subscribe(static function (Async\Exception\CancelledException $e) use (&$called): void {
            $called = true;
        });

        $token->unsubscribe($id);
        $token->cancel();

        static::assertFalse($called);
    }

    public function testSubscribeAfterCancelInvokesImmediately(): void
    {
        $token = new Async\SignalCancellationToken();
        $token->cancel();

        $called = false;
        $token->subscribe(static function (Async\Exception\CancelledException $e) use (&$called): void {
            $called = true;
        });

        static::assertTrue($called);
    }

    public function testSubscribeAfterCancelReturnsNullId(): void
    {
        $token = new Async\SignalCancellationToken();
        $token->cancel();

        $id = $token->subscribe(static function (Async\Exception\CancelledException $e): void {});

        static::assertSame('null', $id);
    }

    public function testCancelTwiceIsNoOp(): void
    {
        $token = new Async\SignalCancellationToken();
        $count = 0;

        $token->subscribe(static function (Async\Exception\CancelledException $e) use (&$count): void {
            $count++;
        });

        $token->cancel();
        $token->cancel();

        static::assertSame(1, $count);
    }

    public function testMultipleSubscribers(): void
    {
        $token = new Async\SignalCancellationToken();
        $count = 0;

        $token->subscribe(static function (Async\Exception\CancelledException $e) use (&$count): void {
            $count++;
        });

        $token->subscribe(static function (Async\Exception\CancelledException $e) use (&$count): void {
            $count++;
        });

        $token->cancel();

        static::assertSame(2, $count);
    }

    public function testCancelWithoutCauseHasNoPrevious(): void
    {
        $token = new Async\SignalCancellationToken();
        $token->cancel();

        try {
            $token->throwIfCancelled();
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException $e) {
            static::assertNull($e->getPrevious());
        }
    }

    public function testGetTokenReturnsSelf(): void
    {
        $token = new Async\SignalCancellationToken();
        $token->cancel();

        try {
            $token->throwIfCancelled();
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException $e) {
            static::assertSame($token, $e->getToken());
        }
    }

    public function testSubscriberReceivesTokenReference(): void
    {
        $token = new Async\SignalCancellationToken();
        $received = null;

        $token->subscribe(static function (Async\Exception\CancelledException $e) use (&$received): void {
            $received = $e->getToken();
        });

        $token->cancel();

        static::assertSame($token, $received);
    }

    public function testCancelRunsEveryCallbackWhenOneThrows(): void
    {
        $token = new Async\SignalCancellationToken();
        $failure = new RuntimeException('second');
        $calls = [];

        $token->subscribe(static function () use (&$calls): void {
            $calls[] = 'first';
        });

        $token->subscribe(static function () use (&$calls, $failure): void {
            $calls[] = 'second';

            throw $failure;
        });

        $token->subscribe(static function () use (&$calls): void {
            $calls[] = 'third';
        });

        $thrown = null;
        try {
            $token->cancel();
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        static::assertSame($failure, $thrown);

        static::assertSame(['first', 'second', 'third'], $calls);
        static::assertTrue($token->isCancelled());
    }

    public function testCancelRethrowsTheFailuresOfSeveralCallbacksTogether(): void
    {
        $token = new Async\SignalCancellationToken();
        $first = new RuntimeException('first');
        $third = new RuntimeException('third');
        $calls = [];

        $token->subscribe(static function () use (&$calls, $first): void {
            $calls[] = 'first';

            throw $first;
        });

        $token->subscribe(static function () use (&$calls): void {
            $calls[] = 'second';
        });

        $token->subscribe(static function () use (&$calls, $third): void {
            $calls[] = 'third';

            throw $third;
        });

        try {
            $token->cancel();

            static::fail('Expected the failures of the first and third callbacks.');
        } catch (Async\Exception\CompositeException $exception) {
            static::assertSame([$first, $third], $exception->getReasons());
        }

        static::assertSame(['first', 'second', 'third'], $calls);
    }
}
