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
     * @param resource $stream
     */
    public function __construct(mixed $stream, null|Address $localAddress = null, null|Address $peerAddress = null)
    {
        $this->handle = new ResourceHandle($stream, read: true, write: true, seek: false, close: true);

        if (null !== $localAddress && null !== $peerAddress) {
            $this->localAddress = $localAddress;
            $this->peerAddress = $peerAddress;
        } else {
            // Unix sockets: one side may not have a name.
            // Server-accepted: local = path, peer = unavailable.
            // Client: local = unavailable, peer = path.
            $local = namespace\get_address($stream, peer: false);
            $peer = namespace\get_address($stream, peer: true);

            $resolved = $local ?? $peer ?? Address::unix('<anonymous>');
            $this->localAddress = $localAddress ?? $local ?? $resolved;
            $this->peerAddress = $peerAddress ?? $peer ?? $resolved;
        }
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
