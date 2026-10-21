<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Fixture;

use Override;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

use function min;
use function strlen;
use function substr;

/**
 * Accepts at most a fixed number of bytes per write, and suspends the calling fiber for one event loop tick before
 * each waiting write, like a stream that has to wait until it is writable; `tryWrite()` never suspends.
 */
final class SuspendingWriteHandle implements IO\WriteHandleInterface
{
    use IO\WriteHandleConvenienceMethodsTrait;

    /**
     * The bytes the handle accepted.
     */
    public string $buffer = '';

    /**
     * @param positive-int $maxBytesPerWrite
     */
    public function __construct(
        private readonly int $maxBytesPerWrite,
    ) {}

    #[Override]
    public function tryWrite(string $bytes): int
    {
        $length = min(strlen($bytes), $this->maxBytesPerWrite);
        $this->buffer .= substr($bytes, 0, $length);

        return $length;
    }

    #[Override]
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        Async\later();

        return $this->tryWrite($bytes);
    }
}
