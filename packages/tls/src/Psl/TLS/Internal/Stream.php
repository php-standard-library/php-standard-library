<?php

declare(strict_types=1);

namespace Psl\TLS\Internal;

use Override;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\Network;
use Psl\TLS;
use Revolt\EventLoop;

use function is_resource;
use function stream_socket_enable_crypto;
use function strlen;
use function substr;

/**
 * TLS-wrapped stream implementation.
 *
 * Wraps an existing stream that has had TLS enabled on it. Delegates all I/O
 * to the inner stream and adds TLS connection state + proper TLS shutdown on close.
 *
 * Writes need one extra rule. OpenSSL encrypts a write into a TLS record before it
 * touches the socket; when the socket is not writable, PHP reports the write as short
 * (`ext/openssl/xp_ssl.c`, `php_openssl_sockop_io` maps `SSL_ERROR_WANT_WRITE` to 0
 * bytes) although that record is already committed and will be sent ahead of whatever
 * the next write passes. OpenSSL requires the retry to carry the same bytes
 * (SSL_write(3), `SSL_MODE_ACCEPT_MOVING_WRITE_BUFFER`); it does not check them, so a
 * retry with other bytes silently corrupts the byte stream. This class therefore keeps
 * the unwritten head of every short write, reports it as accepted, and sends it before
 * any later bytes.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
final class Stream implements TLS\StreamInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    /**
     * Maximum plaintext size of one TLS record (RFC 8446 section 5.1, RFC 5246 section
     * 6.2.1), and OpenSSL's default `max_send_fragment`: a short write commits at most
     * this many bytes of its unwritten remainder.
     */
    private const int MAX_RECORD_PLAINTEXT = 16_384;

    private readonly Network\Address $localAddress;
    private readonly Network\Address $peerAddress;

    /**
     * Bytes already reported as written that may still sit in a committed TLS record.
     *
     * They are sent, unchanged and before anything else, by the next write operation.
     */
    private string $pending = '';

    /**
     * Serializes write() and writeAll() so that the pending record is handled by one fiber at a time.
     *
     * @var Async\Sequence<array{string, CancellationTokenInterface, bool}, int<0, max>>
     */
    private readonly Async\Sequence $writes;

    public function __construct(
        private readonly Network\StreamInterface $inner,
        private readonly TLS\ConnectionState $state,
    ) {
        $this->localAddress = $inner->getLocalAddress();
        $this->peerAddress = $inner->getPeerAddress();
        $this->writes = new Async\Sequence(
            /**
             * @param array{string, CancellationTokenInterface, bool} $input
             *
             * @return int<0, max>
             */
            function (array $input): int {
                [$bytes, $cancellation, $all] = $input;

                $this->drainPending($cancellation);
                if ('' === $bytes) {
                    return 0;
                }

                if (!$all) {
                    $accepted = $this->writeChunk($bytes);
                    $this->drainPending($cancellation);

                    return $accepted;
                }

                $total = 0;
                while ('' !== $bytes) {
                    $accepted = $this->writeChunk($bytes);
                    $total += $accepted;
                    $bytes = substr($bytes, $accepted);
                    $this->drainPending($cancellation);
                }

                return $total;
            },
        );
    }

    #[Override]
    public function getState(): TLS\ConnectionState
    {
        return $this->state;
    }

    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        return $this->inner->reachedEndOfDataSource();
    }

    /**
     * @param ?positive-int $maxBytes
     */
    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        return $this->inner->tryRead($maxBytes);
    }

    /**
     * @param ?positive-int $maxBytes
     */
    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        return $this->inner->read($maxBytes, $cancellation);
    }

    /**
     * Try to write without waiting.
     *
     * Returns the number of bytes accepted. Up to one TLS record of them may still be held
     * back by OpenSSL; it is sent before any later write. Returns 0 while that record
     * cannot be sent yet, or while a write() or writeAll() is in progress on another fiber.
     *
     * @return int<0, max>
     */
    #[Override]
    public function tryWrite(string $bytes): int
    {
        if ($this->writes->hasOngoingOperations()) {
            return 0;
        }

        if (!$this->flushPending()) {
            return 0;
        }

        return $this->writeChunk($bytes);
    }

    /**
     * Write, waiting if necessary.
     *
     * Every byte counted by the return value has been handed to the socket when this
     * method returns: the pending TLS record is sent first, and the record left by this
     * write is sent before returning.
     *
     * @return int<0, max>
     */
    #[Override]
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        return $this->writes->waitFor([$bytes, $cancellation, false], $cancellation);
    }

    #[Override]
    public function writeAll(
        string $bytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $this->writes->waitFor([$bytes, $cancellation, true], $cancellation);
    }

    /**
     * @return resource|object|null
     */
    #[Override]
    public function getStream(): mixed
    {
        return $this->inner->getStream();
    }

    #[Override]
    public function getLocalAddress(): Network\Address
    {
        return $this->localAddress;
    }

    #[Override]
    public function getPeerAddress(): Network\Address
    {
        return $this->peerAddress;
    }

    /**
     * @param positive-int $maxBytes
     */
    #[Override]
    public function peek(int $maxBytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): string
    {
        return $this->inner->peek($maxBytes, $cancellation);
    }

    /**
     * @throws IO\Exception\RuntimeException If a TLS record accepted by tryWrite() could not be sent yet;
     *                                       write() or writeAll() send it before returning.
     */
    #[Override]
    public function shutdown(): void
    {
        if (!$this->flushPending()) {
            throw new IO\Exception\RuntimeException(
                'Cannot shut down the write side of a TLS stream while a TLS record is still pending; '
                . 'call write() or writeAll() first.',
            );
        }

        $this->inner->shutdown();
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->inner->isClosed();
    }

    #[Override]
    public function close(): void
    {
        // Send TLS close_notify before closing the underlying stream.
        $stream = $this->inner->getStream();
        if (is_resource($stream)) {
            @stream_socket_enable_crypto($stream, false);
        }

        $this->inner->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Write one chunk, keeping the committed head of the unwritten remainder as pending.
     *
     * @return int<0, max> The number of bytes accepted: written, plus kept as pending.
     */
    private function writeChunk(string $bytes): int
    {
        if ('' === $bytes) {
            return 0;
        }

        $written = $this->inner->tryWrite($bytes);
        if ($written >= strlen($bytes)) {
            return $written;
        }

        $this->pending = substr($bytes, $written, self::MAX_RECORD_PLAINTEXT);

        return $written + strlen($this->pending);
    }

    /**
     * Try to send the pending record without waiting.
     *
     * @return bool Whether nothing is pending afterwards.
     */
    private function flushPending(): bool
    {
        if ('' === $this->pending) {
            return true;
        }

        $written = $this->inner->tryWrite($this->pending);
        $this->pending = substr($this->pending, $written);

        return '' === $this->pending;
    }

    /**
     * Send the pending record, waiting for the socket to become writable as needed.
     *
     * A fatal SSL error is reported by PHP as a write of 0 bytes on a non-blocking stream
     * (`ext/openssl/xp_ssl.c`, `php_openssl_sockop_io`), and a broken socket reports as
     * writable, so a record that makes no progress right after the socket became writable
     * means the connection is gone; waiting again would spin.
     *
     * @throws CancelledException If the cancellation token is cancelled while waiting; the record stays pending.
     * @throws IO\Exception\RuntimeException If the stream accepts no data although it reported as writable.
     */
    private function drainPending(CancellationTokenInterface $cancellation): void
    {
        while (!$this->flushPending()) {
            $this->waitUntilWritable($cancellation);

            $remaining = strlen($this->pending);
            if ($this->flushPending()) {
                return;
            }

            if (strlen($this->pending) === $remaining) {
                $this->pending = '';

                throw new IO\Exception\RuntimeException(
                    'Stream reported as writable but accepted no data; the connection may be broken.',
                );
            }
        }
    }

    /**
     * @throws CancelledException If the cancellation token is cancelled while waiting.
     * @throws IO\Exception\AlreadyClosedException If the stream has been closed.
     */
    private function waitUntilWritable(CancellationTokenInterface $cancellation): void
    {
        $resource = $this->inner->getStream();
        if (!is_resource($resource)) {
            throw new IO\Exception\AlreadyClosedException('Stream handle has already been closed.');
        }

        if ($cancellation->cancellable) {
            $cancellation->throwIfCancelled();
        }

        $suspension = EventLoop::getSuspension();
        $watcher = EventLoop::onWritable($resource, static function (string $watcher) use ($suspension): void {
            EventLoop::cancel($watcher);
            $suspension->resume();
        });

        $cancellationId = null;
        if ($cancellation->cancellable) {
            $cancellationId = $cancellation->subscribe(static function (CancelledException $e) use (
                $watcher,
                $suspension,
            ): void {
                EventLoop::cancel($watcher);
                $suspension->throw($e);
            });
        }

        try {
            $suspension->suspend();
        } finally {
            EventLoop::cancel($watcher);
            if (null !== $cancellationId) {
                $cancellation->unsubscribe($cancellationId);
            }
        }
    }
}
