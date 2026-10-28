<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use HashContext;
use Override;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\OS;

use function array_slice;
use function count;
use function fclose;
use function file_get_contents;
use function fopen;
use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function hrtime;
use function intdiv;
use function max;
use function min;
use function str_repeat;
use function stream_socket_pair;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * Writing N bytes must copy O(N) bytes, however few bytes the destination accepts per write.
 */
final class WriteAllTest extends TestCase
{
    private const int PAYLOAD_SIZE = 16 * 1024 * 1024;

    /**
     * The largest slice a write loop may copy out of the caller's string for one write.
     */
    private const int MAXIMUM_SLICE_SIZE = 64 * 1024;

    public function testTraitWriteAllPassesBoundedSlices(): void
    {
        $sink = new class() implements IO\WriteHandleInterface {
            use IO\WriteHandleConvenienceMethodsTrait;

            /**
             * @var list<int>
             */
            public array $sizes = [];

            public string $digest = '';

            private HashContext $hash;

            public function __construct()
            {
                $this->hash = hash_init('xxh128');
            }

            #[Override]
            public function tryWrite(string $bytes): int
            {
                $this->sizes[] = strlen($bytes);
                $accepted = min(8192, strlen($bytes));
                hash_update($this->hash, substr($bytes, 0, $accepted));

                return $accepted;
            }

            #[Override]
            public function write(
                string $bytes,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): int {
                return $this->tryWrite($bytes);
            }

            public function finish(): void
            {
                $this->digest = hash_final($this->hash);
            }
        };

        $payload = self::payload();

        $start = hrtime(true);
        $sink->writeAll($payload);
        $elapsed = (hrtime(true) - $start) / 1e9;
        $sink->finish();

        static::assertSame(hash('xxh128', $payload), $sink->digest);
        static::assertSame(self::PAYLOAD_SIZE, $sink->sizes[0]);
        static::assertLessThanOrEqual(self::MAXIMUM_SLICE_SIZE, max(array_slice($sink->sizes, 1)));
        // Copying the whole remainder on every write takes about two seconds here; the offset loop takes a
        // few milliseconds.
        static::assertLessThan(1.0, $elapsed);
    }

    public function testStreamWriteAllPassesBoundedSlices(): void
    {
        if (OS\is_windows()) {
            // PHP emulates socket pairs over TCP on Windows, whose send buffering may take the whole payload in
            // one write and so leave no later slice to measure.
            static::markTestSkipped('Windows has no Unix socket pairs.');
        }

        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        $writer = new class($a) extends IO\Internal\ResourceHandle {
            /**
             * @var list<int>
             */
            public array $sizes = [];

            /**
             * @param resource $stream
             */
            public function __construct(mixed $stream)
            {
                parent::__construct($stream, read: false, write: true, seek: false, close: true);
            }

            #[Override]
            public function tryWrite(string $bytes): int
            {
                $this->sizes[] = strlen($bytes);

                return parent::tryWrite($bytes);
            }
        };

        $reader = new IO\CloseReadStreamHandle($b);
        $payload = self::payload();

        $received = Async\run(static function () use ($reader): string {
            $hash = hash_init('xxh128');
            $total = 0;
            while ($total < self::PAYLOAD_SIZE) {
                $chunk = $reader->read(65_536);
                $total += strlen($chunk);
                hash_update($hash, $chunk);
            }

            return hash_final($hash);
        });

        $start = hrtime(true);
        $writer->writeAll($payload);
        $elapsed = (hrtime(true) - $start) / 1e9;

        static::assertSame(hash('xxh128', $payload), $received->await());
        static::assertGreaterThan(1, count($writer->sizes));
        static::assertSame(self::PAYLOAD_SIZE, $writer->sizes[0]);
        static::assertLessThanOrEqual(self::MAXIMUM_SLICE_SIZE, max(array_slice($writer->sizes, 1)));
        // Copying the whole remainder on every write takes about two seconds here; the offset loop takes a
        // few tens of milliseconds.
        static::assertLessThan(1.0, $elapsed);

        $writer->close();
        $reader->close();
    }

    public function testStreamWriteOnBlockingFileWritesEverythingAtOnce(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'psl');
        static::assertNotFalse($file);

        try {
            $resource = fopen($file, 'w');
            static::assertIsResource($resource);
            $handle = new IO\WriteStreamHandle($resource);

            $payload = str_repeat('0123456789abcdef', 65_536);
            static::assertSame(strlen($payload), $handle->write($payload));

            fclose($resource);

            static::assertSame($payload, file_get_contents($file));
        } finally {
            unlink($file);
        }
    }

    private static function payload(): string
    {
        return str_repeat('0123456789abcdef', intdiv(self::PAYLOAD_SIZE, 16));
    }
}
