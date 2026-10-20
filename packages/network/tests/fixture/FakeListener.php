<?php

declare(strict_types=1);

namespace Psl\Network\Tests\Fixture;

use Closure;
use Override;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Network;
use Throwable;

use function array_search;
use function array_shift;
use function array_splice;

/**
 * An in-memory listener: results pushed to it are handed out by accept() in order.
 */
final class FakeListener implements Network\ListenerInterface
{
    /**
     * Number of accept() calls currently waiting for a result.
     */
    public int $waiting = 0;

    /**
     * Invoked right before a waiting accept() call returns the stream it waited for.
     *
     * @var null|(Closure(): void)
     */
    public null|Closure $beforeDelivery = null;

    private bool $closed = false;

    /**
     * @var list<Network\StreamInterface|Throwable>
     */
    private array $results = [];

    /**
     * @var list<Async\Deferred<Network\StreamInterface>>
     */
    private array $acceptors = [];

    public function push(Network\StreamInterface|Throwable $result): void
    {
        $deferred = array_shift($this->acceptors);
        if (null === $deferred) {
            $this->results[] = $result;

            return;
        }

        if ($result instanceof Throwable) {
            $deferred->error($result);
        } else {
            $deferred->complete($result);
        }
    }

    #[Override]
    public function accept(CancellationTokenInterface $cancellation = new NullCancellationToken()): Network\StreamInterface
    {
        if ($this->closed) {
            throw new Network\Exception\AlreadyStoppedException('Listener has been closed.');
        }

        $result = array_shift($this->results);
        if ($result instanceof Throwable) {
            throw $result;
        }

        if (null !== $result) {
            return $result;
        }

        /** @var Async\Deferred<Network\StreamInterface> $deferred */
        $deferred = new Async\Deferred();
        $this->acceptors[] = $deferred;
        $this->waiting++;

        try {
            $stream = $deferred->getAwaitable()->await($cancellation);
        } finally {
            $this->waiting--;
            $index = array_search($deferred, $this->acceptors, true);
            if (false !== $index) {
                array_splice($this->acceptors, $index, 1);
            }
        }

        if (null !== $this->beforeDelivery) {
            ($this->beforeDelivery)();
        }

        return $stream;
    }

    #[Override]
    public function getLocalAddress(): Network\Address
    {
        return Network\Address::tcp('127.0.0.1', 0);
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }

    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $acceptors = $this->acceptors;
        $this->acceptors = [];
        foreach ($acceptors as $deferred) {
            $deferred->error(new Network\Exception\AlreadyStoppedException('Listener has been closed.'));
        }
    }
}
