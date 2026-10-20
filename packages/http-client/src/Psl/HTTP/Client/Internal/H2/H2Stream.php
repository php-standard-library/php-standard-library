<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal\H2;

use Closure;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\HPACK\Header;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Message\FieldMap;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\HTTP\Message\Response;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

use function strlen;
use function substr;

/**
 * Per-stream state container for an HTTP/2 exchange.
 *
 * Acts as a shared buffer and synchronization point between the
 * {@see H2Multiplexer}'s background read fiber (producer) and the consumer
 * fiber ({@see StreamExchange} for response headers, {@see ResponseBodyHandle}
 * for body data). This decoupling means the read fiber can continue processing
 * events for other streams while a consumer is still reading the body.
 *
 * Data flow:
 * 1. The multiplexer calls {@see handleHeaders()} when HEADERS frames arrive.
 *    - If this is the first non-informational response, the response deferred is resolved.
 *    - If already resolved, the headers are treated as trailing headers.
 *    - 1xx informational responses are collected separately.
 * 2. The multiplexer calls {@see handleData()} when DATA frames arrive, appending
 *    to the body buffer and waking any suspended consumer.
 * 3. The multiplexer calls {@see fail()} on error (RST_STREAM, GOAWAY, I/O failure),
 *    which propagates the error to both the response deferred and the body consumer.
 *
 * Fiber safety: the producer (multiplexer read fiber) and consumer (exchange fiber)
 * never run concurrently within a single stream because PHP fibers are cooperative.
 * The body waiter suspension mechanism provides the handoff: the consumer suspends
 * when the buffer is empty, and the producer resumes it when data arrives.
 *
 * @internal
 *
 * @see H2Multiplexer The producer that dispatches events into this stream.
 * @see StreamExchange Awaits the response headers via {@see awaitResponse()}.
 * @see ResponseBodyHandle Reads body data via {@see readBody()}.
 */
final class H2Stream
{
    /**
     * Deferred resolved when the final (non-informational) response HEADERS arrive.
     *
     * @var Async\Deferred<array{int<100, 999>, FieldMap, bool}>
     */
    private Async\Deferred $responseDeferred;

    /**
     * Collected 1xx informational responses received before the final response.
     *
     * @var list<Response>
     */
    private array $informationalResponses = [];

    /**
     * Whether the response deferred has been resolved (final headers received).
     */
    private bool $responseResolved = false;

    /**
     * Accumulated body data from DATA frames, consumed by {@see readBody()}.
     */
    private string $bodyBuffer = '';

    private int $bytesReceived = 0;

    /**
     * Whether the stream has received END_STREAM (no more data or trailers expected).
     */
    private bool $bodyComplete = false;

    /**
     * Suspended consumer fiber waiting for body data, or null if no consumer is waiting.
     */
    private null|Suspension $bodyWaiter = null;

    /**
     * Trailing headers received after the body, or null if not yet received.
     */
    private null|FieldMap $trailers = null;

    /**
     * The error that caused this stream to fail, or null if no error occurred.
     */
    private null|Throwable $error = null;

    /**
     * @param int $streamId The HTTP/2 stream identifier assigned by the connection.
     * @param null|(Closure(Response): void) $onInformationalResponse Callback invoked for each 1xx response as it arrives.
     * @param positive-int $maxResponseBodySize Maximum received and buffered body bytes.
     */
    public function __construct(
        public readonly int $streamId,
        private readonly null|Closure $onInformationalResponse = null,
        private readonly int $maxResponseBodySize = 10_485_760,
    ) {
        /** @var Async\Deferred<array{int<100, 999>, FieldMap, bool}> */
        $this->responseDeferred = new Async\Deferred();
        // An upload can fail before the exchange awaits response headers.
        $this->responseDeferred->getAwaitable()->ignore();
    }

    /**
     * Handle incoming HEADERS frames from the multiplexer.
     *
     * Three cases are handled:
     * 1. Response not yet resolved and status < 200: informational (1xx) response,
     *    collected in {@see $informationalResponses} for the transaction.
     * 2. Response not yet resolved and status >= 200: final response, resolves
     *    the deferred so {@see awaitResponse()} returns.
     * 3. Response already resolved: these are trailing headers, parsed into a
     *    {@see FieldMap} and stored in {@see $trailers}.
     *
     * If no :status pseudo-header is found, the stream is failed with a protocol
     * exception, since HTTP/2 responses must include :status per RFC 9113 Section 8.3.2.
     *
     * @param list<Header> $headers Raw HPACK-decoded headers from the H2 connection.
     * @param bool $endStream Whether the END_STREAM flag was set on this HEADERS frame.
     */
    public function handleHeaders(array $headers, bool $endStream): void
    {
        if ($this->responseResolved) {
            $this->handleTrailers($headers);
            return;
        }

        /** @var int<100, 999>|null $status */
        $status = null;
        /** @var list<list{non-empty-string, string}> $fields */
        $fields = [];

        foreach ($headers as $header) {
            if ($header->name === ':status') {
                /** @var int<100, 999> $status */
                $status = (int) $header->value;
                continue;
            }

            if ($header->name[0] === ':') {
                continue;
            }

            $fields[] = [$header->name, $header->value];
        }

        if ($status === null) {
            $this->fail(ProtocolException::forMalformedResponse('Missing :status pseudo-header.'));
            return;
        }

        if ($status < 200) {
            $informationalResponse = new Response(
                status: $status,
                protocolVersion: ProtocolVersion::V20,
                headers: FieldMap::from($fields),
            );

            if ($this->onInformationalResponse !== null) {
                ($this->onInformationalResponse)($informationalResponse);
            }

            $this->informationalResponses[] = $informationalResponse;
            return;
        }

        $this->responseResolved = true;

        if ($endStream) {
            $this->bodyComplete = true;
            $this->trailers = FieldMap::default();
        }

        $this->responseDeferred->complete([$status, FieldMap::from($fields), $endStream]);
    }

    /**
     * Handle incoming DATA frames from the multiplexer.
     *
     * Appends the data to the body buffer and wakes the consumer fiber if one
     * is suspended in {@see readBody()}. If END_STREAM is set, marks the body
     * as complete and initializes empty trailers (since no trailing HEADERS will follow).
     *
     * @param string $data The raw body data from the DATA frame.
     * @param bool $endStream Whether the END_STREAM flag was set.
     */
    public function handleData(string $data, bool $endStream): void
    {
        if ($this->error !== null) {
            return;
        }

        $length = strlen($data);
        if ($length > ($this->maxResponseBodySize - $this->bytesReceived)) {
            $this->fail(ProtocolException::forResponseBodyTooLarge($this->maxResponseBodySize));
            return;
        }

        $this->bytesReceived += $length;
        $this->bodyBuffer .= $data;

        if ($endStream) {
            $this->bodyComplete = true;
            $this->trailers ??= FieldMap::default();
        }

        $this->wakeBodyWaiter();
    }

    /**
     * Transition this stream into an error state.
     *
     * Called by the multiplexer on RST_STREAM, GOAWAY with error, or connection
     * I/O failure. Records the error, marks the body as complete, and propagates
     * the error to:
     * - The response deferred (if not yet resolved), so {@see awaitResponse()} throws.
     * - The body waiter (if a consumer is suspended), so {@see readBody()} can observe the error.
     *
     * Only the first call has any effect; subsequent calls are ignored.
     *
     * @param Throwable $error The error to propagate.
     */
    public function fail(Throwable $error): void
    {
        if ($this->error !== null) {
            return;
        }

        $this->error = $error;
        $this->bodyBuffer = '';
        $this->bodyComplete = true;

        if (!$this->responseResolved) {
            $this->responseResolved = true;
            $this->responseDeferred->error($error);
        }

        $this->wakeBodyWaiter();
    }

    /**
     * Wait for the final (non-informational) response HEADERS.
     *
     * Suspends the calling fiber until the response deferred is resolved by
     * {@see handleHeaders()} or rejected by {@see fail()}.
     *
     * @param CancellationTokenInterface $cancellation Token to cancel the wait.
     *
     * @return array{int<100, 999>, FieldMap, bool} The status code, response headers, and whether END_STREAM was set.
     *
     * @throws Throwable If the stream fails before headers arrive.
     * @throws Async\Exception\CancelledException If the cancellation token fires.
     */
    public function awaitResponse(CancellationTokenInterface $cancellation): array
    {
        return $this->responseDeferred->getAwaitable()->await($cancellation);
    }

    /**
     * Retrieve all 1xx informational responses collected before the final response.
     *
     * @return list<Response> The informational responses in the order they were received.
     */
    public function getInformationalResponses(): array
    {
        return $this->informationalResponses;
    }

    /**
     * Read body data, blocking if the buffer is empty and the stream is not complete.
     *
     * If the body buffer has data, returns up to $maxBytes immediately. If the
     * buffer is empty and the stream is not yet complete, the calling fiber is
     * suspended until the multiplexer pushes more data via {@see handleData()}
     * or the stream is failed via {@see fail()}.
     *
     * Cancellation is supported: the cancellation token subscriber resumes
     * the suspension with a {@see CancelledException}.
     *
     * @param null|int $maxBytes Maximum bytes to return, or null for all buffered data.
     * @param CancellationTokenInterface $cancellation Token to cancel the wait.
     *
     * @return string The body data (may be '' if the stream is complete and buffer is empty).
     *
     * @throws Throwable If the stream encountered an error.
     * @throws Async\Exception\CancelledException If the cancellation token fires while waiting.
     */
    public function readBody(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        $cancellation->throwIfCancelled();
        $this->throwIfErrored();

        if ($this->bodyBuffer !== '') {
            return $this->consumeBuffer($maxBytes);
        }

        if ($this->bodyComplete) {
            return '';
        }

        $suspension = EventLoop::getSuspension();
        $this->bodyWaiter = $suspension;

        $id = null;
        if ($cancellation->cancellable) {
            $id = $cancellation->subscribe(function (Async\Exception\CancelledException $e) use ($suspension): void {
                if ($this->bodyWaiter !== null) {
                    $this->bodyWaiter = null;
                    $suspension->throw($e);
                }
            });
        }

        try {
            $suspension->suspend();
        } finally {
            if ($id !== null) {
                $cancellation->unsubscribe($id);
            }

            $this->bodyWaiter = null;
        }

        $this->throwIfErrored();

        return $this->consumeBuffer($maxBytes);
    }

    /**
     * Return buffered body data without blocking.
     *
     * Returns data from the body buffer without suspending. If the buffer is
     * empty, returns '' immediately regardless of stream completion status.
     *
     * @param null|int $maxBytes Maximum bytes to return, or null for all buffered data.
     *
     * @return string The buffered data (may be '' if nothing is buffered).
     *
     * @throws Throwable If the stream previously encountered an error.
     */
    public function tryReadBody(null|int $maxBytes = null): string
    {
        $this->throwIfErrored();

        if ($this->bodyBuffer === '') {
            return '';
        }

        return $this->consumeBuffer($maxBytes);
    }

    /**
     * Whether the body is fully consumed (all data received and buffer drained).
     *
     * @return bool True if END_STREAM has been received and no buffered data remains.
     */
    public function isBodyComplete(): bool
    {
        return $this->bodyComplete && $this->bodyBuffer === '';
    }

    /**
     * Retrieve trailing headers, if received.
     *
     * @return null|FieldMap The trailing headers, or null if none have been received yet.
     */
    public function getTrailers(): null|FieldMap
    {
        return $this->trailers;
    }

    /**
     * Whether this stream has encountered an error.
     */
    public function hasError(): bool
    {
        return $this->error !== null;
    }

    /**
     * The error that caused this stream to fail, or null if healthy.
     */
    public function getError(): null|Throwable
    {
        return $this->error;
    }

    /**
     * Parse trailing HEADERS into a {@see FieldMap} and mark the body as complete.
     *
     * Pseudo-headers (names starting with ":") are filtered out per
     * RFC 9113 Section 8.1, as they are not valid in trailers.
     *
     * @param list<Header> $headers The HPACK-decoded trailing headers.
     */
    private function handleTrailers(array $headers): void
    {
        /** @var list<list{non-empty-string, string}> $fields */
        $fields = [];
        foreach ($headers as $header) {
            if ($header->name[0] === ':') {
                continue;
            }

            $fields[] = [$header->name, $header->value];
        }

        $this->trailers = FieldMap::from($fields);
        $this->bodyComplete = true;
        $this->wakeBodyWaiter();
    }

    /**
     * Resume the suspended consumer fiber, if any is waiting for body data.
     */
    private function wakeBodyWaiter(): void
    {
        if ($this->bodyWaiter !== null) {
            $suspension = $this->bodyWaiter;
            $this->bodyWaiter = null;
            $suspension->resume();
        }
    }

    /**
     * Throw the recorded error if this stream is in a failed state.
     *
     * @throws Throwable The recorded error.
     */
    private function throwIfErrored(): void
    {
        if ($this->error !== null) {
            throw $this->error;
        }
    }

    /**
     * Consume up to $maxBytes from the body buffer.
     *
     * Returns all buffered data if $maxBytes is null or greater than the buffer
     * size. Otherwise, returns exactly $maxBytes and retains the remainder.
     *
     * @param null|int $maxBytes Maximum bytes to consume, or null for all.
     *
     * @return string The consumed data (may be '' if the buffer is empty).
     */
    private function consumeBuffer(null|int $maxBytes): string
    {
        if ($this->bodyBuffer === '') {
            return '';
        }

        if ($maxBytes === null || $maxBytes >= strlen($this->bodyBuffer)) {
            $data = $this->bodyBuffer;
            $this->bodyBuffer = '';
            return $data;
        }

        $data = substr($this->bodyBuffer, 0, $maxBytes);
        $this->bodyBuffer = substr($this->bodyBuffer, $maxBytes);
        return $data;
    }
}
