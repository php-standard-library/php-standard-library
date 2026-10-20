<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit;

use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Encoding\Base64;
use Psl\Encoding\Hex;
use Psl\Encoding\QuotedPrintable;
use Psl\IO;

use function strlen;
use function substr;

/**
 * `tryRead()` must not wait: the read handles may only take what the inner handle has available right away.
 */
final class ReadHandleTryReadTest extends TestCase
{
    /**
     * @param Closure(IO\ReadHandleInterface): IO\ReadHandleInterface $wrap
     */
    #[DataProvider('provideHandles')]
    public function testTryReadDoesNotWaitForTheInnerHandle(Closure $wrap, string $available, string $expected): void
    {
        $handle = $wrap(self::createNonBlockingHandle($available));

        static::assertSame($expected, $handle->tryRead());
        static::assertSame('', $handle->tryRead());
        static::assertFalse($handle->reachedEndOfDataSource());
    }

    /**
     * @return iterable<string, array{Closure(IO\ReadHandleInterface): IO\ReadHandleInterface, string, string}>
     */
    public static function provideHandles(): iterable
    {
        yield 'Base64\EncodingReadHandle' => [
            static fn(IO\ReadHandleInterface $inner): IO\ReadHandleInterface => new Base64\EncodingReadHandle($inner),
            'Hello',
            'SGVs',
        ];

        yield 'Base64\DecodingReadHandle' => [
            static fn(IO\ReadHandleInterface $inner): IO\ReadHandleInterface => new Base64\DecodingReadHandle($inner),
            'SGVsbG8',
            'Hel',
        ];

        yield 'Hex\EncodingReadHandle' => [
            static fn(IO\ReadHandleInterface $inner): IO\ReadHandleInterface => new Hex\EncodingReadHandle($inner),
            'Hello',
            '48656c6c6f',
        ];

        yield 'Hex\DecodingReadHandle' => [
            static fn(IO\ReadHandleInterface $inner): IO\ReadHandleInterface => new Hex\DecodingReadHandle($inner),
            '48656c6c6',
            'Hell',
        ];

        yield 'QuotedPrintable\EncodingReadHandle' => [
            static fn(IO\ReadHandleInterface $inner): IO\ReadHandleInterface => new QuotedPrintable\EncodingReadHandle(
                $inner,
            ),
            "caf\xC3\xA9\r\nHello",
            'caf=C3=A9',
        ];

        yield 'QuotedPrintable\DecodingReadHandle' => [
            static fn(IO\ReadHandleInterface $inner): IO\ReadHandleInterface => new QuotedPrintable\DecodingReadHandle(
                $inner,
            ),
            "caf=C3=A9\r\nHello=",
            "caf\xC3\xA9\r\n",
        ];
    }

    /**
     * Create a handle that has `$available` ready to be read, and then has nothing available, without reaching
     * the end of its data source, like a socket that is waiting for more data: `read()` would wait forever.
     */
    private static function createNonBlockingHandle(string $available): IO\ReadHandleInterface
    {
        return new class($available) implements IO\ReadHandleInterface {
            use IO\ReadHandleConvenienceMethodsTrait;

            public function __construct(
                private string $available,
            ) {}

            public function reachedEndOfDataSource(): bool
            {
                return false;
            }

            public function tryRead(null|int $maxBytes = null): string
            {
                $maxBytes ??= strlen($this->available);
                $chunk = substr($this->available, 0, $maxBytes);
                $this->available = substr($this->available, $maxBytes);

                return $chunk;
            }

            public function read(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                throw new LogicException('read() waits for data that never comes.');
            }
        };
    }
}
