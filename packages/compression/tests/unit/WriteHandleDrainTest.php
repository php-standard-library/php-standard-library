<?php

declare(strict_types=1);

namespace Psl\Compression\Tests\Unit;

use Closure;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Compression\CompressingWriteHandle;
use Psl\Compression\DecompressingWriteHandle;
use Psl\Compression\Tests\Fixture\InterruptibleWriteHandle;
use Psl\Compression\Tests\Fixture\NullCompressor;
use Psl\Compression\Tests\Fixture\NullDecompressor;
use Psl\Compression\Tests\Fixture\TrailingCompressor;
use Psl\Compression\Tests\Fixture\TrailingDecompressor;
use Psl\IO;

use function hrtime;
use function intdiv;
use function str_repeat;
use function strlen;
use function substr;

/**
 * The compressing and decompressing write handles drain their pending output with an offset, so that draining N
 * bytes copies O(N) bytes, and they keep what a drain already wrote and the stream they already finished when
 * a flush fails, so that retrying it writes neither twice.
 */
final class WriteHandleDrainTest extends TestCase
{
    private const int PAYLOAD_SIZE = 16 * 1024 * 1024;

    /**
     * @return iterable<string, array{Closure(IO\WriteHandleInterface): IO\BufferedWriteHandleInterface}>
     */
    public static function provideIdentityHandles(): iterable
    {
        yield 'compressing' => [
            static fn(IO\WriteHandleInterface $inner): IO\BufferedWriteHandleInterface => new CompressingWriteHandle(
                $inner,
                new NullCompressor(),
            ),
        ];

        yield 'decompressing' => [
            static fn(IO\WriteHandleInterface $inner): IO\BufferedWriteHandleInterface => new DecompressingWriteHandle(
                $inner,
                new NullDecompressor(),
            ),
        ];
    }

    /**
     * @return iterable<string, array{Closure(IO\WriteHandleInterface, TrailingCompressor|TrailingDecompressor): IO\BufferedWriteHandleInterface, TrailingCompressor|TrailingDecompressor}>
     */
    public static function provideTrailingHandles(): iterable
    {
        yield 'compressing' => [
            static fn(
                IO\WriteHandleInterface $inner,
                TrailingCompressor|TrailingDecompressor $codec,
            ): IO\BufferedWriteHandleInterface => new CompressingWriteHandle($inner, $codec),
            new TrailingCompressor(),
        ];

        yield 'decompressing' => [
            static fn(
                IO\WriteHandleInterface $inner,
                TrailingCompressor|TrailingDecompressor $codec,
            ): IO\BufferedWriteHandleInterface => new DecompressingWriteHandle($inner, $codec),
            new TrailingDecompressor(),
        ];
    }

    /**
     * @param Closure(IO\WriteHandleInterface): IO\BufferedWriteHandleInterface $factory
     */
    #[DataProvider('provideIdentityHandles')]
    public function testWriteDrainsInLinearTime(Closure $factory): void
    {
        $inner = new InterruptibleWriteHandle(8192);
        $handle = $factory($inner);
        $payload = self::payload();

        $start = hrtime(true);
        // Each write adds 64 KiB of output and drains 8 KiB of it, so the pending output keeps growing.
        for ($offset = 0; $offset < self::PAYLOAD_SIZE; $offset += 65_536) {
            $handle->write(substr($payload, $offset, 65_536));
        }

        $handle->flush();
        $elapsed = (hrtime(true) - $start) / 1e9;

        static::assertSame($payload, $inner->buffer);
        // Passing the whole pending output on every write hands the inner handle about 1.8 GiB here.
        static::assertLessThan(16 * self::PAYLOAD_SIZE, $inner->given);
        static::assertLessThan(1.0, $elapsed);
    }

    /**
     * @param Closure(IO\WriteHandleInterface): IO\BufferedWriteHandleInterface $factory
     */
    #[DataProvider('provideIdentityHandles')]
    public function testTryWriteDrainsInLinearTime(Closure $factory): void
    {
        $inner = new InterruptibleWriteHandle(8192);
        $handle = $factory($inner);
        $payload = self::payload();

        $start = hrtime(true);
        // Each write adds 64 KiB of output and drains 8 KiB of it, so the pending output keeps growing.
        for ($offset = 0; $offset < self::PAYLOAD_SIZE; $offset += 65_536) {
            $handle->tryWrite(substr($payload, $offset, 65_536));
        }

        $handle->flush();
        $elapsed = (hrtime(true) - $start) / 1e9;

        static::assertSame($payload, $inner->buffer);
        static::assertLessThan(16 * self::PAYLOAD_SIZE, $inner->given);
        static::assertLessThan(1.0, $elapsed);
    }

    /**
     * @param Closure(IO\WriteHandleInterface, TrailingCompressor|TrailingDecompressor): IO\BufferedWriteHandleInterface $factory
     */
    #[DataProvider('provideTrailingHandles')]
    public function testRetriedFlushWritesNothingTwice(
        Closure $factory,
        TrailingCompressor|TrailingDecompressor $codec,
    ): void {
        $inner = new InterruptibleWriteHandle(2);
        $handle = $factory($inner, $codec);
        $handle->tryWrite('abcdef');
        static::assertSame('ab', $inner->buffer);

        // The flush writes "cd", then the next write fails.
        $failure = new Async\Exception\CancelledException(new Async\SignalCancellationToken());
        $inner->failAfter(1, $failure);

        try {
            $handle->flush();
            static::fail('The flush did not fail.');
        } catch (Async\Exception\CancelledException $e) {
            static::assertSame($failure, $e);
        }

        static::assertSame('abcd', $inner->buffer);

        // Writing nothing does not start a new stream; the retry finishes the drain.
        $handle->write('');
        $handle->flush();

        static::assertSame('abcdef!!', $inner->buffer);
        static::assertSame(1, $codec->finished);

        // Data written after the flush starts a new stream, which the next flush finishes.
        $handle->write('gh');
        $handle->flush();

        static::assertSame('abcdef!!gh!!', $inner->buffer);
        static::assertSame(2, $codec->finished);
    }

    /**
     * @param Closure(IO\WriteHandleInterface, TrailingCompressor|TrailingDecompressor): IO\BufferedWriteHandleInterface $factory
     */
    #[DataProvider('provideTrailingHandles')]
    public function testWriteAfterFailedFlushStartsNewStream(
        Closure $factory,
        TrailingCompressor|TrailingDecompressor $codec,
    ): void {
        $inner = new InterruptibleWriteHandle(2);
        $handle = $factory($inner, $codec);
        $handle->tryWrite('abcd');

        $failure = new IO\Exception\RuntimeException('The connection was reset.');
        $inner->failAfter(0, $failure);

        try {
            $handle->flush();
            static::fail('The flush did not fail.');
        } catch (IO\Exception\RuntimeException $e) {
            static::assertSame($failure, $e);
        }

        // The finished stream's output is still pending; the new data follows it, in a new stream.
        $handle->write('ef');
        $handle->flush();

        static::assertSame('abcd!!ef!!', $inner->buffer);
        static::assertSame(2, $codec->finished);
    }

    /**
     * @param Closure(IO\WriteHandleInterface, TrailingCompressor|TrailingDecompressor): IO\BufferedWriteHandleInterface $factory
     */
    #[DataProvider('provideTrailingHandles')]
    public function testFlushFailsWhenTheInnerHandleAcceptsNothing(
        Closure $factory,
        TrailingCompressor|TrailingDecompressor $codec,
    ): void {
        $inner = new class() implements IO\WriteHandleInterface {
            use IO\WriteHandleConvenienceMethodsTrait;

            public bool $accepting = false;

            public string $buffer = '';

            #[Override]
            public function tryWrite(string $bytes): int
            {
                if (!$this->accepting) {
                    return 0;
                }

                $this->buffer .= $bytes;

                return strlen($bytes);
            }

            #[Override]
            public function write(
                string $bytes,
                Async\CancellationTokenInterface $cancellation = new Async\NullCancellationToken(),
            ): int {
                return $this->tryWrite($bytes);
            }
        };

        $handle = $factory($inner, $codec);
        $handle->write('abc');

        try {
            $handle->flush();
            static::fail('The flush did not fail.');
        } catch (IO\Exception\RuntimeException $e) {
            static::assertSame(
                'The inner handle accepted no data; 5 bytes of output are still pending.',
                $e->getMessage(),
            );
        }

        $inner->accepting = true;
        $handle->flush();

        static::assertSame('abc!!', $inner->buffer);
        static::assertSame(1, $codec->finished);
    }

    private static function payload(): string
    {
        return str_repeat('0123456789abcdef', intdiv(self::PAYLOAD_SIZE, 16));
    }
}
