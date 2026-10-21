<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Revolt\EventLoop;

use function array_diff;
use function array_values;
use function gc_collect_cycles;
use function is_resource;
use function str_repeat;
use function strlen;
use function substr;

/**
 * A stream handle that is no longer referenced is destroyed, and closes its stream, at once; one that an
 * operation is waiting on stays alive.
 */
final class HandleLifetimeTest extends TestCase
{
    public function testDroppedHandleIsDestroyed(): void
    {
        $callbacks = EventLoop::getIdentifiers();

        [$read, $write] = IO\pipe();
        $readStream = $read->getStream();
        $writeStream = $write->getStream();
        unset($read, $write);

        // The event loop kept every handle alive through its watcher callbacks, so the pipe stayed open.
        static::assertFalse(is_resource($readStream));
        static::assertFalse(is_resource($writeStream));
        static::assertSame([], array_values(array_diff(EventLoop::getIdentifiers(), $callbacks)));
    }

    public function testDroppedHandleIsDestroyedAfterWaiting(): void
    {
        $callbacks = EventLoop::getIdentifiers();

        [$read, $write] = IO\pipe();
        $readStream = $read->getStream();
        $writeStream = $write->getStream();

        // Make both handles wait once, which enables their watchers.
        $writer = Async\run(static function () use ($write): void {
            $write->writeAll(str_repeat('a', 1024 * 1024));
        });

        $received = 0;
        while ($received < (1024 * 1024)) {
            $received += strlen($read->read());
        }

        $writer->await();

        Async\run(static function () use ($write): void {
            Async\later();
            $write->writeAll('b');
        })->ignore();

        static::assertSame('b', $read->read());

        unset($read, $write, $writer);
        Async\later();

        static::assertFalse(is_resource($readStream));
        static::assertFalse(is_resource($writeStream));
        static::assertSame([], array_values(array_diff(EventLoop::getIdentifiers(), $callbacks)));
    }

    public function testWaitingReadKeepsHandleAlive(): void
    {
        [$read, $write] = IO\pipe();
        $pending = Async\run($read->read(...));
        unset($read);

        // Once read() waits, the handle is only referenced by the waiting fiber, which is itself only
        // referenced through the handle's waiting suspension. The event loop must keep both alive until data
        // comes in.
        Async\later();
        gc_collect_cycles();

        $write->writeAll('ping');
        static::assertSame('ping', $pending->await(self::timeout()));

        $write->close();
    }

    public function testWaitingWriteKeepsHandleAlive(): void
    {
        [$read, $write] = IO\pipe();

        // Fill the pipe, so that the next write waits.
        $filled = 0;
        do {
            $written = $write->tryWrite(str_repeat('a', 65_536));
            $filled += $written;
        } while (0 !== $written);

        $pending = Async\run(static function () use ($write): void {
            $write->writeAll('ping');
        });
        unset($write);

        Async\later();
        gc_collect_cycles();

        $drained = '';
        while (strlen($drained) < ($filled + 4)) {
            $chunk = $read->read(null, self::timeout());
            if ('' === $chunk) {
                static::fail('The pipe was closed before the waiting write completed.');
            }

            $drained .= $chunk;
        }

        $pending->await(self::timeout());
        static::assertSame('ping', substr($drained, -4));

        $read->close();
    }

    /**
     * Bounds each wait, so that a fiber destroyed while it waits fails the test instead of hanging it.
     */
    private static function timeout(): Async\TimeoutCancellationToken
    {
        return new Async\TimeoutCancellationToken(Duration::seconds(5));
    }
}
