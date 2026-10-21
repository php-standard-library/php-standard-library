<?php

declare(strict_types=1);

namespace Psl\Compression\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Compression\CompressingWriteHandle;
use Psl\Compression\DecompressingWriteHandle;
use Psl\Compression\Tests\Fixture\InterruptibleWriteHandle;
use Psl\Compression\Tests\Fixture\NullCompressor;
use Psl\Compression\Tests\Fixture\NullDecompressor;
use Psl\IO;

use function hrtime;
use function intdiv;
use function str_repeat;
use function substr;

/**
 * The compressing and decompressing write handles drain their pending output with an offset, so that draining N
 * bytes copies O(N) bytes.
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

    private static function payload(): string
    {
        return str_repeat('0123456789abcdef', intdiv(self::PAYLOAD_SIZE, 16));
    }
}
