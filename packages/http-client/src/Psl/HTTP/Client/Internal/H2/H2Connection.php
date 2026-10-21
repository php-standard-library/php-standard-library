<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal\H2;

use Closure;
use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\H2;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\ConnectionInterface;
use Psl\HTTP\Client\Connection\ConnectionMetadata;
use Psl\HTTP\Client\Exception;
use Psl\HTTP\Message;
use Psl\HTTP\Message\Request;
use Psl\HTTP\Message\Transaction;
use Psl\IO;
use Psl\Network;
use Psl\TLS;

use function in_array;

/**
 * HTTP/2 connection implementation backed by a multiplexed {@see H2Session}.
 *
 * Represents a single logical HTTP/2 connection that delegates exchanges to a
 * shared {@see H2Session}, which manages stream concurrency and frame I/O over
 * one TCP/TLS connection. Each exchange opens a new HTTP/2 stream via
 * {@see StreamExchange::exchange()}.
 *
 * If the session is already closed, the connection reconnects before sending
 * the request. It retries an exchange at most once, only when the body is
 * absent or rewinds to its start, and either the peer proved it did not
 * process the request (REFUSED_STREAM, a stream above GOAWAY's last-stream-id,
 * or no HEADERS written) for any method, or the request is replayable (GET,
 * HEAD, OPTIONS, TRACE, or an Idempotency-Key header) and the session failed
 * at the connection level before any response headers arrived (RFC 9110
 * Section 9.2.2, RFC 9113 Section 8.7).
 *
 * Unlike {@see H1\H1Connection}, HTTP/2 connections do not need
 * {@see finalize()} logic because stream multiplexing eliminates the
 * one-request-per-connection constraint; there is no connection to "release
 * back to the pool" after each exchange.
 *
 * @internal
 *
 * @see H2Session Manages stream concurrency and the underlying H2 wire connection.
 * @see StreamExchange Performs the per-stream request/response exchange.
 * @see H2Multiplexer Dispatches H2 events to per-stream consumers.
 */
final readonly class H2Connection implements ConnectionInterface
{
    /**
     * @param H2Session $session The shared HTTP/2 session managing stream concurrency.
     * @param ConnectionMetadata $metadata Connection metadata (local/peer addresses and TLS state).
     * @param null|(Closure(Request, ClientConfiguration, CancellationTokenInterface): ConnectionInterface) $reconnect Closure to obtain a fresh connection from the pool when this session is closed. Null if reconnection is not supported (e.g., non-pooled connections).
     */
    public function __construct(
        private H2Session $session,
        public ConnectionMetadata $metadata,
        private null|Closure $reconnect = null,
    ) {}

    /**
     * Execute an HTTP/2 request/response exchange on a new stream.
     *
     * Opens a new HTTP/2 stream on the underlying {@see H2Session} and performs
     * the exchange via {@see StreamExchange::exchange()}. If the session is closed
     * before the exchange, a reconnect closure can supply a fresh connection.
     * Retries once only when the peer proves it did not process the request and
     * the body is absent or can be rewound to its original position.
     *
     * Errors from the H2 layer ({@see H2\Exception\ExceptionInterface}) are
     * wrapped in {@see Exception\RuntimeException} for a consistent exception
     * hierarchy.
     *
     * @throws Exception\RequestException If the request URL is missing.
     * @throws Exception\ProtocolException If the server sends a malformed response or a stream is reset.
     * @throws Exception\RuntimeException If the H2 connection is closed and reconnection fails, or an H2 framing error occurs.
     * @throws IO\Exception\RuntimeException If an I/O error occurs on the underlying stream.
     * @throws Network\Exception\RuntimeException If a network-level error occurs.
     * @throws CancelledException If the cancellation token fires.
     */
    #[Override]
    public function exchange(
        Request $request,
        ClientConfiguration $configuration,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): Transaction {
        return $this->exchangeWithRetry($request, $configuration, $cancellation, true);
    }

    private function exchangeWithRetry(
        Request $request,
        ClientConfiguration $configuration,
        CancellationTokenInterface $cancellation,
        bool $retry,
    ): Transaction {
        if ($this->session->isClosed()) {
            return $this->reconnectAndExchange($request, $configuration, $cancellation, $retry);
        }

        $url = $request->url ?? throw Exception\RequestException::forMissingUrl();
        $body = $request->body;
        $position = null;
        if ($retry && $body instanceof IO\SeekHandleInterface) {
            try {
                $position = $body->tell();
            } catch (IO\Exception\RuntimeException) {
                // @mago-expect lint:no-empty-catch-clause - The request can proceed without retry support.
            }
        }

        // The body can be resent only if it is absent or rewinds to its start.
        $rewindable = $body === null || $body instanceof IO\SeekHandleInterface && $position !== null;
        $canRetry = $retry && $this->reconnect !== null && $rewindable;

        try {
            return StreamExchange::exchange($this->session, $request, $url, $configuration, $cancellation);
        } catch (CancelledException $e) {
            throw $e;
        } catch (UnprocessedRequestException $e) {
            // Case (a): the peer proved it did not process the request (REFUSED_STREAM,
            // a stream above GOAWAY's last-stream-id, or no HEADERS written). Any method.
            if ($canRetry && $this->rewind($body, $position)) {
                return $this->reconnectAndExchange($request, $configuration, $cancellation, false);
            }

            throw $e->failure;
        } catch (
            Exception\RuntimeException|IO\Exception\RuntimeException|Network\Exception\RuntimeException|H2\Exception\ExceptionInterface $e
        ) {
            // Case (b): a connection-level failure before any response headers, on a
            // reused session, is replayable for an idempotent or idempotency-keyed
            // request (RFC 9110 Section 9.2.2; Go net/http). A stream reset leaves the
            // session open, so isClosed() excludes it; request-body read errors do too.
            if (
                $canRetry
                && $this->session->isClosed()
                && self::isReplayable($request)
                && $this->rewind($body, $position)
            ) {
                return $this->reconnectAndExchange($request, $configuration, $cancellation, false);
            }

            throw $e instanceof H2\Exception\ExceptionInterface
                ? new Exception\RuntimeException($e->getMessage(), previous: $e)
                : $e;
        }
    }

    /**
     * Rewind a seekable body to its recorded position before a replay.
     *
     * A null body needs no rewind. A seekable body with a recorded position is
     * sought back; any other body cannot be replayed.
     *
     * @param null|int<0, max> $position
     */
    private function rewind(null|IO\ReadHandleInterface $body, null|int $position): bool
    {
        if ($body === null) {
            return true;
        }

        if (!$body instanceof IO\SeekHandleInterface || $position === null) {
            return false;
        }

        try {
            $body->seek($position);

            return true;
        } catch (IO\Exception\RuntimeException) {
            return false;
        }
    }

    /**
     * Whether a connection-level failure may be replayed for this request.
     *
     * Safe/idempotent methods qualify, as does any request carrying an
     * `Idempotency-Key` header (RFC 9110 Section 9.2.2).
     */
    private static function isReplayable(Request $request): bool
    {
        if ($request->headers->has('idempotency-key')) {
            return true;
        }

        return in_array(
            $request->method,
            [Message\METHOD_GET, Message\METHOD_HEAD, Message\METHOD_OPTIONS, Message\METHOD_TRACE],
            true,
        );
    }

    /**
     * Return the transaction as-is.
     *
     * HTTP/2 connections do not require finalization because stream multiplexing
     * removes the need for pool release wrapping. The response body is read
     * directly from the {@see H2Stream} buffer, and stream cleanup is handled
     * by the {@see H2Multiplexer} and {@see ResponseBodyHandle}.
     *
     * {@inheritDoc}
     */
    #[Override]
    public function finalize(Transaction $transaction): Transaction
    {
        return $transaction;
    }

    /**
     * Obtain a fresh connection from the pool and retry the exchange.
     *
     * Called when the current session is closed (GOAWAY or I/O failure) and a
     * reconnect closure is available. The reconnect closure is responsible for
     * returning a fully initialized {@see ConnectionInterface}, which may be
     * another H2Connection or even an H1Connection depending on ALPN negotiation.
     *
     * @throws Exception\RuntimeException If no reconnect closure is available.
     * @throws CancelledException If the cancellation token fires during reconnection.
     */
    private function reconnectAndExchange(
        Request $request,
        ClientConfiguration $configuration,
        CancellationTokenInterface $cancellation,
        bool $retry,
    ): Transaction {
        if ($this->reconnect === null) {
            throw new Exception\RuntimeException('HTTP/2 connection is closed.');
        }

        $connection = ($this->reconnect)($request, $configuration, $cancellation);

        if ($connection instanceof self) {
            return $connection->exchangeWithRetry($request, $configuration, $cancellation, $retry);
        }

        return $connection->exchange($request, $configuration, $cancellation);
    }
}
