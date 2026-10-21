<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Encoding\Base64;
use Psl\Encoding\Hex;
use Psl\Encoding\QuotedPrintable;
use Psl\IO;

use function count_chars;
use function method_exists;
use function min;
use function str_repeat;
use function strlen;
use function substr;
use function substr_count;

/**
 * A write made while another write of the same handle waits for the inner handle must not write that write's output
 * again, nor put its own output ahead of it.
 */
final class WriteHandleConcurrencyTest extends TestCase
{
    /**
     * @param Closure(IO\WriteHandleInterface): IO\WriteHandleInterface $wrap
     */
    #[DataProvider('provideHandles')]
    public function testConcurrentWritesWriteEachOutputOnce(
        Closure $wrap,
        string $first,
        string $second,
        string $expected,
    ): void {
        $inner = self::createInnerHandle(1024);
        $handle = $wrap($inner);

        $a = Async\run(static fn(): int => $handle->write($first));
        $b = Async\run(static fn(): int => $handle->write($second));

        static::assertSame(strlen($first), $a->await());
        static::assertSame(strlen($second), $b->await());
        self::flush($handle);

        static::assertSame($expected, $inner->buffer);
    }

    /**
     * @param Closure(IO\WriteHandleInterface): IO\WriteHandleInterface $wrap
     */
    #[DataProvider('provideHandles')]
    public function testConcurrentWritesLoseNoOutputWhenTheInnerHandleTakesItInParts(
        Closure $wrap,
        string $first,
        string $second,
        string $expected,
    ): void {
        $inner = self::createInnerHandle(3);
        $handle = $wrap($inner);

        $a = Async\run(static fn(): int => $handle->write($first));
        $b = Async\run(static fn(): int => $handle->write($second));

        static::assertSame(strlen($first), $a->await());
        static::assertSame(strlen($second), $b->await());
        self::flush($handle);

        // The parts of the two outputs can alternate, but none is lost or written twice.
        static::assertSame(count_chars($expected, 1), count_chars($inner->buffer, 1));
    }

    /**
     * @param Closure(IO\WriteHandleInterface): IO\WriteHandleInterface $wrap
     */
    #[DataProvider('provideHandles')]
    public function testTryWriteOnlyQueuesItsOutputWhileAWriteWaits(
        Closure $wrap,
        string $first,
        string $second,
        string $expected,
    ): void {
        $inner = self::createInnerHandle(1024);
        $handle = $wrap($inner);

        $write = Async\run(static fn(): int => $handle->write($first));
        // Let the write start; it waits for the inner handle.
        Async\later();
        static::assertSame('', $inner->buffer);

        // Output written now would reach the inner handle ahead of the waiting write's output.
        static::assertSame(strlen($second), $handle->tryWrite($second));
        static::assertSame('', $inner->buffer);

        static::assertSame(strlen($first), $write->await());
        $handle->write('');
        self::flush($handle);

        static::assertSame($expected, $inner->buffer);
    }

    public function testConcurrentWritesToAPipeLoseNoOutput(): void
    {
        [$reader, $writer] = IO\pipe();
        $handle = new Hex\EncodingWriteHandle($writer);

        $read = Async\run($reader->readAll(...));
        $a = Async\run(static fn(): int => $handle->write(str_repeat('A', 200_000)));
        $b = Async\run(static fn(): int => $handle->write(str_repeat('B', 200_000)));
        $a->await();
        $b->await();
        $writer->close();

        $output = $read->await();

        // "A" is "41" and "B" is "42" in hex; the outputs can alternate, but none is lost or written twice.
        static::assertSame(800_000, strlen($output));
        static::assertSame(400_000, substr_count($output, '4'));
        static::assertSame(200_000, substr_count($output, '1'));
        static::assertSame(200_000, substr_count($output, '2'));
    }

    /**
     * @return iterable<string, array{Closure(IO\WriteHandleInterface): IO\WriteHandleInterface, string, string, string}>
     */
    public static function provideHandles(): iterable
    {
        yield 'Base64\EncodingWriteHandle' => [
            static fn(IO\WriteHandleInterface $inner): IO\WriteHandleInterface => new Base64\EncodingWriteHandle(
                $inner,
            ),
            'Hello, ',
            'World',
            Base64\encode('Hello, World'),
        ];

        yield 'Base64\DecodingWriteHandle' => [
            static fn(IO\WriteHandleInterface $inner): IO\WriteHandleInterface => new Base64\DecodingWriteHandle(
                $inner,
            ),
            'SGVsbG8s',
            'IFdvcmxk',
            'Hello, World',
        ];

        yield 'Hex\EncodingWriteHandle' => [
            static fn(IO\WriteHandleInterface $inner): IO\WriteHandleInterface => new Hex\EncodingWriteHandle($inner),
            'Hello, ',
            'World',
            Hex\encode('Hello, World'),
        ];

        yield 'Hex\DecodingWriteHandle' => [
            static fn(IO\WriteHandleInterface $inner): IO\WriteHandleInterface => new Hex\DecodingWriteHandle($inner),
            '48656c6c6f2c20',
            '576f726c64',
            'Hello, World',
        ];

        yield 'QuotedPrintable\EncodingWriteHandle' => [
            static fn(IO\WriteHandleInterface $inner): IO\WriteHandleInterface => new QuotedPrintable\EncodingWriteHandle(
                $inner,
            ),
            "caf\xC3\xA9\r\n",
            'Hello',
            QuotedPrintable\encode("caf\xC3\xA9\r\nHello"),
        ];

        yield 'QuotedPrintable\DecodingWriteHandle' => [
            static fn(IO\WriteHandleInterface $inner): IO\WriteHandleInterface => new QuotedPrintable\DecodingWriteHandle(
                $inner,
            ),
            "caf=C3=A9\r\n",
            'Hello',
            "caf\xC3\xA9\r\nHello",
        ];
    }

    private static function flush(IO\WriteHandleInterface $handle): void
    {
        if (method_exists($handle, 'flush')) {
            $handle->flush();
        }
    }

    /**
     * Create a handle that accepts at most `$maxBytesPerWrite` bytes per write and whose `write()` first suspends the
     * calling fiber for one event loop tick, like a stream that has to wait until it is writable.
     *
     * @param positive-int $maxBytesPerWrite
     */
    private static function createInnerHandle(int $maxBytesPerWrite): object
    {
        return new class($maxBytesPerWrite) implements IO\WriteHandleInterface {
            use IO\WriteHandleConvenienceMethodsTrait;

            public string $buffer = '';

            /**
             * @param positive-int $maxBytesPerWrite
             */
            public function __construct(
                private readonly int $maxBytesPerWrite,
            ) {}

            public function tryWrite(string $bytes): int
            {
                $accepted = min($this->maxBytesPerWrite, strlen($bytes));
                $this->buffer .= substr($bytes, 0, $accepted);

                return $accepted;
            }

            public function write(
                string $bytes,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): int {
                Async\later();

                return $this->tryWrite($bytes);
            }
        };
    }
}
