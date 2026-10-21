<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\IO;
use Revolt\EventLoop;

use function array_filter;
use function array_values;
use function str_repeat;
use function strlen;

/**
 * A wait of a stream handle settles once, and its cancellation listener reaches only that wait: not before the
 * wait has suspended, and not a later wait of the same fiber, which the event loop hands the same suspension.
 */
final class WaitCancellationTest extends TestCase
{
    public function testReadFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        [$read, $write] = IO\pipe();
        $token = new ExpiringCancellationToken();
        $callbacks = self::enabledCallbacks();

        $failure = self::readFailure($read, $token);

        static::assertSame($token->exception, $failure);
        static::assertSame($callbacks, self::enabledCallbacks());

        $write->writeAll('hello');
        static::assertSame('hello', $read->read());
    }

    public function testReadFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        [$read, $write] = IO\pipe();
        $token = new InlineCancellationToken();
        $callbacks = self::enabledCallbacks();

        $failure = self::readFailure($read, $token);

        static::assertSame($token->exception, $failure);
        static::assertSame($callbacks, self::enabledCallbacks());

        $write->writeAll('hello');
        static::assertSame('hello', $read->read());
    }

    public function testLateCancellationOfAnEarlierReadDoesNotEndALaterRead(): void
    {
        [$read, $write] = IO\pipe();
        $token = new DeferredCancellationToken();
        $firstRead = new Async\Deferred();

        $reader = Async\run(static function () use ($read, $token, $firstRead): string {
            $first = $read->read(null, $token);
            $firstRead->complete($first);

            // A second wait of the same fiber, which does not use the token.
            return $read->read();
        });

        // The reader waits in its first read, subscribed to the token. The token is cancelled, but its listeners
        // are notified only later, after that read has returned the data written below.
        Async\later();
        $token->cancel();
        $write->writeAll('first');
        static::assertSame('first', $firstRead->getAwaitable()->await());

        // The reader now waits in its second read; the first read's listener must not end it.
        $token->deliver();
        $write->writeAll('second');

        static::assertSame('second', $reader->await());
    }

    public function testWriteFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        [$read, $write] = IO\pipe();
        $buffered = self::fill($write);
        $token = new ExpiringCancellationToken();
        $callbacks = self::enabledCallbacks();

        $failure = self::writeFailure($write, $token);

        static::assertSame($token->exception, $failure);
        static::assertSame($callbacks, self::enabledCallbacks());

        self::drain($read, $buffered);
        static::assertSame(5, $write->write('hello'));
        static::assertSame('hello', $read->read());
    }

    public function testWriteFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        [$read, $write] = IO\pipe();
        $buffered = self::fill($write);
        $token = new InlineCancellationToken();
        $callbacks = self::enabledCallbacks();

        $failure = self::writeFailure($write, $token);

        static::assertSame($token->exception, $failure);
        static::assertSame($callbacks, self::enabledCallbacks());

        self::drain($read, $buffered);
        static::assertSame(5, $write->write('hello'));
        static::assertSame('hello', $read->read());
    }

    public function testLateCancellationOfAnEarlierWriteDoesNotEndALaterWrite(): void
    {
        [$read, $write] = IO\pipe();
        $token = new DeferredCancellationToken();
        $firstWrite = new Async\Deferred();
        $buffered = self::fill($write);

        $writer = Async\run(static function () use ($write, $token, $firstWrite): int {
            $first = $write->write('first', $token);
            // Fill the stream again, so that the next write waits too.
            $firstWrite->complete([$first, self::fill($write)]);

            // A second wait of the same fiber, which does not use the token.
            return $write->write('second');
        });

        // The writer waits in its first write, subscribed to the token. The token is cancelled, but its listeners
        // are notified only later, after that write has gone through.
        Async\later();
        $token->cancel();
        self::drain($read, $buffered);
        [$first, $refilled] = $firstWrite->getAwaitable()->await();
        static::assertGreaterThan(0, $first);

        // The writer now waits in its second write; the first write's listener must not end it.
        $token->deliver();
        self::drain($read, $refilled);

        static::assertSame(6, $writer->await());
    }

    private static function readFailure(
        IO\ReadHandleInterface $handle,
        Async\CancellationTokenInterface $cancellation,
    ): Async\Exception\CancelledException {
        try {
            $handle->read(null, $cancellation);
        } catch (Async\Exception\CancelledException $e) {
            return $e;
        }

        static::fail('The read was not cancelled.');
    }

    private static function writeFailure(
        IO\WriteHandleInterface $handle,
        Async\CancellationTokenInterface $cancellation,
    ): Async\Exception\CancelledException {
        try {
            $handle->write('hello', $cancellation);
        } catch (Async\Exception\CancelledException $e) {
            return $e;
        }

        static::fail('The write was not cancelled.');
    }

    /**
     * @return list<string> The event loop callbacks that are enabled.
     */
    private static function enabledCallbacks(): array
    {
        return array_values(array_filter(EventLoop::getIdentifiers(), EventLoop::isEnabled(...)));
    }

    /**
     * Writes until the stream accepts nothing more, and returns how many bytes it accepted.
     */
    private static function fill(IO\WriteHandleInterface $handle): int
    {
        $chunk = str_repeat('a', 65_536);
        $total = 0;
        do {
            $written = $handle->tryWrite($chunk);
            $total += $written;
        } while ($written > 0);

        return $total;
    }

    /**
     * Reads `$length` bytes, and discards whatever else is already there.
     */
    private static function drain(IO\ReadHandleInterface $handle, int $length): void
    {
        $received = 0;
        while ($received < $length) {
            $received += strlen($handle->read());
        }

        do {
            $chunk = $handle->tryRead();
        } while ('' !== $chunk);
    }
}
