<?php

declare(strict_types=1);

namespace Psl\Unix\Internal;

use Override;
use Psl\Async\CancellationTokenInterface;
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
     * @param positive-int $maxBytes
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

        $suspension = EventLoop::getSuspension();
        $readWatcher = EventLoop::onReadable($stream, static function (string $watcher) use ($suspension): void {
            EventLoop::cancel($watcher);
            $suspension->resume();
        });

        $id = null;
        if ($cancellation->cancellable) {
            $id = $cancellation->subscribe($suspension->throw(...));
        }

        try {
            $suspension->suspend();
        } finally {
            EventLoop::cancel($readWatcher);
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
