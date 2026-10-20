<?php

declare(strict_types=1);

namespace Psl\TCP;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\DateTime\Duration;
use Psl\Network;
use Revolt\EventLoop;

use function implode;

/**
 * A connector that retries failed connections with exponential backoff.
 *
 * Wraps any {@see ConnectorInterface} and automatically retries on failure.
 * The delay between attempts grows exponentially: backoff * multiplier^attempt.
 *
 * @api
 */
final readonly class RetryConnector implements ConnectorInterface
{
    private Duration $backoff;

    /**
     * @param ConnectorInterface $connector The underlying connector to use.
     * @param int<1, 10> $maxAttempts Maximum number of connection attempts.
     * @param Duration|null $backoff Base delay before first retry. Defaults to 100ms.
     * @param int<1, max> $backoffMultiplier Exponential multiplier for each subsequent retry.
     */
    public function __construct(
        private ConnectorInterface $connector,
        private int $maxAttempts = 3,
        null|Duration $backoff = null,
        private int $backoffMultiplier = 2,
    ) {
        $this->backoff = $backoff ?? Duration::milliseconds(100);
    }

    #[Override]
    public function connect(
        string $host,
        int $port,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): StreamInterface {
        $attempts = 0;
        $messages = [];

        while (true) {
            $attempts++;

            try {
                return $this->connector->connect($host, $port, $cancellation);
            } catch (Network\Exception\RuntimeException $e) {
                $messages[] = "Attempt {$attempts}: {$e->getMessage()}";

                if ($attempts >= $this->maxAttempts) {
                    $address = Network\Address::tcp($host, $port)->toString();
                    throw new Network\Exception\RuntimeException(
                        "Failed to connect to {$address} after {$attempts} attempts. " . implode(' ', $messages),
                        (int) $e->getCode(),
                        $e,
                    );
                }

                if ($cancellation->cancellable) {
                    $cancellation->throwIfCancelled();
                }

                // Exponential backoff: base * multiplier^(attempt-1)
                $delaySec = $this->backoff->getTotalSeconds() * ($this->backoffMultiplier ** ($attempts - 1));

                $suspension = EventLoop::getSuspension();
                $watcher = EventLoop::delay($delaySec, static function () use ($suspension): void {
                    $suspension->resume();
                });

                $id = null;
                if ($cancellation->cancellable) {
                    $id = $cancellation->subscribe(static function (CancelledException $e) use (
                        $suspension,
                        $watcher,
                    ): void {
                        EventLoop::cancel($watcher);
                        $suspension->throw($e);
                    });
                }

                try {
                    $suspension->suspend();
                } finally {
                    EventLoop::cancel($watcher);
                    if (null !== $id) {
                        $cancellation->unsubscribe($id);
                    }
                }
            }
        }
    }
}
