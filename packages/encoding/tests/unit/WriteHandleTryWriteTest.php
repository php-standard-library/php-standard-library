<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Async\SignalCancellationToken;
use Psl\Encoding\Base64;
use Psl\Encoding\Hex;
use Psl\Encoding\QuotedPrintable;
use Psl\IO;

use function method_exists;
use function min;
use function strlen;
use function substr;

/**
 * `tryWrite()` must not wait: the write handles keep the output their inner handle does not accept right away, and
 * a waiting write delivers it, passing its cancellation token to the inner handle.
 */
final class WriteHandleTryWriteTest extends TestCase
{
    /**
     * @param Closure(IO\WriteHandleInterface): IO\WriteHandleInterface $wrap
     */
    #[DataProvider('provideHandles')]
    public function testTryWriteDoesNotWaitForTheInnerHandle(Closure $wrap, string $input, string $expected): void
    {
        $inner = self::createInnerHandle(0);
        $handle = $wrap($inner);

        static::assertSame(strlen($input), $handle->tryWrite($input));
        static::assertSame([], $inner->tokens);
        static::assertSame('', $inner->buffer);

        // A waiting write delivers what the try left pending.
        $token = new SignalCancellationToken();
        static::assertSame(0, $handle->write('', $token));
        self::flush($handle);

        static::assertSame($expected, $inner->buffer);
    }

    /**
     * @param Closure(IO\WriteHandleInterface): IO\WriteHandleInterface $wrap
     */
    #[DataProvider('provideHandles')]
    public function testTryWriteKeepsTheOrderOfPartlyWrittenOutput(Closure $wrap, string $input, string $expected): void
    {
        $inner = self::createInnerHandle(3);
        $handle = $wrap($inner);

        foreach ([substr($input, 0, 4), substr($input, 4)] as $part) {
            static::assertSame(strlen($part), $handle->tryWrite($part));
        }

        static::assertLessThanOrEqual(3, strlen($inner->buffer));
        static::assertSame([], $inner->tokens);

        $handle->write('');
        self::flush($handle);

        static::assertSame($expected, $inner->buffer);
    }

    /**
     * @param Closure(IO\WriteHandleInterface): IO\WriteHandleInterface $wrap
     */
    #[DataProvider('provideHandles')]
    public function testWritePassesItsTokenToTheInnerHandle(Closure $wrap, string $input, string $expected): void
    {
        $inner = self::createInnerHandle(0);
        $handle = $wrap($inner);
        $token = new SignalCancellationToken();

        static::assertSame(strlen($input), $handle->write($input, $token));

        static::assertNotSame([], $inner->tokens);
        foreach ($inner->tokens as $received) {
            static::assertSame($token, $received);
        }

        self::flush($handle);
        static::assertSame($expected, $inner->buffer);
    }

    /**
     * @return iterable<string, array{Closure(IO\WriteHandleInterface): IO\WriteHandleInterface, string, string}>
     */
    public static function provideHandles(): iterable
    {
        yield 'Base64\EncodingWriteHandle' => [
            static fn(IO\WriteHandleInterface $inner): IO\WriteHandleInterface => new Base64\EncodingWriteHandle(
                $inner,
            ),
            'Hello, World',
            Base64\encode('Hello, World'),
        ];

        yield 'Base64\DecodingWriteHandle' => [
            static fn(IO\WriteHandleInterface $inner): IO\WriteHandleInterface => new Base64\DecodingWriteHandle(
                $inner,
            ),
            'SGVsbG8sIFdvcmxk',
            'Hello, World',
        ];

        yield 'Hex\EncodingWriteHandle' => [
            static fn(IO\WriteHandleInterface $inner): IO\WriteHandleInterface => new Hex\EncodingWriteHandle($inner),
            'Hello, World',
            Hex\encode('Hello, World'),
        ];

        yield 'Hex\DecodingWriteHandle' => [
            static fn(IO\WriteHandleInterface $inner): IO\WriteHandleInterface => new Hex\DecodingWriteHandle($inner),
            '48656c6c6f2c20576f726c64',
            'Hello, World',
        ];

        yield 'QuotedPrintable\EncodingWriteHandle' => [
            static fn(IO\WriteHandleInterface $inner): IO\WriteHandleInterface => new QuotedPrintable\EncodingWriteHandle(
                $inner,
            ),
            "caf\xC3\xA9\r\nHello",
            QuotedPrintable\encode("caf\xC3\xA9\r\nHello"),
        ];

        yield 'QuotedPrintable\DecodingWriteHandle' => [
            static fn(IO\WriteHandleInterface $inner): IO\WriteHandleInterface => new QuotedPrintable\DecodingWriteHandle(
                $inner,
            ),
            "caf=C3=A9\r\nHello",
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
     * Create a handle whose `tryWrite()` accepts at most `$ready` bytes in all, like a socket whose buffer is nearly
     * full, and whose `write()` records the token it is given and accepts everything, as if it had waited.
     */
    private static function createInnerHandle(int $ready): object
    {
        return new class($ready) implements IO\WriteHandleInterface {
            use IO\WriteHandleConvenienceMethodsTrait;

            public string $buffer = '';

            /**
             * @var list<CancellationTokenInterface>
             */
            public array $tokens = [];

            public function __construct(
                private int $ready,
            ) {}

            public function tryWrite(string $bytes): int
            {
                $accepted = min($this->ready, strlen($bytes));
                $this->ready -= $accepted;
                $this->buffer .= substr($bytes, 0, $accepted);

                return $accepted;
            }

            public function write(
                string $bytes,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): int {
                $this->tokens[] = $cancellation;
                $this->buffer .= $bytes;

                return strlen($bytes);
            }
        };
    }
}
