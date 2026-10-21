<?php

declare(strict_types=1);

namespace Psl\IO;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

/**
 * @codeCoverageIgnore
 *
 * @api
 */
final class SeekWriteStreamHandle implements StreamHandleInterface, WriteHandleInterface, SeekHandleInterface
{
    use IO\WriteHandleConvenienceMethodsTrait;

    private StreamHandleInterface&WriteHandleInterface&SeekHandleInterface $handle;

    /**
     * The handle borrows the stream: it never closes it. It makes the stream nonblocking; once the last handle
     * over the stream is released, or at the end of an orderly shutdown, the stream gets back the blocking mode it
     * had, which other processes sharing it may rely on.
     *
     * @param resource $stream
     */
    public function __construct(mixed $stream)
    {
        $this->handle = new Internal\ResourceHandle($stream, read: false, write: true, seek: true, close: false);
    }

    /**
     * @return int<0, max>
     *
     * @inheritDoc
     */
    #[Override]
    public function tryWrite(string $bytes): int
    {
        return $this->handle->tryWrite($bytes);
    }

    /**
     * @return int<0, max>
     *
     * @inheritDoc
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
     * @param int<0, max> $offset
     *
     * @inheritDoc
     */
    #[Override]
    public function seek(int $offset): void
    {
        $this->handle->seek($offset);
    }

    /**
     * @return int<0, max>
     *
     * @inheritDoc
     */
    #[Override]
    public function tell(): int
    {
        return $this->handle->tell();
    }

    /**
     * @return resource|object|null
     *
     * @inheritDoc
     */
    #[Override]
    public function getStream(): mixed
    {
        return $this->handle->getStream();
    }
}
