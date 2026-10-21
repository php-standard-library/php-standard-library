<?php

declare(strict_types=1);

namespace Psl\Network\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\IO;
use Psl\Network;
use Revolt\EventLoop;

use function array_filter;
use function array_values;

/**
 * A waiting peek ends when the stream closes, and waits again when another reader took the bytes it woke up for.
 */
final class PeekTest extends TestCase
{
    /**
     * Closing the stream ends a waiting peek with `AlreadyClosedException`, and leaves no watcher behind.
     */
    public function testCloseEndsAWaitingPeek(): void
    {
        [$a, $b] = Network\socket_pair();
        $callbacks = self::enabledCallbacks();

        $peek = Async\run(static fn(): string => $a->peek(5));
        Async\later();

        $a->close();

        $failure = null;
        try {
            $peek->await();
        } catch (IO\Exception\AlreadyClosedException $e) {
            $failure = $e;
        }

        static::assertInstanceOf(IO\Exception\AlreadyClosedException::class, $failure);
        static::assertSame($callbacks, self::enabledCallbacks());

        $b->close();
    }

    /**
     * A peek that wakes up after a read that waited before it took the bytes waits for the next bytes, instead of
     * failing.
     */
    public function testPeekWaitsAgainWhenAnEarlierReadTookTheBytes(): void
    {
        [$a, $b] = Network\socket_pair();

        $read = Async\run(static fn(): string => $a->read(5));
        $peek = Async\run(static fn(): string => $a->peek(5));
        Async\later();

        $b->writeAll('hello');
        static::assertSame('hello', $read->await());

        // Let the peek wake up and find nothing, before the next bytes arrive.
        Async\later();
        $b->writeAll('world');
        static::assertSame('world', $peek->await());
        static::assertSame('world', $a->read(5));
    }

    /**
     * A peek returns the bytes already waiting without consuming them, and the end of the stream as an empty
     * string.
     */
    public function testPeekReturnsWaitingBytesAndTheEndOfTheStream(): void
    {
        [$a, $b] = Network\socket_pair();

        $b->writeAll('hello');
        static::assertSame('hel', $a->peek(3));
        static::assertSame('hello', $a->read(5));

        $b->close();
        static::assertSame('', $a->peek(3));
    }

    /**
     * The event loop callbacks that are enabled.
     *
     * @return list<string>
     */
    private static function enabledCallbacks(): array
    {
        return array_values(array_filter(EventLoop::getIdentifiers(), EventLoop::isEnabled(...)));
    }
}
