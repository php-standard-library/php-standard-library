<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use HashContext;
use Override;
use PHPUnit\Framework\TestCase;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\Network;
use Psl\TLS;

use function array_slice;
use function fclose;
use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function hrtime;
use function intdiv;
use function max;
use function min;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use function substr;

use const PHP_OS_FAMILY;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * Writing N bytes must copy O(N) bytes, however few bytes the socket accepts per write.
 */
final class WriteAllTest extends TestCase
{
    private const int PAYLOAD_SIZE = 16 * 1024 * 1024;

    /**
     * The largest chunk writeAll() may copy out of the caller's string for one write.
     */
    private const int MAXIMUM_CHUNK_SIZE = 64 * 1024;

    public function testWriteAllPassesBoundedChunks(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('Windows emulates socket pairs over TCP, and only for STREAM_PF_INET.');
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        static::assertNotFalse($pair);
        [$resource, $peer] = $pair;
        stream_set_blocking($resource, false);

        // Takes 8 KiB per write, as a socket whose buffer is full does while the peer reads. It does not
        // record the buffers themselves: keeping 16 MiB remainders around would take gigabytes.
        $inner = new class($resource) implements Network\StreamInterface {
            use IO\ReadHandleConvenienceMethodsTrait;
            use IO\WriteHandleConvenienceMethodsTrait;

            /**
             * @var list<int>
             */
            public array $sizes = [];

            private HashContext $sent;

            /**
             * @param resource $resource
             */
            public function __construct(
                private mixed $resource,
            ) {
                $this->sent = hash_init('xxh128');
            }

            public function digest(): string
            {
                return hash_final($this->sent);
            }

            #[Override]
            public function tryWrite(string $bytes): int
            {
                $this->sizes[] = strlen($bytes);
                $written = min(8192, strlen($bytes));
                hash_update($this->sent, substr($bytes, 0, $written));

                return $written;
            }

            #[Override]
            public function write(
                string $bytes,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): int {
                return $this->tryWrite($bytes);
            }

            #[Override]
            public function reachedEndOfDataSource(): bool
            {
                return false;
            }

            #[Override]
            public function tryRead(null|int $maxBytes = null): string
            {
                return '';
            }

            #[Override]
            public function read(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return '';
            }

            #[Override]
            public function peek(
                int $maxBytes,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return '';
            }

            /**
             * @return resource
             */
            #[Override]
            public function getStream(): mixed
            {
                return $this->resource;
            }

            #[Override]
            public function getLocalAddress(): Network\Address
            {
                return Network\Address::tcp();
            }

            #[Override]
            public function getPeerAddress(): Network\Address
            {
                return Network\Address::tcp();
            }

            #[Override]
            public function shutdown(): void {}

            #[Override]
            public function isClosed(): bool
            {
                return false;
            }

            #[Override]
            public function close(): void {}
        };

        $stream = new TLS\Internal\Stream(
            $inner,
            new TLS\ConnectionState(TLS\Version::Tls13, 'TLS_AES_256_GCM_SHA384', 256, 'TLSv1.3', null, null, null),
        );
        $payload = str_repeat('0123456789abcdef', intdiv(self::PAYLOAD_SIZE, 16));

        try {
            $start = hrtime(true);
            $stream->writeAll($payload);
            $elapsed = (hrtime(true) - $start) / 1e9;
        } finally {
            fclose($resource);
            fclose($peer);
        }

        // Every byte went out once and in order, the pending records included, before writeAll() returned.
        static::assertSame(hash('xxh128', $payload), $inner->digest());
        // The first write gets the caller's string, which copies nothing; no later write gets more than 64 KiB.
        static::assertSame(self::PAYLOAD_SIZE, $inner->sizes[0]);
        static::assertLessThanOrEqual(self::MAXIMUM_CHUNK_SIZE, max(array_slice($inner->sizes, 1)));
        static::assertLessThan(1.0, $elapsed);
    }
}
