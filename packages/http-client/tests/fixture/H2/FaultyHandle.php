<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Fixture\H2;

use Override;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Throwable;

/**
 * A bidirectional handle that forwards to another handle and can fail or stall writes on demand.
 */
final class FaultyHandle implements IO\ReadHandleInterface, IO\WriteHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    /**
     * Thrown by the next write, then cleared.
     */
    public null|Throwable $writeFailure = null;

    /**
     * While true, writes wait until their cancellation token fires.
     */
    public bool $stallWrites = false;

    public function __construct(
        private readonly IO\ReadHandleInterface&IO\WriteHandleInterface $inner,
    ) {}

    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        return $this->inner->reachedEndOfDataSource();
    }

    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        return $this->inner->tryRead($maxBytes);
    }

    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        return $this->inner->read($maxBytes, $cancellation);
    }

    #[Override]
    public function tryWrite(string $bytes): int
    {
        $this->failIfArmed();

        return $this->inner->tryWrite($bytes);
    }

    #[Override]
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        $this->failIfArmed();
        $this->stallIfRequested($cancellation);

        return $this->inner->write($bytes, $cancellation);
    }

    #[Override]
    public function writeAll(
        string $bytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $this->failIfArmed();
        $this->stallIfRequested($cancellation);
        $this->inner->writeAll($bytes, $cancellation);
    }

    private function failIfArmed(): void
    {
        if ($this->writeFailure === null) {
            return;
        }

        $failure = $this->writeFailure;
        $this->writeFailure = null;

        throw $failure;
    }

    private function stallIfRequested(CancellationTokenInterface $cancellation): void
    {
        if (!$this->stallWrites) {
            return;
        }

        /** @var Async\Deferred<null> $never */
        $never = new Async\Deferred();
        $never->getAwaitable()->await($cancellation);
    }
}
