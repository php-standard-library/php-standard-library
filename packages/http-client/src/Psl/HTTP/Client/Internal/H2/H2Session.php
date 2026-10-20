<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal\H2;

use Psl\H2\ClientConnection;
use Psl\H2\ClientConnectionInterface;
use Psl\H2\Configuration;
use Psl\H2\Setting;
use Psl\HTTP\Client\Exception\RuntimeException;
use Psl\HTTP\Client\H2ClientConfiguration;
use Psl\IO;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

use function array_shift;

use const PHP_INT_MAX;

/**
 * Manages an HTTP/2 multiplexed session over a single TCP/TLS connection.
 *
 * Provides cooperative stream concurrency control per RFC 9113 Section 5.1.2
 * (SETTINGS_MAX_CONCURRENT_STREAMS). When the maximum number of active streams
 * is reached, callers of {@see acquireStream()} are suspended until a slot is
 * released. The session also owns the {@see H2Multiplexer} that dispatches
 * frames to per-stream consumers.
 *
 * Lifecycle: a session is created when a new HTTP/2 connection is established
 * and marked closed when a GOAWAY frame is received (RFC 9113 Section 6.8) or
 * when an unrecoverable error occurs. Once closed, no new streams can be opened.
 *
 * @internal
 *
 * @see H2Connection The per-exchange facade backed by this session.
 * @see H2Multiplexer Dispatches H2 events to per-stream consumers.
 */
final class H2Session
{
    public readonly ClientConnectionInterface $connection;

    public readonly H2Multiplexer $multiplexer;

    private bool $closed = false;

    private int $activeStreams = 0;

    private int $maxConcurrentStreams = PHP_INT_MAX;

    /**
     * @var list<Suspension>
     */
    private array $streamWaiters = [];

    /**
     * Initialize the HTTP/2 session: send the connection preface and SETTINGS
     * frame, then create the multiplexer for event dispatching.
     *
     * @param IO\ReadHandleInterface&IO\WriteHandleInterface $handle The underlying bidirectional stream (TCP or TLS).
     * @param H2ClientConfiguration $configuration HTTP/2-specific settings (max concurrent streams, window size, frame size, header list size).
     */
    public function __construct(
        IO\ReadHandleInterface&IO\WriteHandleInterface $handle,
        H2ClientConfiguration $configuration,
    ) {
        /** @var array<positive-int, non-negative-int> $settings */
        $settings = [
            Setting::MaxConcurrentStreams->value => $configuration->maxConcurrentStreams,
            Setting::InitialWindowSize->value => $configuration->initialWindowSize,
            Setting::MaxFrameSize->value => $configuration->maxFrameSize,
            Setting::MaxHeaderListSize->value => $configuration->maxHeaderListSize,
        ];

        $this->connection = new ClientConnection(
            $handle,
            new Configuration(
                settings: $settings,
                maxHeaderBlockSize: $configuration->maxHeaderBlockSize,
                maxReceiveWindowSize: $configuration->maxReceiveWindowSize,
            ),
        );

        $this->connection->initialize();

        $this->multiplexer = new H2Multiplexer($this->connection, $this);
    }

    /**
     * Acquire a stream slot, blocking if at the concurrent stream limit.
     *
     * Enforces the SETTINGS_MAX_CONCURRENT_STREAMS limit (RFC 9113 Section 5.1.2).
     * If all slots are in use, the calling fiber is suspended until another stream
     * completes and calls {@see releaseStream()}.
     *
     * @throws RuntimeException If the session has been closed (e.g., due to GOAWAY).
     */
    public function acquireStream(): void
    {
        if ($this->closed) {
            throw new RuntimeException('HTTP/2 connection is closed.');
        }

        $this->multiplexer->start();

        while ($this->activeStreams >= $this->maxConcurrentStreams) {
            $suspension = EventLoop::getSuspension();
            $this->streamWaiters[] = $suspension;
            $suspension->suspend();

            if ($this->closed) {
                throw new RuntimeException('HTTP/2 connection is closed.');
            }
        }

        $this->activeStreams++;
    }

    /**
     * Apply the peer's stream limit and wake waiters to check it again.
     */
    public function updateMaxConcurrentStreams(int $limit): void
    {
        $this->maxConcurrentStreams = $limit;
        $waiters = $this->streamWaiters;
        $this->streamWaiters = [];
        foreach ($waiters as $waiter) {
            $waiter->resume();
        }
    }

    public function hasStreamWaiters(): bool
    {
        return $this->streamWaiters !== [];
    }

    /**
     * Release a stream slot and wake one waiting fiber, if any.
     *
     * Must be called exactly once for every successful {@see acquireStream()} call,
     * including on error paths, to prevent stream slot leaks.
     */
    public function releaseStream(): void
    {
        if ($this->activeStreams > 0) {
            $this->activeStreams--;
        }

        $waiter = array_shift($this->streamWaiters);
        $waiter?->resume();
    }

    /**
     * Mark this session as closed, preventing new streams from being opened.
     *
     * All fibers waiting in {@see acquireStream()} are resumed so they can
     * observe the closed state and fail gracefully. Typically called when a
     * GOAWAY frame is received (RFC 9113 Section 6.8) or on unrecoverable error.
     */
    public function markClosed(): void
    {
        $this->closed = true;

        // Wake all stream waiters so they can fail.
        $waiters = $this->streamWaiters;
        $this->streamWaiters = [];
        foreach ($waiters as $waiter) {
            $waiter->resume();
        }
    }

    /**
     * Whether this session has been marked as closed.
     *
     * @return bool True if no new streams can be opened on this session.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }
}
