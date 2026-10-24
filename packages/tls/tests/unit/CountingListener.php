<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Network;

/**
 * A listener that counts the connections taken from the listener it wraps.
 */
final class CountingListener implements Network\ListenerInterface
{
    /**
     * The number of connections accept() has returned.
     *
     * @var int<0, max>
     */
    public private(set) int $accepted = 0;

    /**
     * @param Network\ListenerInterface $inner The listener whose connections are counted.
     */
    public function __construct(
        private readonly Network\ListenerInterface $inner,
    ) {}

    /**
     * @inheritDoc
     */
    #[Override]
    public function accept(CancellationTokenInterface $cancellation = new NullCancellationToken()): Network\StreamInterface
    {
        $stream = $this->inner->accept($cancellation);
        $this->accepted++;

        return $stream;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getLocalAddress(): Network\Address
    {
        return $this->inner->getLocalAddress();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function isClosed(): bool
    {
        return $this->inner->isClosed();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function close(): void
    {
        $this->inner->close();
    }
}
