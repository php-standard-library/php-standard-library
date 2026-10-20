<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Str;
use Psl\Str\Byte;

final class MemoryHandleTest extends TestCase
{
    /**
     * @param (Closure(IO\MemoryHandle): mixed) $operation
     */
    #[DataProvider('provideOperations')]
    public function testClose(Closure $operation): void
    {
        $handle = new IO\MemoryHandle('hello');
        $handle->close();

        $this->expectException(IO\Exception\AlreadyClosedException::class);
        $this->expectExceptionMessage('Handle has already been closed.');

        $operation($handle);
    }

    /**
     * @return iterable<(Closure(IO\MemoryHandle): mixed)>
     */
    public static function provideOperations(): iterable
    {
        yield [
            static fn(IO\SeekHandleInterface $handle): null => $handle->seek(5),
        ];

        yield [
            static fn(IO\SeekHandleInterface $handle): int => $handle->tell(),
        ];

        yield [
            static fn(IO\WriteHandleInterface $handle): null => $handle->write('hello'),
        ];

        yield [
            static fn(IO\WriteHandleInterface $handle): null => $handle->writeAll('hello'),
        ];

        yield [
            static fn(IO\ReadHandleInterface $handle): string => $handle->read(),
        ];

        yield [
            static fn(IO\ReadHandleInterface $handle): string => $handle->readAll(),
        ];

        yield [
            static fn(IO\ReadHandleInterface $handle): string => $handle->tryRead(),
        ];
    }

    public function testMemoryHandle(): void
    {
        $handle = new IO\MemoryHandle('f');

        $handle->writeAll('Hello, World!' . "\n");
        foreach (['', '- Read', '- Write', '- Seek', '- Close'] as $line) {
            $handle->writeAll($line . "\n");
        }

        $handle->seek(0);
        static::assertSame('Hello, World!', $handle->read(13));

        $handle->seek(13 + Byte\length("\n") + Byte\length("\n"));
        static::assertSame('- Read', $handle->read(6));

        $handle->seek(19 + (Byte\length("\n") * 3));
        static::assertSame('- Write', $handle->read(7));

        $handle->seek(26 + (Byte\length("\n") * 4));
        static::assertSame('- Seek', $handle->read(6));

        $handle->seek(32 + (Byte\length("\n") * 5));
        static::assertSame('- Close', $handle->read(7));

        static::assertSame("\n", $handle->read());

        static::assertSame(45, $handle->tell());
    }

    public function testRead(): void
    {
        $h = new IO\MemoryHandle('herpderp');
        $reader = new IO\Reader($h);
        static::assertSame('herp', $reader->readFixedSize(4));
        static::assertSame('derp', $reader->read());
        static::assertSame('', $reader->read());
        static::assertSame('', $h->read());
        static::assertSame(8, $h->tell());
        $h->seek(0);
        static::assertSame(0, $h->tell());
        static::assertSame('herpderp', $h->read());
        $h->seek(4);
        static::assertSame(4, $h->tell());
        static::assertSame('derp', $h->read());
    }

    public function testReadAtInvalidOffset(): void
    {
        $h = new IO\MemoryHandle('herpderp');
        $h->seek(99_999);
        static::assertSame('', $h->read());
    }

    public function testSeekResetsEndOfDataSource(): void
    {
        $h = new IO\MemoryHandle('herpderp');
        static::assertSame('herpderp', $h->readAll());
        static::assertSame('', $h->read());
        static::assertTrue($h->reachedEndOfDataSource());

        $h->seek(0);

        static::assertFalse($h->reachedEndOfDataSource());
        static::assertSame('herp', $h->read(4));
        static::assertFalse($h->reachedEndOfDataSource());
        static::assertSame('derp', $h->readAll());
    }

    public function testSeekAfterEofDoesNotHideRemainingDataFromWrappers(): void
    {
        $h = new IO\MemoryHandle('hello, world');
        static::assertSame('hello, world', $h->readAll());
        static::assertSame('', $h->read());

        $h->seek(0);

        // A stale EOF flag would make the bounded handle treat the limit as a clean end of data.
        $bounded = new IO\BoundedReadHandle($h, 5);

        $this->expectException(IO\Exception\RuntimeException::class);
        $this->expectExceptionMessage('exceeded the configured limit of 5 bytes');

        $bounded->readAll();
    }

    public function testReadTooMuch(): void
    {
        $h = new IO\MemoryHandle('herpderp');

        $this->expectException(IO\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Reached end of file before requested size.');

        $reader = new IO\Reader($h);
        $reader->readFixedSize(1024);
    }

    public function testWrite(): void
    {
        $h = new IO\MemoryHandle();
        $h->writeAll('foo');

        static::assertSame('foo', $h->getBuffer());
    }

    public function testWriteAfter(): void
    {
        $h = new IO\MemoryHandle('hello');
        $h->seek(20);

        $h->write('world');

        $h->seek(0);
        static::assertSame('hello', $h->readAll(5));
        static::assertSame(Str\repeat("\0", 15), $h->readAll(15));
        static::assertSame('world', $h->readAll());
    }

    public function testOverwrite(): void
    {
        $h = new IO\MemoryHandle('xxxxderp');
        $h->write('herp');
        static::assertSame('herpderp', $h->getBuffer());
        static::assertSame('derp', $h->readAll());
        $h->seek(0);
        static::assertSame('herpderp', $h->readAll());
    }

    public function testWriteAllWithTimeout(): void
    {
        $h = new IO\MemoryHandle();

        $h->writeAll('hello, world!', new Async\TimeoutCancellationToken(Duration::seconds(5)));

        static::assertSame('hello, world!', $h->getBuffer());
    }

    public function testWriteAllEmptyWithTimeout(): void
    {
        $h = new IO\MemoryHandle();

        $h->writeAll('', new Async\TimeoutCancellationToken(Duration::seconds(5)));

        static::assertSame('', $h->getBuffer());
    }

    public function testReadAllWithTimeout(): void
    {
        $h = new IO\MemoryHandle('hello, world!');

        $data = $h->readAll(cancellation: new Async\TimeoutCancellationToken(Duration::seconds(5)));

        static::assertSame('hello, world!', $data);
    }

    public function testReadAllWithMaxBytesAndTimeout(): void
    {
        $h = new IO\MemoryHandle('hello, world!');

        $data = $h->readAll(maxBytes: 5, cancellation: new Async\TimeoutCancellationToken(Duration::seconds(5)));

        static::assertSame('hello', $data);
    }

    public function testReadFixedSizeWithTimeout(): void
    {
        $h = new IO\MemoryHandle('hello, world!');

        $data = $h->readFixedSize(5, new Async\TimeoutCancellationToken(Duration::seconds(5)));

        static::assertSame('hello', $data);
    }

    public function testReadFixedSizeInsufficientData(): void
    {
        $h = new IO\MemoryHandle('hi');

        $this->expectException(IO\Exception\RuntimeException::class);
        $this->expectExceptionMessage('3 bytes were requested, but only able to read 2 bytes');

        $h->readFixedSize(3);
    }

    public function testIsClosedReturnsFalseByDefault(): void
    {
        $h = new IO\MemoryHandle('hello');

        static::assertFalse($h->isClosed());
    }

    public function testIsClosedReturnsTrueAfterClose(): void
    {
        $h = new IO\MemoryHandle('hello');

        $h->close();

        static::assertTrue($h->isClosed());
    }
}
