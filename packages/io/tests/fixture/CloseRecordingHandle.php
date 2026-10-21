<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Fixture;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Throwable;

use function strlen;

/**
 * An empty, closeable read-write handle that counts how often it is closed, and can be told to fail its next close.
 */
final class CloseRecordingHandle implements IO\ReadHandleInterface, IO\WriteHandleInterface, IO\CloseHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;
    use IO\WriteHandleConvenienceMethodsTrait;

    public int $closes = 0;

    public null|Throwable $closeFailure = null;

    private bool $closed = false;

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
    public function reachedEndOfDataSource(): bool
    {
        return true;
    }

    #[Override]
    public function tryWrite(string $bytes): int
    {
        return strlen($bytes);
    }

    #[Override]
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        return strlen($bytes);
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }

    #[Override]
    public function close(): void
    {
        $this->closes++;
        $this->closed = true;

        $failure = $this->closeFailure;
        $this->closeFailure = null;
        if (null !== $failure) {
            throw $failure;
        }
    }
}
