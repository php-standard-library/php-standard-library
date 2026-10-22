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

use function fread;
use function is_resource;
use function restore_error_handler;
use function set_error_handler;
use function stream_get_meta_data;
use function stream_socket_enable_crypto;
use function strlen;
use function substr;

use const E_ALL;
use const E_DEPRECATED;
use const E_USER_DEPRECATED;

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

    /**
     * How many writable wakes in a row may end in a flush that reports no progress, see drainPending().
     *
     * Each such wake moved at least one byte of the record to the socket, and a record is at most 16,384 +
     * 2,048 bytes of ciphertext behind a 5-byte header (RFC 5246 section 6.2.3; RFC 8446 section 5.2 allows
     * less), so more wakes in a row than that mean the socket takes nothing at all.
     */
    private const int MAX_STALLED_FLUSHES = self::MAX_RECORD_PLAINTEXT + 2_048 + 5;

    /**
     * The largest part of a caller's buffer that writeAll() copies for one write.
     *
     * writeAll() keeps the caller's string and an offset into it. The first write passes the string itself,
     * which copies nothing; every later write copies at most this many bytes from the offset. Copying the whole
     * remainder after every write instead made writing N bytes cost O(N²) when the socket accepts a few
     * kilobytes at a time.
     */
    private const int MAXIMUM_WRITE_CHUNK_SIZE = 65_536;

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
     * @var Async\Sequence<array{self, string, CancellationTokenInterface, bool}, int<0, max>>
     */
    private readonly Async\Sequence $writes;

    public function __construct(
        private readonly Network\StreamInterface $inner,
        private readonly TLS\ConnectionState $state,
    ) {
        $this->localAddress = $inner->getLocalAddress();
        $this->peerAddress = $inner->getPeerAddress();

        // The stream is passed with each operation rather than captured: a captured stream would reference
        // itself through its sequence, and a stream dropped without being closed would only be destroyed, and
        // its connection closed, once the cycle collector ran. A fiber that waits for the socket in write() or
        // writeAll() is kept alive by the event loop, through the watcher it waits on, and keeps the stream
        // alive through its own frame; the fibers queued behind it are kept alive by the stream's sequence.
        $this->writes = new Async\Sequence(
            /**
             * @param array{self, string, CancellationTokenInterface, bool} $input
             *
             * @return int<0, max>
             */
            static function (array $input): int {
                [$stream, $bytes, $cancellation, $all] = $input;

                return $stream->doWrite($bytes, $cancellation, $all);
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
        return $this->writes->waitFor([$this, $bytes, $cancellation, false], $cancellation);
    }

    #[Override]
    public function writeAll(
        string $bytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $this->writes->waitFor([$this, $bytes, $cancellation, true], $cancellation);
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
        $stream = $this->inner->getStream();
        if (is_resource($stream)) {
            // Send close_notify, then let go of what the peer sent and nobody read, so that closing the socket ends
            // the connection instead of resetting it.
            @stream_socket_enable_crypto($stream, false);
            self::discardUnreadBytes($stream);
        }

        $this->inner->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Read and discard the bytes already waiting on the socket, without waiting for more.
     *
     * Closing a socket that still holds unread bytes makes the kernel reset the connection instead of ending it
     * (RFC 1122 section 4.2.2.13). The kernel then drops what this side wrote last and the peer has not received
     * yet, close_notify included, and the peer sees an error, or nothing at all, instead of the end of the stream.
     * A TLS 1.3 server sends session tickets right after the handshake, so a client that never reads leaves such
     * bytes behind. Once crypto is disabled, reads return the raw bytes of the socket.
     *
     * This only reads what has already arrived, from a non-blocking socket, and at most 1 MiB of it: it never
     * waits for the peer. Bytes that arrive later, or past that bound, still reset the connection, as before.
     *
     * @param resource $stream
     */
    private static function discardUnreadBytes(mixed $stream): void
    {
        if (stream_get_meta_data($stream)['blocked']) {
            return;
        }

        for ($i = 0; $i < 16; $i++) {
            $chunk = @fread($stream, 65_536);
            if (false === $chunk || '' === $chunk) {
                return;
            }
        }
    }

    /**
     * Run one write() or writeAll(), in sequence with the others.
     *
     * The pending record goes out first, and every byte counted by the return value has been handed to the
     * socket when this method returns.
     *
     * @return int<0, max>
     */
    private function doWrite(string $bytes, CancellationTokenInterface $cancellation, bool $all): int
    {
        $this->drainPending($cancellation);
        if ('' === $bytes) {
            return 0;
        }

        $accepted = $this->writeChunk($bytes);
        $this->drainPending($cancellation);
        if (!$all) {
            return $accepted;
        }

        // writeChunk() always accepts part of a chunk: the head the socket did not take is kept as pending, as
        // OpenSSL committed it from that chunk, and drainPending() sends it before the next chunk is written.
        $length = strlen($bytes);
        while ($accepted < $length) {
            $accepted += $this->writeChunk(substr($bytes, $accepted, self::MAXIMUM_WRITE_CHUNK_SIZE));
            $this->drainPending($cancellation);
        }

        return $accepted;
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
     * PHP reports a write of 0 bytes on a non-blocking stream both when OpenSSL wants to write
     * more and when the connection failed (`ext/openssl/xp_ssl.c`, `php_openssl_sockop_io`).
     * Only the failure comes with a warning (`php_openssl_handle_ssl_error`): a broken socket
     * reports as writable and fails again with a warning on every write. OpenSSL wants to write
     * more when the socket became writable with room for part of the record only; it took that
     * part, but PHP reports the record once it is complete. So a flush that reports no progress
     * right after the socket became writable fails the write when it raised a warning, and
     * waits for the socket again when it did not. A socket that keeps reporting as writable
     * without taking anything, and without a warning, fails the write once more wakes in a row
     * ended that way than the record has bytes; see {@see self::MAX_STALLED_FLUSHES}.
     *
     * `feof()` is not a sign of failure here: it peeks at the socket, and is true once the peer
     * has shut down its write side, while it may still read what this side writes.
     *
     * @throws CancelledException If the cancellation token is cancelled while waiting; the record stays pending.
     * @throws IO\Exception\RuntimeException If the connection failed, or the stream accepts no data although it
     *                                       keeps reporting as writable.
     */
    private function drainPending(CancellationTokenInterface $cancellation): void
    {
        if ($this->flushPending()) {
            return;
        }

        $stalled = 0;
        while (true) {
            $this->waitUntilWritable($cancellation);

            $remaining = strlen($this->pending);
            $error = null;
            set_error_handler(
                static function (int $_, string $message) use (&$error): bool {
                    $error = $message;

                    return false;
                },
                E_ALL & ~(E_DEPRECATED | E_USER_DEPRECATED),
            );

            try {
                $flushed = $this->flushPending();
            } finally {
                restore_error_handler();
            }

            if ($flushed) {
                return;
            }

            if (null !== $error) {
                $this->pending = '';
                $message = 'Stream reported as writable but accepted no data; the connection may be broken: ';

                throw new IO\Exception\RuntimeException($message . $error);
            }

            $stalled = strlen($this->pending) < $remaining ? 0 : $stalled + 1;
            if ($stalled > self::MAX_STALLED_FLUSHES) {
                $this->pending = '';

                throw new IO\Exception\RuntimeException(
                    'Stream reported as writable but accepted no data; the connection may be broken.',
                );
            }
        }
    }

    /**
     * Wait until the socket can take more bytes, or until the cancellation token is cancelled.
     *
     * @throws CancelledException If the cancellation token is cancelled while waiting.
     * @throws IO\Exception\AlreadyClosedException If the stream has been closed.
     */
    private function waitUntilWritable(CancellationTokenInterface $cancellation): void
    {
        $resource = $this->inner->getStream();
        if (!is_resource($resource)) {
            throw new IO\Exception\AlreadyClosedException('Stream handle has already been closed.');
        }

        $cancellable = $cancellation->cancellable;
        if ($cancellable) {
            $cancellation->throwIfCancelled();
        }

        // The wait has its own record, which the watcher and the cancellation listener settle: the listener may run
        // during subscribe(), before the wait has suspended, or after the wait has ended, when the fiber may already
        // be waiting for something else.
        $waiter = new Waiter();
        $watcher = EventLoop::onWritable($resource, static function () use ($waiter): void {
            $waiter->resume();
        });

        $cancellationId = null;

        try {
            if ($cancellable) {
                $cancellationId = $cancellation->subscribe(static function (CancelledException $e) use ($waiter): void {
                    $waiter->fail($e);
                });
            }

            $waiter->wait();
        } finally {
            EventLoop::cancel($watcher);
            if (null !== $cancellationId) {
                $cancellation->unsubscribe($cancellationId);
            }
        }
    }
}
