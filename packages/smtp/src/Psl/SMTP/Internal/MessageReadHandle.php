<?php

declare(strict_types=1);

namespace Psl\SMTP\Internal;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\Network;

/**
 * The message data that the transport writes to the connection, read from the caller's message.
 *
 * Every read is passed to the inner handle unchanged. When one fails, the handle remembers the exception and lets it
 * through, so that the transport can tell a failure to read the caller's message, which it lets through unchanged,
 * from a failure of the connection, which it reports as a connection exception.
 *
 * @internal
 */
final class MessageReadHandle implements IO\ReadHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    /**
     * The exception that a failed read threw, or null if no read failed.
     */
    public private(set) null|IO\Exception\RuntimeException|Network\Exception\RuntimeException $failure = null;

    public function __construct(
        private readonly IO\ReadHandleInterface $inner,
    ) {}

    /**
     * @throws IO\Exception\RuntimeException If reading the message fails.
     */
    public function reachedEndOfDataSource(): bool
    {
        try {
            return $this->inner->reachedEndOfDataSource();
        } catch (IO\Exception\RuntimeException|Network\Exception\RuntimeException $e) {
            $this->failure = $e;

            throw $e;
        }
    }

    /**
     * @throws IO\Exception\RuntimeException If reading the message fails.
     */
    public function tryRead(null|int $maxBytes = null): string
    {
        try {
            return $this->inner->tryRead($maxBytes);
        } catch (IO\Exception\RuntimeException|Network\Exception\RuntimeException $e) {
            $this->failure = $e;

            throw $e;
        }
    }

    /**
     * @throws IO\Exception\RuntimeException If reading the message fails.
     * @throws CancelledException If the operation is cancelled.
     */
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        try {
            return $this->inner->read($maxBytes, $cancellation);
        } catch (IO\Exception\RuntimeException|Network\Exception\RuntimeException $e) {
            $this->failure = $e;

            throw $e;
        }
    }
}
