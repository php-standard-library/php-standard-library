<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal\H2;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\HTTP\Message\FieldMap;
use Psl\IO;
use Psl\IO\Exception;
use Throwable;

/**
 * Lazy, pull-based read handle for HTTP/2 response bodies.
 *
 * Reads from an {@see H2Stream}'s body buffer, which is asynchronously fed
 * by the {@see H2Multiplexer}'s background read fiber. This handle has no
 * direct interaction with the read fiber; it only pulls from the stream's
 * local buffer via {@see H2Stream::readBody()} and {@see H2Stream::tryReadBody()}.
 *
 * The stream enforces the response size limit when DATA arrives, before it
 * enters the buffer. This bounds memory use even when the caller does not read.
 *
 * Lifecycle: the handle unregisters the stream from the multiplexer when the
 * body is fully consumed, when an error occurs, or when the handle is explicitly
 * closed (including via garbage collection). This ensures the multiplexer does
 * not hold references to completed streams.
 *
 * Read contract:
 * - {@see read()} blocks (suspends the fiber) until data is available or EOF.
 * - {@see tryRead()} returns only buffered data without blocking.
 * - After EOF or error, both return '' and {@see reachedEndOfDataSource()} returns true.
 * - Once in an error state, all subsequent reads throw the recorded exception.
 *
 * @internal
 *
 * @see H2Stream The per-stream state container holding the body buffer.
 * @see H2Multiplexer Dispatches DATA events that fill the stream's buffer.
 * @see StreamExchange Creates this handle when the response has a body.
 */
final class ResponseBodyHandle implements IO\ReadHandleInterface, IO\CloseHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    /**
     * Whether this handle has entered an error state.
     */
    private bool $errored = false;

    /**
     * The exception that caused the error state, if any.
     */
    private null|IO\Exception\RuntimeException $errorCause = null;

    /**
     * Whether this handle has been closed (stream unregistered from multiplexer).
     */
    private bool $closed = false;

    /**
     * @param H2Stream $stream The per-stream state container holding the body buffer.
     * @param H2Multiplexer $multiplexer The multiplexer to unregister from on close.
     * @param positive-int $streamId The HTTP/2 stream ID.
     */
    public function __construct(
        private readonly H2Stream $stream,
        private readonly H2Multiplexer $multiplexer,
        private readonly int $streamId,
    ) {}

    /**
     * Ensure the stream is unregistered from the multiplexer on garbage collection.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Retrieve HTTP/2 trailing headers, if any were received.
     *
     * Trailers are available only after the stream has received trailing HEADERS
     * with the END_STREAM flag. Returns null if no trailers were received or
     * the body has not yet been fully consumed.
     *
     * @return null|FieldMap The trailing headers, or null if none available.
     */
    public function getTrailers(): null|FieldMap
    {
        return $this->stream->getTrailers();
    }

    /**
     * Return buffered body data without blocking.
     *
     * @inheritDoc
     *
     * @throws Exception\RuntimeException If the stream previously encountered an error.
     */
    public function tryRead(null|int $maxBytes = null): string
    {
        $this->throwIfErrored();

        return $this->stream->tryReadBody($maxBytes);
    }

    /**
     * Read body data, blocking if the buffer is empty and the stream is not complete.
     *
     * Throws if the stream exceeded its size limit while receiving data.
     * Automatically closes the handle when the body is fully consumed.
     *
     * @throws Exception\RuntimeException If the stream encountered an error or the body exceeds the size limit.
     */
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        $this->throwIfErrored();

        try {
            $data = $this->stream->readBody($maxBytes, $cancellation);
        } catch (Throwable $e) {
            $this->setError($e);
            $this->throwIfErrored();
            return '';
        }

        if ($this->stream->isBodyComplete()) {
            $this->close();
        }

        return $data;
    }

    /**
     * Whether all body data has been consumed.
     *
     * @throws Exception\RuntimeException If the stream previously encountered an error.
     */
    public function reachedEndOfDataSource(): bool
    {
        $this->throwIfErrored();

        return $this->stream->isBodyComplete();
    }

    /**
     * Whether this handle has been closed.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Close this handle and unregister the stream from the multiplexer.
     *
     * Safe to call multiple times; only the first call has any effect.
     */
    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->multiplexer->unregister($this->streamId);
        }
    }

    /**
     * Transition the handle into an error state and close it.
     *
     * Wraps the original exception in an {@see Exception\RuntimeException} for
     * a consistent exception type and closes the handle to unregister from the
     * multiplexer.
     *
     * @param Throwable $cause The underlying error.
     */
    private function setError(Throwable $cause): void
    {
        $this->errored = true;
        $this->errorCause = new Exception\RuntimeException(
            'H2 stream error: ' . $cause->getMessage(),
            previous: $cause,
        );
        $this->close();
    }

    /**
     * Throw the recorded error if this handle is in an error state.
     *
     * @throws Exception\RuntimeException If the handle previously encountered an error.
     */
    private function throwIfErrored(): void
    {
        if (!$this->errored && $this->stream->hasError()) {
            $this->setError($this->stream->getError() ?? new Exception\RuntimeException('H2 stream error'));
        }

        if ($this->errored) {
            throw $this->errorCause ?? new Exception\RuntimeException('H2 stream error: unknown error');
        }
    }
}
