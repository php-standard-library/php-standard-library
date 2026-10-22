<?php

declare(strict_types=1);

namespace Psl\Unix\Internal;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\IO\Exception;
use Psl\IO\Internal\ResourceHandle;
use Psl\Network\Address;
use Psl\Unix;
use Revolt\EventLoop;

use function is_resource;
use function stream_socket_recvfrom;
use function stream_socket_shutdown;

use const STREAM_PEEK;
use const STREAM_SHUT_WR;

/**
 * @internal
 *
 * @codeCoverageIgnore
 */
final class Stream implements Unix\StreamInterface
{
    use IO\WriteHandleConvenienceMethodsTrait;
    use IO\ReadHandleConvenienceMethodsTrait;

    private ResourceHandle $handle;
    private readonly Address $localAddress;
    private readonly Address $peerAddress;

    /**
     * Wrap a connected Unix domain socket, taking ownership of it.
     *
     * @param resource $stream The connected socket, which the stream closes once it is closed or destroyed.
     * @param Address $localAddress The name the socket is bound to, or the unnamed address, see {@see unnamed_address()}.
     * @param Address $peerAddress The name of the peer's socket, or the unnamed address.
     */
    public function __construct(mixed $stream, Address $localAddress, Address $peerAddress)
    {
        $this->handle = new ResourceHandle($stream, read: true, write: true, seek: false, close: true);
        $this->localAddress = $localAddress;
        $this->peerAddress = $peerAddress;
    }

    /**
     * Wrap a socket that connected to the given path, taking ownership of it.
     *
     * The local address is the name the socket is bound to, or the unnamed address when it is not bound, as a client
     * usually is: the path it connected to is the peer's, not its own. The peer address is the name the system reports
     * for the peer, or the path the socket connected to when it reports none.
     *
     * @param resource $stream The connected socket, which the stream closes once it is closed or destroyed.
     * @param non-empty-string $path The path the socket connected to.
     */
    public static function connected(mixed $stream, string $path): self
    {
        return new self(
            $stream,
            namespace\get_address($stream, peer: false) ?? namespace\unnamed_address(),
            namespace\get_address($stream, peer: true) ?? Address::unix($path),
        );
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
     * Wait until the stream has bytes to read, then return up to $maxBytes of them without consuming them: the next
     * read returns the same bytes. Once the peer has ended the stream, a peek returns an empty string.
     *
     * The wait ends when the stream becomes readable or when $cancellation is cancelled, whichever comes first. It
     * has a record of its own, so a cancellation that arrives once the wait has ended, while the fiber waits for
     * something else, is ignored.
     *
     * @param positive-int $maxBytes
     *
     * @throws Exception\AlreadyClosedException If the stream has already been closed.
     * @throws IO\Exception\RuntimeException If the stream has nothing to peek once it has become readable.
     * @throws CancelledException If $cancellation is cancelled before the stream becomes readable.
     */
    #[Override]
    public function peek(int $maxBytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): string
    {
        $stream = $this->handle->getStream();
        if (!is_resource($stream)) {
            throw new Exception\AlreadyClosedException('Stream handle has already been closed.');
        }

        $cancellable = $cancellation->cancellable;
        if ($cancellable) {
            $cancellation->throwIfCancelled();
        }

        // The wait has its own record, which the watcher and the cancellation listener settle: the listener may run
        // during subscribe(), before the wait has suspended, or after the wait has ended, when the fiber may already
        // be waiting for something else.
        $waiter = new Waiter();
        $watcher = EventLoop::onReadable($stream, static function () use ($waiter): void {
            $waiter->resume();
        });

        $id = null;

        try {
            if ($cancellable) {
                $id = $cancellation->subscribe(static function (CancelledException $exception) use ($waiter): void {
                    $waiter->fail($exception);
                });
            }

            $waiter->wait();
        } finally {
            EventLoop::cancel($watcher);
            if (null !== $id) {
                $cancellation->unsubscribe($id);
            }
        }

        $data = @stream_socket_recvfrom($stream, $maxBytes, STREAM_PEEK);
        if ($data === false) {
            throw new IO\Exception\RuntimeException('Failed to peek data from stream.');
        }

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

    #[Override]
    public function close(): void
    {
        $this->handle->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}
