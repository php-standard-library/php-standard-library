<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal\H2;

use Exception;
use Psl\Async;
use Psl\Async\SignalCancellationToken;
use Psl\H2\ClientConnectionInterface;
use Psl\H2\ErrorCode;
use Psl\H2\Event;
use Psl\H2\Exception\StreamException;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\Exception\RuntimeException;

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
     * one is started via {@see startReadFiber()}.
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

        if (!$this->fiberRunning) {
            $this->startReadFiber();
        }
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
        unset($this->streams[$streamId]);

        if ($this->streams === [] && $this->fiberRunning) {
            $this->fiberRunning = false;
            $this->fiberCancellation->cancel();
            $this->fiberCancellation = new SignalCancellationToken();
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
    private function startReadFiber(): void
    {
        $this->fiberRunning = true;
        $cancel = $this->fiberCancellation;

        Async\Scheduler::defer(function () use ($cancel): void {
            try {
                while ($this->streams !== []) {
                    try {
                        $events = $this->connection->readEvent($cancel);
                    } catch (StreamException $e) {
                        ($this->streams[$e->streamId] ?? null)?->fail($e);
                        unset($this->streams[$e->streamId]);
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
                foreach ($this->streams as $stream) {
                    $stream->fail($wrapped);
                }

                $this->streams = [];
            } finally {
                $this->fiberRunning = false;
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
        } elseif ($event instanceof Event\StreamReset) {
            $stream->fail(ProtocolException::forMalformedResponse('Stream reset: ' . $event->errorCode->name));
            unset($this->streams[$sid]);
        }

        if ($event instanceof Event\StreamClosed) {
            unset($this->streams[$sid]);
        }

        if ($this->streams === [] && $this->fiberRunning) {
            $this->fiberCancellation->cancel();
            $this->fiberCancellation = new SignalCancellationToken();
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

        if ($event->errorCode !== ErrorCode::NoError) {
            $error = new ProtocolException('Connection closed by server: ' . $event->errorCode->name);
            foreach ($this->streams as $stream) {
                $stream->fail($error);
            }

            $this->streams = [];
            $this->fiberCancellation->cancel();
            $this->fiberCancellation = new SignalCancellationToken();
            return;
        }

        $this->goAwayLastStreamId = $event->lastStreamId;
        $error = new RuntimeException('HTTP/2 connection is closed.');

        foreach ($this->streams as $sid => $stream) {
            if ($sid <= $event->lastStreamId) {
                continue;
            }

            $stream->fail($error);
            unset($this->streams[$sid]);
        }

        if ($this->streams === []) {
            $this->fiberCancellation->cancel();
            $this->fiberCancellation = new SignalCancellationToken();
        }
    }
}
