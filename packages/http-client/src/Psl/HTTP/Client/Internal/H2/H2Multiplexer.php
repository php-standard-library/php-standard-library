<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal\H2;

use Exception;
use Psl\Async;
use Psl\Async\Exception\CancelledException;
use Psl\Async\SignalCancellationToken;
use Psl\Async\TimeoutCancellationToken;
use Psl\DateTime\Duration;
use Psl\H2\ClientConnectionInterface;
use Psl\H2\ErrorCode;
use Psl\H2\Event;
use Psl\H2\Exception\ExceptionInterface;
use Psl\H2\Exception\StreamException;
use Psl\H2\Setting;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\Exception\RuntimeException;
use Psl\IO;

/**
 * Background frame reader and per-stream event dispatcher for HTTP/2 connections.
 *
 * Runs a dedicated read fiber that reads H2 frames in a tight loop from the
 * underlying {@see ClientConnectionInterface} and dispatches events directly to
 * the registered {@see H2Stream} objects. The read fiber is started lazily when
 * the first stream is registered and cancelled automatically when the last
 * stream is unregistered, keeping the fiber alive only while there are active
 * consumers.
 *
 * The multiplexer handles the following event types:
 * - {@see Event\HeadersReceived}: routed to the target stream for response or trailer handling.
 * - {@see Event\DataReceived}: routed to the target stream's body buffer.
 * - {@see Event\StreamReset}: fails the target stream with a protocol exception (RST_STREAM).
 * - {@see Event\StreamClosed}: removes the stream from the registry.
 * - {@see Event\GoAwayReceived}: marks the session closed and fails streams above the last-stream-id.
 *
 * Fiber safety: the read fiber runs concurrently with the exchange fibers that
 * call {@see H2Stream::readBody()}. Synchronization between the read fiber and
 * consumer fibers happens through {@see H2Stream}'s suspension mechanism.
 *
 * @internal
 *
 * @see H2Session Owns this multiplexer and manages stream concurrency.
 * @see H2Stream Per-stream state container that receives dispatched events.
 * @see StreamExchange Registers streams and sends request frames.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.8 GOAWAY Frame
 */
final class H2Multiplexer
{
    /**
     * Map of active stream ID to its {@see H2Stream} state container.
     *
     * @var array<int, H2Stream>
     */
    private array $streams = [];

    /**
     * Whether the background read fiber is currently running.
     */
    private bool $fiberRunning = false;

    /**
     * Cancellation token used to stop the background read fiber when no streams remain.
     */
    private SignalCancellationToken $fiberCancellation;

    /**
     * How long {@see cancel()} waits for its RST_STREAM write before giving up on the session.
     *
     * The frame is 13 bytes; the write only waits when the peer has stopped reading.
     */
    private const int RESET_WRITE_TIMEOUT_MILLISECONDS = 1_000;

    /**
     * The last-stream-id from the most recent GOAWAY frame, or -1 if none received.
     *
     * Streams with IDs above this value are rejected in {@see register()}.
     */
    private int $goAwayLastStreamId = -1;

    /**
     * @param ClientConnectionInterface $connection The H2 client connection to read frames from.
     * @param H2Session $session The session to mark as closed on GOAWAY or fatal error.
     */
    public function __construct(
        private readonly ClientConnectionInterface $connection,
        private readonly H2Session $session,
    ) {
        $this->fiberCancellation = new SignalCancellationToken();
    }

    /**
     * Register a stream for event dispatch and start the read fiber if needed.
     *
     * The stream is added to the active stream registry so that incoming events
     * for this stream ID are routed to it. If no read fiber is currently running,
     * one is started via {@see start()}.
     *
     * @param H2Stream $stream The stream to register. Its stream ID must not exceed the GOAWAY last-stream-id.
     *
     * @throws RuntimeException If the stream ID exceeds the GOAWAY last-stream-id (the server will not process it).
     */
    public function register(H2Stream $stream): void
    {
        if ($this->goAwayLastStreamId >= 0 && $stream->streamId > $this->goAwayLastStreamId) {
            throw new RuntimeException('HTTP/2 connection is closed.');
        }

        $this->streams[$stream->streamId] = $stream;

        $this->start();
    }

    /**
     * Unregister a stream and stop the read fiber if no streams remain.
     *
     * Called when a stream exchange completes (successfully or with error), or
     * when the {@see ResponseBodyHandle} is closed. If this was the last active
     * stream, the read fiber is cancelled to avoid spinning on an idle connection.
     *
     * @param int $streamId The stream ID to unregister.
     */
    public function unregister(int $streamId): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }

        unset($this->streams[$streamId]);
        $this->session->releaseStream();

        $this->stopIfIdle();
    }

    public function stopIfIdle(): void
    {
        if ($this->streams === [] && !$this->session->hasStreamWaiters() && $this->fiberRunning) {
            $this->fiberRunning = false;
            $this->fiberCancellation->cancel();
            $this->fiberCancellation = new SignalCancellationToken();
        }
    }

    /**
     * Cancel a stream and release its slot.
     *
     * Sends RST_STREAM(CANCEL) so the peer stops working on the stream. The
     * write is bounded: a peer that no longer reads must not block cleanup
     * after a timeout or an early body close. A write that fails or times out
     * may have left a partial frame on the wire, so the session is marked
     * closed in that case.
     *
     * @param positive-int $streamId
     */
    public function cancel(int $streamId): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }

        try {
            $this->connection->resetStream(
                $streamId,
                ErrorCode::Cancel,
                new TimeoutCancellationToken(Duration::milliseconds(self::RESET_WRITE_TIMEOUT_MILLISECONDS)),
            );
        } catch (CancelledException|ExceptionInterface|IO\Exception\RuntimeException) {
            $this->session->markClosed();
        } finally {
            $this->unregister($streamId);
        }
    }

    /**
     * Start the background read fiber that reads H2 events in a loop.
     *
     * The fiber is deferred onto the event loop via {@see Async\Scheduler::defer()}
     * so it runs concurrently with exchange fibers. It reads events from the H2
     * connection and dispatches them to per-stream consumers. The fiber exits when:
     * - The cancellation token fires (all streams unregistered).
     * - A connection error marks the session closed and fails all streams.
     * Stream errors fail only their own stream; the read loop continues.
     */
    public function start(): void
    {
        if ($this->fiberRunning) {
            return;
        }

        $this->fiberRunning = true;
        $cancel = $this->fiberCancellation;

        Async\Scheduler::defer(function () use ($cancel): void {
            try {
                while ($this->streams !== [] || $this->session->hasStreamWaiters()) {
                    try {
                        $events = $this->connection->readEvent($cancel);
                    } catch (StreamException $e) {
                        ($this->streams[$e->streamId] ?? null)?->fail($e);
                        $this->unregister($e->streamId);
                        continue;
                    }

                    foreach ($events as $event) {
                        $this->dispatch($event);
                    }
                }
            } catch (Async\Exception\CancelledException) {
                // @mago-expect lint:no-empty-catch-clause
            } catch (Exception $e) {
                $this->session->markClosed();
                $wrapped =
                    $e instanceof RuntimeException || $e instanceof ProtocolException
                        ? $e
                        : new RuntimeException($e->getMessage(), previous: $e);
                foreach ($this->streams as $sid => $stream) {
                    $stream->fail($wrapped);
                    $this->unregister($sid);
                }
            } finally {
                if ($cancel === $this->fiberCancellation) {
                    $this->fiberRunning = false;
                }
            }
        });
    }

    /**
     * Route an H2 event to the appropriate stream or handle connection-level events.
     *
     * GOAWAY events are handled at the connection level. All other events are
     * routed to the registered stream by stream ID. Events for unknown or
     * already-unregistered streams are silently discarded.
     *
     * @param Event\EventInterface $event The H2 event to dispatch.
     */
    private function dispatch(Event\EventInterface $event): void
    {
        if ($event instanceof Event\SettingsReceived) {
            $limit = $event->settings[Setting::MaxConcurrentStreams->value] ?? null;
            if ($limit !== null) {
                $this->session->updateMaxConcurrentStreams($limit);
            }

            return;
        }

        if ($event instanceof Event\GoAwayReceived) {
            $this->handleGoAway($event);
            return;
        }

        $sid = match (true) {
            $event instanceof Event\DataReceived => $event->streamId,
            $event instanceof Event\HeadersReceived => $event->streamId,
            $event instanceof Event\StreamReset => $event->streamId,
            $event instanceof Event\StreamClosed => $event->streamId,
            $event instanceof Event\PushPromiseReceived => $event->streamId,
            default => null,
        };

        if ($sid === null || !isset($this->streams[$sid])) {
            return;
        }

        $stream = $this->streams[$sid];

        if ($event instanceof Event\HeadersReceived) {
            $stream->handleHeaders($event->headers, $event->endStream);
        } elseif ($event instanceof Event\DataReceived) {
            $stream->handleData($event->data, $event->endStream);
            if ($stream->hasError()) {
                $this->cancel($sid);
            }
        } elseif ($event instanceof Event\StreamReset) {
            $error = ProtocolException::forMalformedResponse('Stream reset: ' . $event->errorCode->name);
            $stream->fail(
                $event->errorCode === ErrorCode::RefusedStream ? new UnprocessedRequestException($error) : $error,
            );
            $this->unregister($sid);
        }

        if ($event instanceof Event\StreamClosed) {
            $this->unregister($sid);
        }
    }

    /**
     * Handle a GOAWAY frame from the server (RFC 9113 Section 6.8).
     *
     * Marks the session as closed. If the error code is not NoError, all active
     * streams are failed immediately. For graceful shutdown (NoError), only
     * streams with IDs above the last-stream-id are failed, since the server
     * guarantees it will not process those streams.
     *
     * @param Event\GoAwayReceived $event The GOAWAY event containing the error code and last-stream-id.
     */
    private function handleGoAway(Event\GoAwayReceived $event): void
    {
        $this->session->markClosed();

        $this->goAwayLastStreamId = $event->lastStreamId;
        $error = $event->errorCode === ErrorCode::NoError
            ? new RuntimeException('HTTP/2 connection is closed.')
            : new ProtocolException('Connection closed by server: ' . $event->errorCode->name);

        foreach ($this->streams as $sid => $stream) {
            if ($sid <= $event->lastStreamId && $event->errorCode === ErrorCode::NoError) {
                continue;
            }

            $stream->fail($sid > $event->lastStreamId ? new UnprocessedRequestException($error) : $error);
            $this->unregister($sid);
        }

        if ($this->streams === []) {
            $this->fiberCancellation->cancel();
            $this->fiberCancellation = new SignalCancellationToken();
        }
    }
}
