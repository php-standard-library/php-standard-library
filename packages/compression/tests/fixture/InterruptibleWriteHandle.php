<?php

declare(strict_types=1);

namespace Psl\Compression\Tests\Fixture;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Throwable;

use function min;
use function strlen;
use function substr;

/**
 * Accepts at most a fixed number of bytes per write, records the size of every string it is given, and can be told
 * to fail a later write.
 */
final class InterruptibleWriteHandle implements IO\WriteHandleInterface
{
    use IO\WriteHandleConvenienceMethodsTrait;

    public string $buffer = '';

    /**
     * The total size of the strings passed to the handle.
     */
    public int $given = 0;

    private null|Throwable $failure = null;

    private int $writesBeforeFailure = 0;

    /**
     * @param positive-int $maxBytesPerWrite
     */
    public function __construct(
        private readonly int $maxBytesPerWrite,
    ) {}

    /**
     * Fail the write that comes after the next `$writes` writes.
     */
    public function failAfter(int $writes, Throwable $failure): void
    {
        $this->writesBeforeFailure = $writes;
        $this->failure = $failure;
    }

    #[Override]
    public function tryWrite(string $bytes): int
    {
        $this->given += strlen($bytes);
        $length = min(strlen($bytes), $this->maxBytesPerWrite);
        $this->buffer .= substr($bytes, 0, $length);

        return $length;
    }

    #[Override]
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        if (null !== $this->failure) {
            if (0 === $this->writesBeforeFailure) {
                $failure = $this->failure;
                $this->failure = null;

                throw $failure;
            }

            $this->writesBeforeFailure--;
        }

        return $this->tryWrite($bytes);
    }
}
