<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;

use function fclose;
use function fgets;
use function fwrite;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;

use const STREAM_PF_UNIX;
use const STREAM_SOCK_DGRAM;

/**
 * A read makes exactly one native read, and a failed native read is reported as a failure, never as the end of
 * the data.
 */
final class NativeReadTest extends TestCase
{
    public function testConnectionResetIsAFailure(): void
    {
        [$server, $client, $connection] = self::connect();
        $handle = new IO\CloseReadWriteStreamHandle($client);

        fwrite($connection, 'partial-body');
        static::assertSame('partial-body', $handle->read());

        self::reset($connection, $handle);

        // A liveness check here would consume the reset, and the read below would then see a clean end of data.
        static::assertFalse($handle->reachedEndOfDataSource());

        $failure = self::readFailure($handle);
        // The failure is kept: the operating system reports a reset once, and reads a clean end of data after it.
        static::assertSame($failure, self::readFailure($handle));
        static::assertSame($failure, self::readFailure($handle));

        $handle->close();
    }

    public function testReadAllReportsConnectionReset(): void
    {
        [$server, $client, $connection] = self::connect();
        $handle = new IO\CloseReadWriteStreamHandle($client);

        fwrite($connection, 'partial-body');
        Async\sleep(Duration::milliseconds(10));
        self::reset($connection, $handle);

        // Reading the body until the connection closes must not take a truncated body for a complete one.
        $this->expectException(IO\Exception\RuntimeException::class);

        $handle->readAll();
    }

    public function testBytesBufferedBeforeTheHandleAreReadBeforeAFailure(): void
    {
        [$server, $client, $connection] = self::connect();

        fwrite($connection, "header\nbuffered-body");
        Async\sleep(Duration::milliseconds(10));
        // PHP buffers what follows the line.
        static::assertSame("header\n", fgets($client));

        $handle = new IO\CloseReadWriteStreamHandle($client);
        self::reset($connection, $handle);

        // Returning the buffered bytes together with a native read would hide that read's failure.
        static::assertSame('buffered-body', $handle->read());

        $this->expectException(IO\Exception\RuntimeException::class);

        $handle->read();
    }

    public function testConnectionCloseIsEndOfData(): void
    {
        [$server, $client, $connection] = self::connect();
        $handle = new IO\CloseReadWriteStreamHandle($client);

        fwrite($connection, 'complete-body');
        fclose($connection);

        static::assertSame('complete-body', $handle->readAll());
        static::assertTrue($handle->reachedEndOfDataSource());
        static::assertSame('', $handle->read());

        $handle->close();
        fclose($server);
    }

    public function testReadReturnsOneDatagram(): void
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0);
        $reader = new IO\CloseReadStreamHandle($a);
        $writer = new IO\CloseWriteStreamHandle($b);

        $writer->writeAll('first');
        $writer->writeAll('second');

        static::assertSame('first', $reader->tryRead(100));
        static::assertSame('second', $reader->tryRead(100));
        static::assertSame('', $reader->tryRead(100));
        static::assertFalse($reader->reachedEndOfDataSource());

        $reader->close();
        $writer->close();
    }

    /**
     * @return list{resource, resource, resource} The listening socket, the client end and the accepted end.
     */
    private static function connect(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        static::assertIsResource($server);
        $client = stream_socket_client('tcp://' . stream_socket_get_name($server, false));
        static::assertIsResource($client);
        $connection = stream_socket_accept($server);
        static::assertIsResource($connection);

        return [$server, $client, $connection];
    }

    /**
     * @return string The message of the exception the read failed with.
     */
    private static function readFailure(IO\ReadHandleInterface $handle): string
    {
        try {
            $chunk = $handle->read();
        } catch (IO\Exception\RuntimeException $exception) {
            return $exception->getMessage();
        }

        static::fail('The read returned "' . $chunk . '" instead of failing.');
    }

    /**
     * Close the accepted end abortively: closing a TCP socket that still holds received data sends a reset.
     *
     * @param resource $connection
     */
    private static function reset(mixed $connection, IO\WriteHandleInterface $handle): void
    {
        $handle->writeAll('unread');
        Async\sleep(Duration::milliseconds(10));
        fclose($connection);
        Async\sleep(Duration::milliseconds(10));
    }
}
