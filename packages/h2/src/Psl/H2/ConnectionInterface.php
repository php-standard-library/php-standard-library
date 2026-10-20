<?php

declare(strict_types=1);

namespace Psl\H2;

use Closure;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\H2\Event\EventInterface;
use Psl\HPACK\Header;
use Psl\IO;

/**
 * Base contract for a low-level HTTP/2 connection.
 *
 * Defines the common framing operations shared by both client and server endpoints.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113
 *
 * @api
 */
interface ConnectionInterface
{
    /**
     * The role of this endpoint.
     *
     * @var Role
     */
    public Role $role { get; }

    /**
     * Allocate and return the next available stream ID for this endpoint.
     *
     * @return positive-int The next stream ID.
     */
    public function nextStreamId(): int;

    /**
     * Return the highest stream ID received from the remote peer.
     *
     * @return int<0, max> The last peer-initiated stream ID, or 0 if none received.
     */
    public function lastPeerStreamId(): int;

    /**
     * Whether the connection is still active (no GOAWAY sent or received).
     */
    public function isConnected(): bool;

    /**
     * Get the current state of a stream.
     *
     * @param positive-int $streamId The stream to query.
     *
     * @return StreamState The current state of the stream.
     */
    public function getStreamState(int $streamId): StreamState;

    /**
     * Get the number of streams currently in an active state (open or half-closed).
     *
     * @return int<0, max>
     */
    public function activeStreamCount(): int;

    /**
     * Get the available flow control send window for a stream.
     *
     * @param int<0, max> $streamId The stream to check. Pass 0 for the connection-level window.
     *
     * @return int Available bytes in the send window.
     */
    public function availableSendWindow(int $streamId): int;

    /**
     * Wait until the send window for a stream has at least $bytes available.
     *
     * Suspends the current fiber until flow control window updates are received
     * that make enough room for the requested amount, or until cancellation.
     *
     * @param int<0, max> $streamId The stream to wait for. Pass 0 for the connection-level window.
     * @param positive-int $bytes The minimum number of bytes needed in the window.
     *
     * @throws Exception\ConnectionException If the connection is closed while waiting.
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    public function waitForSendWindow(
        int $streamId,
        int $bytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void;

    /**
     * Send the connection preface and initial SETTINGS to the remote peer.
     *
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    public function initialize(CancellationTokenInterface $cancellation = new NullCancellationToken()): void;

    /**
     * Read the next frame from the connection and return resulting events.
     *
     * May return an empty list if the frame produced no user-visible events
     * (e.g. unknown frame types or internal protocol frames).
     *
     * @throws Exception\ConnectionException If the peer has closed the connection.
     * @throws Exception\RuntimeException For protocol violations, stream errors, flow control errors, or I/O failures.
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled.
     *
     * @return list<EventInterface>
     */
    public function readEvent(CancellationTokenInterface $cancellation = new NullCancellationToken()): array;

    /**
     * Send HEADERS (and CONTINUATION frames if needed) on a stream.
     *
     * @param int<0, max> $streamId The stream to send headers on.
     * @param list<Header> $headers The headers to send.
     * @param bool $endStream Whether this also ends the stream.
     *
     * @throws Exception\RuntimeException For stream state errors, flow control errors, or I/O failures.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    public function sendHeaders(
        int $streamId,
        array $headers,
        bool $endStream = false,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void;

    /**
     * Send DATA frames on a stream.
     *
     * Splits data at the peer frame-size limit. The caller must ensure
     * the data fits within the available flow control window. Use
     * {@see sendAllData()} for automatic flow control handling.
     *
     * @param int<0, max> $streamId The stream to send data on.
     * @param string $data The payload bytes to send.
     * @param bool $endStream Whether this is the final data frame on the stream.
     *
     * @throws Exception\RuntimeException For stream state errors, flow control errors, or I/O failures.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    public function sendData(
        int $streamId,
        string $data,
        bool $endStream = false,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void;

    /**
     * Write all data to a stream, handling flow control automatically.
     *
     * Splits the data into chunks that fit within the available send window,
     * waiting for window updates as needed. This is the recommended way to
     * send large response bodies or request payloads.
     *
     * @param int<0, max> $streamId The stream to send data on.
     * @param string $data The full payload to send.
     * @param bool $endStream Whether to end the stream after all data is sent.
     *
     * @throws Exception\ConnectionException If the connection is closed while sending.
     * @throws Exception\RuntimeException For stream state errors or I/O failures.
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    public function sendAllData(
        int $streamId,
        string $data,
        bool $endStream = false,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void;

    /**
     * Reject a server push by sending RST_STREAM with CANCEL on the promised stream.
     *
     * Convenience method for declining a PUSH_PROMISE. Equivalent to
     * `resetStream($promisedStreamId, ErrorCode::Cancel)`.
     *
     * @param positive-int $promisedStreamId The promised stream ID from the PushPromiseReceived event.
     *
     * @throws Exception\ProtocolException If the stream ID is invalid.
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    public function rejectPush(
        int $promisedStreamId,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void;

    /**
     * Send a SETTINGS frame to the remote peer.
     *
     * Can be called at any point during the connection to update parameters.
     * The remote peer must acknowledge the settings before they take effect.
     *
     * @param array<positive-int, non-negative-int> $settings Setting identifiers mapped to values.
     *
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.5
     */
    public function sendSettings(
        array $settings,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void;

    /**
     * Send a RST_STREAM frame to abruptly terminate a stream.
     *
     * @param int<0, max> $streamId The stream to reset.
     * @param ErrorCode $errorCode The reason for resetting the stream.
     *
     * @throws Exception\ProtocolException If the stream ID is invalid.
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    public function resetStream(
        int $streamId,
        ErrorCode $errorCode,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void;

    /**
     * Send a PING frame to the remote peer.
     *
     * @param string $opaqueData Arbitrary data to include in the PING.
     *
     * @throws Exception\ProtocolException If the opaque data is empty.
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    public function ping(
        string $opaqueData,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void;

    /**
     * Send a GOAWAY frame to initiate a graceful connection shutdown.
     *
     * @param ErrorCode $errorCode The reason for closing the connection.
     * @param string $debugData Optional diagnostic data for the remote peer.
     *
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    public function goAway(
        ErrorCode $errorCode,
        string $debugData = '',
        null|int $lastStreamId = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void;

    /**
     * Execute a callback with write buffering enabled.
     *
     * @param Closure(): void $fn The callback to execute while buffering.
     *
     * @throws Exception\ProtocolException If buffering is already active.
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled during flush.
     */
    public function buffered(Closure $fn, CancellationTokenInterface $cancellation = new NullCancellationToken()): void;
}
