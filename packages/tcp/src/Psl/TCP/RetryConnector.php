<?php

declare(strict_types=1);

namespace Psl\TCP;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Async\Scheduler;
use Psl\DateTime;
use Psl\DateTime\Duration;
use Psl\Network;

use function implode;
use function round;

use const PHP_INT_MAX;

/**
 * A connector that retries failed connections with exponential backoff.
 *
 * Wraps any {@see ConnectorInterface} and automatically retries on failure.
 * The delay before retry n grows exponentially: backoff * multiplier^(n-1).
 *
 * The delay is capped at PHP_INT_MAX seconds (about 2.9e11 years): a longer
 * one, which large multipliers and attempt counts can produce, waits that
 * long instead. A zero or negative delay retries at once.
 *
 * @api
 */
final readonly class RetryConnector implements ConnectorInterface
{
    /**
     * The longest delay before a retry, in seconds.
     *
     * The exponential backoff is computed as a float, which large multipliers
     * and attempt counts take beyond what a {@see Duration} can hold, or to INF.
     * Such delays saturate here; no process can wait long enough to notice.
     */
    private const int MAX_DELAY_SECONDS = PHP_INT_MAX;

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
                if ($e instanceof Network\Exception\NonRetryableExceptionInterface) {
                    throw $e;
                }

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

                $suspension = Scheduler::getSuspension();
                $watcher = Scheduler::delay($this->getDelay($attempts), static function () use ($suspension): void {
                    $suspension->resume();
                });

                $id = null;
                if ($cancellation->cancellable) {
                    $id = $cancellation->subscribe(static function (CancelledException $e) use (
                        $suspension,
                        $watcher,
                    ): void {
                        Scheduler::cancel($watcher);
                        $suspension->throw($e);
                    });
                }

                try {
                    $suspension->suspend();
                } finally {
                    Scheduler::cancel($watcher);
                    if (null !== $id) {
                        $cancellation->unsubscribe($id);
                    }
                }
            }
        }
    }

    /**
     * Compute the delay before the retry that follows the given failed attempt.
     *
     * @param positive-int $attempt
     */
    private function getDelay(int $attempt): Duration
    {
        // Exponential backoff: base * multiplier^(attempt-1). Integer overflow turns the power into
        // a float, which can exceed what a Duration holds, or become INF (and 0 * INF is NAN).
        $seconds = $this->backoff->getTotalSeconds() * ($this->backoffMultiplier ** ($attempt - 1));
        if (!($seconds > 0.0)) {
            return Duration::zero();
        }

        if ($seconds >= self::MAX_DELAY_SECONDS) {
            return Duration::seconds(self::MAX_DELAY_SECONDS);
        }

        $whole = (int) $seconds;

        return Duration::fromParts(0, 0, $whole, (int) round(($seconds - $whole) * DateTime\NANOSECONDS_PER_SECOND));
    }
}
