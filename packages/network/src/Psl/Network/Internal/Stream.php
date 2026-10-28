<?php

declare(strict_types=1);

namespace Psl\Network\Internal;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\IO\Exception;
use Psl\IO\Internal\ResourceHandle;
use Psl\Network;
use Psl\Network\Address;
use Psl\Network\SocketScheme;
use Revolt\EventLoop;

use function fread;
use function is_resource;
use function spl_object_id;
use function stream_get_meta_data;
use function stream_select;
use function stream_socket_get_name;
use function stream_socket_recvfrom;
use function stream_socket_shutdown;

use const STREAM_PEEK;
use const STREAM_SHUT_WR;

/**
 * @internal
 *
 * @codeCoverageIgnore
 */
class Stream implements Network\StreamInterface
{
    use IO\WriteHandleConvenienceMethodsTrait;
    use IO\ReadHandleConvenienceMethodsTrait;

    private ResourceHandle $handle;
    private readonly Address $localAddress;
    private readonly Address $peerAddress;

    /**
     * The peeks waiting for the socket to become readable, each with the watcher it waits on, by the object id of
     * its record.
     *
     * {@see close()} ends them: a watcher must not outlive the socket it watches.
     *
     * @var array<int, array{Waiter, string}>
     */
    private array $peeks = [];

    /**
     * @param resource $stream
     */
    public function __construct(mixed $stream, null|Address $localAddress = null, null|Address $peerAddress = null)
    {
        $this->handle = new ResourceHandle($stream, read: true, write: true, seek: false, close: true);
        $this->localAddress = $localAddress ?? namespace\get_sock_name($stream);
        $this->peerAddress = $peerAddress ?? namespace\get_peer_name($stream);
    }

    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        return $this->handle->reachedEndOfDataSource();
    }

    /**
     * @param ?positive-int $maxBytes
     */
    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        return $this->handle->tryRead($maxBytes);
    }

    /**
     * @param ?positive-int $maxBytes
     */
    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        return $this->handle->read($maxBytes, $cancellation);
    }

    /**
     * @return int<0, max>
     */
    #[Override]
    public function tryWrite(string $bytes): int
    {
        return $this->handle->tryWrite($bytes);
    }

    /**
     * @return int<0, max>
     */
    #[Override]
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        return $this->handle->write($bytes, $cancellation);
    }

    #[Override]
    public function writeAll(
        string $bytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $this->handle->writeAll($bytes, $cancellation);
    }

    /**
     * @return resource|object|null
     */
    #[Override]
    public function getStream(): mixed
    {
        return $this->handle->getStream();
    }

    #[Override]
    public function getLocalAddress(): Address
    {
        return $this->localAddress;
    }

    #[Override]
    public function getPeerAddress(): Address
    {
        return $this->peerAddress;
    }

    /**
     * Wait until the socket has bytes to read, then return up to $maxBytes of them without consuming them: the next
     * read returns the same bytes. Once the peer has ended the stream, a peek returns an empty string.
     *
     * The wait ends when the socket becomes readable, when $cancellation is cancelled or when {@see close()} closes
     * the stream, whichever comes first. It has a record of its own, so a cancellation that arrives once the wait has
     * ended, while the fiber waits for something else, is ignored.
     *
     * A peek can wake up to find nothing to peek, as when a concurrent read took the bytes first: it then waits for
     * the next bytes. A connection that failed leaves nothing to peek too, and a reset one can fail every attempt,
     * so on a TCP socket that has lost its peer the peek fails instead of waiting again. Unix-domain sockets get no
     * such check, as their peer often has no name: a reset one reads as the end of the stream on the next attempt.
     * A socket that stays readable with nothing to peek has failed as well, as a reset one does on Windows, where it
     * keeps its peer's name: the peek tries once more, and fails if that attempt fails too.
     *
     * @param positive-int $maxBytes
     *
     * @throws Exception\AlreadyClosedException If the stream is closed before or while the peek waits.
     * @throws IO\Exception\RuntimeException If a TCP connection has failed when the peek wakes up, as when the peer
     *                                        reset it.
     * @throws CancelledException If $cancellation is cancelled before the socket becomes readable.
     */
    #[Override]
    public function peek(int $maxBytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): string
    {
        $stream = $this->handle->getStream();
        if (!is_resource($stream)) {
            throw new Exception\AlreadyClosedException('Stream handle has already been closed.');
        }

        if ($cancellation->cancellable) {
            $cancellation->throwIfCancelled();
        }

        do {
            $this->waitUntilReadable($stream, $cancellation);
            if ($this->handle->isClosed()) {
                // The stream closed after the socket became readable, before this fiber ran again.
                throw new Exception\AlreadyClosedException('Stream handle has already been closed.');
            }

            // Another reader may have taken the bytes the peek woke up for, which leaves nothing to peek: the peek
            // then waits again.
            /** @psalm-suppress MissingThrowsDocblock */
            $data = @stream_socket_recvfrom($stream, $maxBytes, STREAM_PEEK);
            if (false === $data && $this->hasFailed($stream)) {
                throw new IO\Exception\RuntimeException('Failed to peek data from stream.');
            }

            // No other fiber runs before this check, so a socket that is still readable has either received bytes
            // since the attempt, which the next attempt peeks, or failed, which makes the next attempt fail too.
            if (false === $data && self::isReadable($stream)) {
                /** @psalm-suppress MissingThrowsDocblock */
                $data = @stream_socket_recvfrom($stream, $maxBytes, STREAM_PEEK);
                if (false === $data) {
                    throw new IO\Exception\RuntimeException('Failed to peek data from stream.');
                }
            }
        } while (false === $data);

        return $data;
    }

    #[Override]
    public function shutdown(): void
    {
        $stream = $this->handle->getStream();
        if (!is_resource($stream)) {
            throw new Exception\AlreadyClosedException('Stream handle has already been closed.');
        }

        $result = @stream_socket_shutdown($stream, STREAM_SHUT_WR);
        if ($result === false) {
            throw new IO\Exception\RuntimeException('Failed to shut down the write side of the stream.');
        }
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->handle->isClosed();
    }

    /**
     * Close the stream, ending every peek, read and write that waits on it.
     *
     * Each waiting peek has its watcher cancelled, as no watcher may outlive the socket it watches, and ends with
     * `AlreadyClosedException`. The bytes already waiting on the socket are then discarded, so that closing a TCP
     * connection ends it instead of resetting it (see {@see discardUnreadBytes()}), and the handle closes the socket,
     * which ends the waiting reads and writes the same way. Closing a closed stream does nothing.
     *
     * @throws IO\Exception\RuntimeException If unable to close the socket.
     */
    #[Override]
    public function close(): void
    {
        $peeks = $this->peeks;
        $this->peeks = [];
        if ([] !== $peeks) {
            $exception = new Exception\AlreadyClosedException('Stream handle has already been closed.');
            foreach ($peeks as [$waiter, $watcher]) {
                EventLoop::cancel($watcher);
                $waiter->fail($exception);
            }
        }

        $stream = $this->handle->getStream();
        if (is_resource($stream)) {
            self::discardUnreadBytes($stream);
        }

        $this->handle->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Wait until the socket is readable, the token is cancelled or the stream is closed.
     *
     * @param resource $stream
     *
     * @throws CancelledException If the token is cancelled first.
     * @throws Exception\AlreadyClosedException If the stream is closed first.
     */
    private function waitUntilReadable(mixed $stream, CancellationTokenInterface $cancellation): void
    {
        // The wait has its own record, which the watcher, the cancellation listener and close() settle: the
        // listener may run during subscribe(), before the wait has suspended, or after the wait has ended, when the
        // fiber may already be waiting for something else.
        $waiter = new Waiter();
        $watcher = EventLoop::onReadable($stream, static function () use ($waiter): void {
            $waiter->resume();
        });

        $key = spl_object_id($waiter);
        $this->peeks[$key] = [$waiter, $watcher];
        $id = null;

        try {
            if ($cancellation->cancellable) {
                $id = $cancellation->subscribe(static function (CancelledException $exception) use ($waiter): void {
                    $waiter->fail($exception);
                });
            }

            $waiter->wait();
        } finally {
            unset($this->peeks[$key]);
            EventLoop::cancel($watcher);
            if (null !== $id) {
                $cancellation->unsubscribe($id);
            }
        }
    }

    /**
     * Whether the socket is readable right now, without waiting.
     *
     * A socket is readable when bytes wait on it, when its peer ended the stream and when the connection failed.
     *
     * @param resource $stream
     */
    private static function isReadable(mixed $stream): bool
    {
        $read = [$stream];
        $write = null;
        $except = null;

        return 1 === @stream_select($read, $write, $except, 0);
    }

    /**
     * Whether a socket that became readable but had nothing to peek has failed, rather than lost its bytes to another
     * reader.
     *
     * A connection that failed has no peer any more, while one whose bytes another reader took still has. A failed
     * connection can report its error on every attempt, as a reset one does on macOS, so the peek must not wait for
     * it again. Unix-domain sockets are left out, as their peer often has no name: a failed one reads as the end of
     * the stream on the next attempt.
     *
     * @param resource $stream
     */
    private function hasFailed(mixed $stream): bool
    {
        return SocketScheme::Unix !== $this->peerAddress->scheme && false === @stream_socket_get_name($stream, true);
    }

    /**
     * Read and discard the bytes already waiting on the socket, without waiting for more.
     *
     * Closing a TCP socket that still holds unread bytes makes the kernel reset the connection instead of ending it
     * (RFC 1122 section 4.2.2.13). The kernel then drops what this side wrote last and the peer has not received
     * yet, and the peer sees an error instead of the end of the stream. A peer that sent a greeting, a pipelined
     * request or anything else this side never read is enough.
     *
     * This only reads what has already arrived, from a non-blocking socket, and at most 1 MiB of it: it never
     * waits for the peer. Bytes that arrive later, or past that bound, still reset the connection, as before.
     *
     * @param resource $stream
     */
    private static function discardUnreadBytes(mixed $stream): void
    {
        if (stream_get_meta_data($stream)['blocked']) {
            return;
        }

        for ($i = 0; $i < 16; $i++) {
            $chunk = @fread($stream, 65_536);
            if (false === $chunk || '' === $chunk) {
                return;
            }
        }
    }
}
