<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

/**
 * A test handle that counts reads attempted after it has signalled the end of the data source,
 * either by returning an empty read at EOF or by reporting it from `reachedEndOfDataSource()`.
 *
 * Reading past EOF is not free for every source: on an interactive terminal, EOF (Ctrl-D)
 * is a one-shot event delivered as an empty read, and a further read waits for more input.
 */
final class EofTrackingHandle implements IO\ReadHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    public int $readsAfterEof = 0;

    private bool $signalledEof = false;
    private IO\MemoryHandle $inner;

    public function __construct(string $content)
    {
        $this->inner = new IO\MemoryHandle($content);
    }

    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        if ($this->signalledEof) {
            $this->readsAfterEof++;
        }

        $chunk = $this->inner->tryRead($maxBytes);
        if ('' === $chunk && $this->inner->reachedEndOfDataSource()) {
            $this->signalledEof = true;
        }

        return $chunk;
    }

    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        return $this->tryRead($maxBytes);
    }

    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        if ($this->inner->reachedEndOfDataSource()) {
            $this->signalledEof = true;
        }

        return $this->signalledEof;
    }
}
