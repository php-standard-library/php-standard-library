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

    /**
     * Connect to the given host and port, retrying a failed attempt after a backoff.
     *
     * A failure that implements {@see Network\Exception\NonRetryableExceptionInterface} is thrown at once. Each
     * backoff wait has a record of its own, which its timer and the cancellation listener settle, whichever comes
     * first, so a cancellation that arrives once the wait has ended, while the fiber waits for something else, is
     * ignored.
     *
     * @param non-empty-string $host
     * @param int<0, 65535> $port
     *
     * @throws Network\Exception\RuntimeException If every attempt failed, or an attempt failed for a reason that
     *                                             retrying cannot change.
     * @throws CancelledException If $cancellation is cancelled before an attempt succeeds.
     */
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

                self::wait($this->getDelay($attempts), $cancellation);
            }
        }
    }

    /**
     * Wait for the given delay before the next attempt, or until $cancellation is cancelled.
     *
     * The wait has its own record, which the timer and the cancellation listener settle: the listener may run during
     * subscribe(), before the wait has suspended, or after the wait has ended, when the fiber may already be waiting
     * for something else.
     *
     * @throws CancelledException If $cancellation is cancelled before the delay elapses.
     */
    private static function wait(Duration $delay, CancellationTokenInterface $cancellation): void
    {
        $waiter = new Internal\BackoffWaiter();
        $watcher = Scheduler::delay($delay, static function () use ($waiter): void {
            $waiter->resume();
        });

        $id = null;

        try {
            if ($cancellation->cancellable) {
                $id = $cancellation->subscribe(static function (CancelledException $e) use ($waiter): void {
                    $waiter->fail($e);
                });
            }

            $waiter->wait();
        } finally {
            Scheduler::cancel($watcher);
            if (null !== $id) {
                $cancellation->unsubscribe($id);
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
