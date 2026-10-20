<?php

declare(strict_types=1);

namespace Psl\IO;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;

use function str_repeat;
use function strlen;
use function substr;

use const PHP_INT_MAX;

/**
 * @api
 */
final class MemoryHandle implements WriteHandleInterface, ReadHandleInterface, SeekHandleInterface, CloseHandleInterface
{
    use WriteHandleConvenienceMethodsTrait;
    use ReadHandleConvenienceMethodsTrait;

    /**
     * @var int<0, max>
     */
    private int $offset = 0;
    private string $buffer;
    private bool $closed = false;
    private bool $reachedEof = false;

    /**
     * @psalm-external-mutation-free
     */
    public function __construct(string $buffer = '')
    {
        $this->buffer = $buffer;
    }

    /**
     * @inheritDoc
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        $this->assertHandleIsOpen();

        return $this->reachedEof;
    }

    /**
     * @param ?positive-int $maxBytes the maximum number of bytes to read
     *
     * @psalm-external-mutation-free
     *
     * @inheritDoc
     */
    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        $this->assertHandleIsOpen();

        if (null === $maxBytes) {
            $maxBytes = PHP_INT_MAX;
        }

        $length = strlen($this->buffer);
        if ($this->offset >= $length) {
            $this->reachedEof = true;

            return '';
        }

        $length -= $this->offset;
        $length = $length > $maxBytes ? $maxBytes : $length;
        $result = substr($this->buffer, $this->offset, $length);
        $this->offset += $length;

        return $result;
    }

    /**
     * @param ?positive-int $maxBytes the maximum number of bytes to read
     *
     * @psalm-external-mutation-free
     *
     * @inheritDoc
     */
    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        return $this->tryRead($maxBytes);
    }

    /**
     * @param int<0, max> $offset
     *
     * @psalm-external-mutation-free
     *
     * @inheritDoc
     */
    #[Override]
    public function seek(int $offset): void
    {
        $this->assertHandleIsOpen();

        $this->offset = $offset;
        $this->reachedEof = false;
    }

    /**
     * @return int<0, max>
     *
     * @psalm-mutation-free
     *
     * @inheritDoc
     */
    #[Override]
    public function tell(): int
    {
        $this->assertHandleIsOpen();

        return $this->offset;
    }

    /**
     * @return int<0, max>
     *
     * @psalm-external-mutation-free
     *
     * @inheritDoc
     */
    #[Override]
    public function tryWrite(string $bytes): int
    {
        $this->assertHandleIsOpen();
        $length = strlen($this->buffer);
        $bytesLength = strlen($bytes);

        if ($this->offset >= $length) {
            // Fast-path: appending at or past end of buffer
            if ($this->offset > $length) {
                /** @var non-negative-int $times */
                $times = $this->offset - $length;
                $this->buffer .= str_repeat("\0", $times);
            }

            $this->buffer .= $bytes;
            $this->offset += $bytesLength;
            return $bytesLength;
        }

        // Overwrite in the middle of the buffer
        $new = substr($this->buffer, 0, $this->offset) . $bytes;
        $offset = $this->offset + $bytesLength;
        if ($offset < $length) {
            $new .= substr($this->buffer, $offset);
        }

        $this->buffer = $new;
        $this->offset += $bytesLength;
        return $bytesLength;
    }

    /**
     * @return int<0, max>
     *
     * @psalm-external-mutation-free
     *
     * @inheritDoc
     */
    #[Override]
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        return $this->tryWrite($bytes);
    }

    /**
     * @psalm-mutation-free
     *
     * @inheritDoc
     */
    #[Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @codeCoverageIgnore
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @psalm-external-mutation-free
     *
     * @inheritDoc
     */
    #[Override]
    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * @psalm-mutation-free
     */
    public function getBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * @throws Exception\AlreadyClosedException If the handle has been already closed.
     *
     * @psalm-mutation-free
     */
    private function assertHandleIsOpen(): void
    {
        if ($this->closed) {
            throw new Exception\AlreadyClosedException('Handle has already been closed.');
        }
    }
}
