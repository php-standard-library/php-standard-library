<?php

declare(strict_types=1);

namespace Psl\IO;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;

use function sprintf;
use function strlen;
use function strpos;
use function substr;

/**
 * @api
 */
final class Reader implements BufferedReadHandleInterface
{
    use ReadHandleConvenienceMethodsTrait;

    private readonly ReadHandleInterface $handle;

    private bool $eof = false;
    private string $buffer = '';

    public function __construct(ReadHandleInterface $handle)
    {
        $this->handle = $handle;
    }

    /**
     * {@inheritDoc}
     *
     * When the internal buffer is empty and EOF has not been observed yet, this reads from the
     * underlying handle to find out, waiting for data if necessary.
     *
     * @param CancellationTokenInterface $cancellation Bounds the wait for the underlying handle.
     *
     * @throws CancelledException If the cancellation token is cancelled.
     *
     * @mago-expect lint:no-empty-catch-clause
     */
    #[Override]
    public function reachedEndOfDataSource(CancellationTokenInterface $cancellation = new NullCancellationToken()): bool
    {
        if ('' !== $this->buffer) {
            return false;
        }

        if ($this->eof) {
            return true;
        }

        try {
            $this->buffer = $this->handle->read(null, $cancellation);
            if ('' === $this->buffer) {
                return $this->eof = $this->handle->reachedEndOfDataSource();
            }
        } catch (Exception\ExceptionInterface) {
            // ignore; it'll be thrown again when attempting a real read.
        }

        return false;
    }

    /**
     * {@inheritDoc}
     *
     * If the operation fails or is cancelled, the bytes read so far stay buffered for the next read.
     *
     * @param ?positive-int $maxBytes the maximum number of bytes to read
     */
    #[Override]
    public function readAll(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        $toRead = $maxBytes;
        $data = '';

        try {
            do {
                $chunk = $this->read($toRead, $cancellation);
                $data .= $chunk;
                if (null !== $toRead) {
                    $toRead -= strlen($chunk);
                }
            } while ((null === $toRead || $toRead > 0) && !$this->reachedEndOfDataSource($cancellation));
        } catch (Exception\ExceptionInterface|CancelledException $e) {
            $this->buffer = $data . $this->buffer;

            throw $e;
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function readFixedSize(
        int $size,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        do {
            $length = strlen($this->buffer);
            if ($length >= $size) {
                break;
            }

            if ($this->eof) {
                throw new Exception\RuntimeException('Reached end of file before requested size.');
            }

            $toRead = $size - $length;
            $this->fillBuffer($toRead, $cancellation);
        } while (true);

        $bufferSize = strlen($this->buffer);
        if ($size === $bufferSize) {
            $ret = $this->buffer;
            $this->buffer = '';
            return $ret;
        }

        $ret = substr($this->buffer, 0, $size);
        $this->buffer = substr($this->buffer, $size);
        return $ret;
    }

    /**
     * Read a single byte from the handle.
     *
     * @throws Exception\AlreadyClosedException If the handle has been already closed.
     * @throws Exception\RuntimeException If an error occurred during the operation, or reached end of file.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    public function readByte(CancellationTokenInterface $cancellation = new NullCancellationToken()): string
    {
        // an empty read is not EOF unless the handle says so; keep waiting for data.
        while ('' === $this->buffer && !$this->eof) {
            $this->fillBuffer(null, $cancellation);
        }

        if ('' === $this->buffer) {
            throw new Exception\RuntimeException('Reached EOF without any more data.');
        }

        $ret = $this->buffer[0];
        if ($ret === $this->buffer) {
            $this->buffer = '';
            return $ret;
        }

        $this->buffer = substr($this->buffer, 1);
        return $ret;
    }

    /**
     * {@inheritDoc}
     */
    public function readLine(CancellationTokenInterface $cancellation = new NullCancellationToken()): null|string
    {
        $line = $this->readUntil("\n", $cancellation);
        if (null !== $line) {
            if ($line !== '' && $line[-1] === "\r") {
                return substr($line, 0, -1);
            }

            return $line;
        }

        $content = $this->read(null, $cancellation);
        return '' === $content ? null : $content;
    }

    /**
     * Read until the specified suffix is seen.
     *
     * The trailing suffix is read (so won't be returned by other calls), but is not
     * included in the return value.
     *
     * This call returns null if the suffix is not seen, even if there is other
     * data.
     *
     * @throws Exception\AlreadyClosedException If the handle has been already closed.
     * @throws Exception\RuntimeException If an error occurred during the operation.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    public function readUntil(
        string $suffix,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): null|string {
        $suffixLen = strlen($suffix);
        $idx = strpos($this->buffer, $suffix);
        while (false === $idx) {
            if ($this->eof) {
                return null;
            }

            // + 1 as it would have been matched in the previous iteration if it
            // fully fit in the chunk
            $offset = strlen($this->buffer) - $suffixLen + 1;
            $offset = $offset > 0 ? $offset : 0;
            $chunk = $this->handle->read(null, $cancellation);
            if ('' === $chunk) {
                $this->eof = $this->handle->reachedEndOfDataSource();

                continue;
            }

            // append as data arrives, so nothing is lost if a later read throws (e.g. is cancelled).
            $this->buffer .= $chunk;
            $idx = strpos($this->buffer, $suffix, $offset);
        }

        $buf = $this->buffer;
        $this->buffer = substr($buf, $idx + $suffixLen);

        return substr($buf, 0, $idx);
    }

    /**
     * Read until the specified suffix is seen, with a maximum number of bytes to read.
     *
     * The trailing suffix is read (so won't be returned by other calls), but is not
     * included in the return value.
     *
     * This call returns null if the suffix is not seen before EOF.
     *
     * @param positive-int $maxBytes Maximum number of bytes to read before throwing OverflowException.
     *
     * @throws Exception\AlreadyClosedException If the handle has been already closed.
     * @throws Exception\RuntimeException If an error occurred during the operation.
     * @throws CancelledException If the cancellation token is cancelled.
     * @throws Exception\OverflowException If $maxBytes is exceeded without finding the suffix.
     */
    public function readUntilBounded(
        string $suffix,
        int $maxBytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): null|string {
        $suffixLen = strlen($suffix);
        $idx = strpos($this->buffer, $suffix);
        while (false === $idx) {
            if (strlen($this->buffer) > $maxBytes) {
                throw new Exception\OverflowException(sprintf(
                    'Exceeded maximum byte limit (%d) before encountering the suffix ("%s").',
                    $maxBytes,
                    $suffix,
                ));
            }

            if ($this->eof) {
                return null;
            }

            $offset = strlen($this->buffer) - $suffixLen + 1;
            $offset = $offset > 0 ? $offset : 0;
            $chunk = $this->handle->read(null, $cancellation);
            if ('' === $chunk) {
                $this->eof = $this->handle->reachedEndOfDataSource();

                continue;
            }

            // append as data arrives, so nothing is lost if a later read throws (e.g. is cancelled).
            $this->buffer .= $chunk;
            $idx = strpos($this->buffer, $suffix, $offset);
        }

        if ($idx > $maxBytes) {
            throw new Exception\OverflowException(sprintf(
                'Exceeded maximum byte limit (%d) before encountering the suffix ("%s").',
                $maxBytes,
                $suffix,
            ));
        }

        $buf = $this->buffer;
        $this->buffer = substr($buf, $idx + $suffixLen);

        return substr($buf, 0, $idx);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        if ('' === $this->buffer) {
            if ($this->eof) {
                return '';
            }

            $this->fillBuffer(null, $cancellation);
        }

        // We either have a buffer, or reached EOF; either way, behavior matches
        // read, so just delegate
        return $this->tryRead($maxBytes);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        if ('' === $this->buffer) {
            if ($this->eof) {
                return '';
            }

            $this->buffer = $this->getHandle()->tryRead();
            if ('' === $this->buffer) {
                return '';
            }
        }

        $buffer = $this->buffer;
        if (null === $maxBytes || $maxBytes >= strlen($buffer)) {
            $this->buffer = '';
            return $buffer;
        }

        $this->buffer = substr($buffer, $maxBytes);

        return substr($buffer, 0, $maxBytes);
    }

    public function getHandle(): ReadHandleInterface
    {
        return $this->handle;
    }

    /**
     * @param null|positive-int $desiredBytes
     *
     * @throws Exception\AlreadyClosedException If the handle has been already closed.
     * @throws Exception\RuntimeException If an error occurred during the operation.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    private function fillBuffer(null|int $desiredBytes, CancellationTokenInterface $cancellation): void
    {
        $chunk = $this->handle->read($desiredBytes, $cancellation);
        $this->buffer .= $chunk;
        if ('' === $chunk) {
            $this->eof = $this->handle->reachedEndOfDataSource();
        }
    }
}
