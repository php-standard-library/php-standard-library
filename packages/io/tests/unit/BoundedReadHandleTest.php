<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

final class BoundedReadHandleTest extends TestCase
{
    public function testReadingExactlyAtLimit(): void
    {
        $inner = new IO\MemoryHandle('hello');
        $handle = new IO\BoundedReadHandle($inner, 5);

        static::assertSame('hello', $handle->readAll());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testReadingUnderLimit(): void
    {
        $inner = new IO\MemoryHandle('hi');
        $handle = new IO\BoundedReadHandle($inner, 100);

        static::assertSame('hi', $handle->readAll());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testOverflowThrowsOnRead(): void
    {
        $inner = new IO\MemoryHandle('hello world');
        $handle = new IO\BoundedReadHandle($inner, 5);

        static::assertSame('hello', $handle->read(5));

        $this->expectException(IO\Exception\RuntimeException::class);
        $this->expectExceptionMessage('exceeded the configured limit of 5 bytes');

        $handle->read();
    }

    public function testOverflowThrowsOnTryRead(): void
    {
        $inner = new IO\MemoryHandle('hello world');
        $handle = new IO\BoundedReadHandle($inner, 5);

        static::assertSame('hello', $handle->read(5));

        $this->expectException(IO\Exception\RuntimeException::class);
        $this->expectExceptionMessage('exceeded the configured limit of 5 bytes');

        $handle->tryRead();
    }

    public function testNoOverflowWhenInnerAtEof(): void
    {
        $inner = new IO\MemoryHandle('hello');
        $handle = new IO\BoundedReadHandle($inner, 5);

        static::assertSame('hello', $handle->read(5));
        static::assertSame('', $handle->read());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testNoOverflowWhenInnerReturnsEmptyOnPeek(): void
    {
        $inner = new IO\IterableReadHandle(['hello']);
        $handle = new IO\BoundedReadHandle($inner, 5);

        static::assertSame('hello', $handle->read(5));
        static::assertSame('', $handle->read());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testLateBytesAtTheLimitAreNotEof(): void
    {
        $inner = new ScriptedReadHandle(['hello', '', ' world']);
        $handle = new IO\BoundedReadHandle($inner, 5);

        static::assertSame('hello', $handle->read());
        static::assertSame('', $handle->tryRead());
        static::assertFalse($handle->reachedEndOfDataSource());

        $this->expectException(IO\Exception\RuntimeException::class);
        $this->expectExceptionMessage('exceeded the configured limit of 5 bytes');

        $handle->read();
    }

    public function testTryReadAtTheLimitThrowsOnceLateBytesArrive(): void
    {
        $inner = new ScriptedReadHandle(['hello', '', 'x']);
        $handle = new IO\BoundedReadHandle($inner, 5);

        static::assertSame('hello', $handle->tryRead());
        static::assertSame('', $handle->tryRead());
        static::assertFalse($handle->reachedEndOfDataSource());

        $this->expectException(IO\Exception\RuntimeException::class);

        $handle->tryRead();
    }

    public function testReadAtTheLimitReportsEofOnlyWhenTheInnerHandleDoes(): void
    {
        $inner = new ScriptedReadHandle(['hello', '']);
        $handle = new IO\BoundedReadHandle($inner, 5);

        static::assertSame('hello', $handle->read());
        static::assertSame('', $handle->read());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testARetryAfterAnOverflowThrowsAgainInsteadOfReportingEof(): void
    {
        // the overflow byte is the last one: a second peek would find the inner handle at EOF.
        $inner = new IO\MemoryHandle('hello!');
        $handle = new IO\BoundedReadHandle($inner, 5);

        static::assertSame('hello', $handle->read(5));

        try {
            $handle->read();
            static::fail('Expected LimitExceededException');
        } catch (IO\Exception\LimitExceededException $e) {
            static::assertSame(5, $e->limit);
        }

        $this->expectException(IO\Exception\LimitExceededException::class);
        $this->expectExceptionMessage('exceeded the configured limit of 5 bytes');

        $handle->read();
    }

    public function testAnObservedOverflowIsLatched(): void
    {
        $inner = new IO\MemoryHandle('hello world');
        $handle = new IO\BoundedReadHandle($inner, 5);

        static::assertSame('hello', $handle->read(5));

        $overflow = null;
        try {
            $handle->tryRead();
        } catch (IO\Exception\LimitExceededException $e) {
            $overflow = $e;
        }

        static::assertNotNull($overflow);
        static::assertInstanceOf(IO\Exception\RuntimeException::class, $overflow);
        static::assertSame('Data exceeded the configured limit of 5 bytes.', $overflow->getMessage());

        $position = $inner->tell();

        // every later read throws the same exception and leaves the inner handle alone.
        foreach ([
            $handle->read(...),
            $handle->tryRead(...),
            $handle->readAll(...),
            static fn() => $handle->readFixedSize(1),
        ] as $operation) {
            try {
                $operation();
                static::fail('Expected LimitExceededException');
            } catch (IO\Exception\LimitExceededException $e) {
                static::assertSame($overflow, $e);
            }
        }

        static::assertSame($position, $inner->tell());
    }

    public function testLimitReachedFlagPreventsRepeatedPeeks(): void
    {
        $inner = new IO\MemoryHandle('hello');
        $handle = new IO\BoundedReadHandle($inner, 5);

        $handle->read(5);
        $handle->read();
        static::assertTrue($handle->reachedEndOfDataSource());
        static::assertSame('', $handle->read());
        static::assertSame('', $handle->tryRead());
    }

    public function testReachedEndOfDataSourceFalseBeforeLimit(): void
    {
        $inner = new IO\MemoryHandle('hello world');
        $handle = new IO\BoundedReadHandle($inner, 5);

        static::assertFalse($handle->reachedEndOfDataSource());

        $handle->read(3);

        static::assertFalse($handle->reachedEndOfDataSource());
    }

    public function testMaxBytesIsCappedToRemaining(): void
    {
        $inner = new IO\MemoryHandle('abcde');
        $handle = new IO\BoundedReadHandle($inner, 3);

        static::assertSame('abc', $handle->read(10));
    }

    public function testTryReadCapsToRemaining(): void
    {
        $inner = new IO\MemoryHandle('abcde');
        $inner->read(0);
        $handle = new IO\BoundedReadHandle($inner, 3);

        static::assertSame('abc', $handle->tryRead(10));
    }

    public function testLimitOfZero(): void
    {
        $inner = new IO\MemoryHandle('');
        $handle = new IO\BoundedReadHandle($inner, 0);

        static::assertSame('', $handle->read());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testLimitOfZeroWithDataThrows(): void
    {
        $inner = new IO\MemoryHandle('x');
        $handle = new IO\BoundedReadHandle($inner, 0);

        $this->expectException(IO\Exception\RuntimeException::class);

        $handle->read();
    }

    public function testCloseBehavior(): void
    {
        $inner = new IO\MemoryHandle('hello');
        $handle = new IO\BoundedReadHandle($inner, 5);

        static::assertFalse($handle->isClosed());

        $handle->close();

        static::assertTrue($handle->isClosed());
        static::assertTrue($inner->isClosed());
    }

    public function testCloseIsIdempotent(): void
    {
        $inner = new IO\MemoryHandle('hello');
        $handle = new IO\BoundedReadHandle($inner, 5);

        $handle->close();
        $handle->close();

        static::assertTrue($handle->isClosed());
    }

    public function testCloseDoesNotCloseNonCloseableHandle(): void
    {
        $inner = new class implements IO\ReadHandleInterface {
            use IO\ReadHandleConvenienceMethodsTrait;

            public function reachedEndOfDataSource(): bool
            {
                return true;
            }

            public function tryRead(null|int $maxBytes = null): string
            {
                return '';
            }

            public function read(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return '';
            }
        };

        $handle = new IO\BoundedReadHandle($inner, 5);
        $handle->close();

        static::assertTrue($handle->isClosed());
    }

    /**
     * @param (Closure(IO\BoundedReadHandle): mixed) $operation
     */
    #[DataProvider('provideOperations')]
    public function testAllOperationsThrowAfterClose(Closure $operation): void
    {
        $inner = new IO\MemoryHandle('hello');
        $handle = new IO\BoundedReadHandle($inner, 5);
        $handle->close();

        $this->expectException(IO\Exception\AlreadyClosedException::class);
        $this->expectExceptionMessage('Handle has already been closed.');

        $operation($handle);
    }

    /**
     * @return iterable<string, array{(Closure(IO\BoundedReadHandle): mixed)}>
     */
    public static function provideOperations(): iterable
    {
        yield 'read' => [static fn(IO\BoundedReadHandle $h) => $h->read()];
        yield 'readAll' => [static fn(IO\BoundedReadHandle $h) => $h->readAll()];
        yield 'readFixedSize' => [static fn(IO\BoundedReadHandle $h) => $h->readFixedSize(1)];
        yield 'tryRead' => [static fn(IO\BoundedReadHandle $h) => $h->tryRead()];
        yield 'reachedEndOfDataSource' => [static fn(IO\BoundedReadHandle $h) => $h->reachedEndOfDataSource()];
    }

    public function testOverflowDetectedAfterMultipleReads(): void
    {
        $inner = new IO\MemoryHandle('abcdefghij');
        $handle = new IO\BoundedReadHandle($inner, 5);

        static::assertSame('ab', $handle->read(2));
        static::assertSame('cde', $handle->read(3));

        $this->expectException(IO\Exception\RuntimeException::class);

        $handle->read();
    }
}
