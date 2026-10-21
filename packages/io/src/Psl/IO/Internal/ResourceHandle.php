<?php

declare(strict_types=1);

namespace Psl\IO\Internal;

use Override;
use Psl;
use Psl\Async;
use Psl\IO;
use Psl\IO\Exception;
use Revolt\EventLoop;

use function error_clear_last;
use function error_get_last;
use function fclose;
use function feof;
use function fread;
use function fseek;
use function ftell;
use function fwrite;
use function is_resource;
use function str_contains;
use function stream_get_meta_data;
use function stream_set_blocking;
use function stream_set_read_buffer;
use function stream_set_write_buffer;
use function strlen;
use function strpbrk;
use function substr;

/**
 * @internal
 *
 * @codeCoverageIgnore
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-properties
 */
class ResourceHandle implements
    IO\ReadHandleInterface,
    IO\WriteHandleInterface,
    IO\SeekHandleInterface,
    IO\CloseHandleInterface,
    IO\StreamHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;
    use IO\WriteHandleConvenienceMethodsTrait;

    public const int DEFAULT_READ_BUFFER_SIZE = 4096;
    public const int MAXIMUM_READ_BUFFER_SIZE = 786_432;

    /**
     * The largest part of a caller's buffer copied for one native write.
     *
     * Write loops keep the caller's string and an offset into it. The first attempt passes the string itself,
     * which copies nothing; every later attempt copies at most this many bytes from the offset. Copying the
     * whole remainder on every attempt instead made writing N bytes cost O(N²) when the stream accepts a few
     * kilobytes at a time.
     */
    private const int MAXIMUM_WRITE_CHUNK_SIZE = 65_536;

    /**
     * @var closed-resource|resource|null $stream
     */
    protected mixed $stream;

    /**
     * @var null|Async\Sequence<array{self, string, Async\CancellationTokenInterface, bool}, int<0, max>>
     */
    private null|Async\Sequence $writeSequence = null;

    /**
     * The write waiting for the stream to become writable, if any.
     */
    private null|Waiter $writeWaiter = null;

    private string $writeWatcher = 'invalid';

    /**
     * @var null|Async\Sequence<array{self, null|int<1, max>, Async\CancellationTokenInterface}, string>
     */
    private null|Async\Sequence $readSequence = null;

    /**
     * The read waiting for the stream to become readable, if any.
     */
    private null|Waiter $readWaiter = null;

    private string $readWatcher = 'invalid';

    private bool $reachedEof = false;
    private bool $blocks;

    /**
     * Whether the stream is a socket, whose EOF bit is read without feof(); see {@see hasReachedNativeEof()}.
     */
    private bool $socket = false;

    /**
     * Bytes PHP had already buffered when the handle was created and that no read has returned yet.
     */
    private int $buffered = 0;

    /**
     * The error of the read that failed, once one failed: every later read fails with it again.
     */
    private null|string $readError = null;

    /**
     * The handle's membership of the {@see BlockingModeRegistry}, while it has one.
     */
    private null|int $membership = null;

    /**
     * @param resource $stream
     */
    public function __construct(
        mixed $stream,
        bool $read,
        bool $write,
        bool $seek,
        private readonly bool $close,
    ) {
        $this->stream = $stream;

        $meta = stream_get_meta_data($stream);
        if ($seek) {
            $seekable = $meta['seekable'];

            Psl\invariant($seekable, 'Handle is not seekable.');
        }

        if ($read) {
            $readable = str_contains($meta['mode'], 'r') || str_contains($meta['mode'], '+');

            Psl\invariant($readable, 'Handle is not readable.');
        }

        if ($write) {
            $writable = false !== strpbrk($meta['mode'], 'xwca+');

            Psl\invariant($writable, 'Handle is not writeable.');
        }

        // A stream this handle does not close may be shared with other code and other processes, which expect it in
        // the mode they left it in; so may a duplicate of a standard stream that this handle closes. Either joins the
        // registry before it changes the mode, which puts the mode back once the last handle over the stream is
        // released.
        $membership = $close ? BlockingModeRegistry::claim($stream) : BlockingModeRegistry::join($stream);
        if (stream_set_blocking($stream, false)) {
            $this->membership = $membership;
        } elseif (null !== $membership) {
            // The mode did not change, so there is nothing to put back.
            BlockingModeRegistry::leave($membership);
        }

        $meta = stream_get_meta_data($stream);
        if ($read) {
            $this->socket = str_contains($meta['stream_type'], 'socket');
            $this->buffered = $meta['unread_bytes'];
        }

        /** @mago-expect analysis:redundant-null-coalesce */
        $this->blocks = ($meta['blocked'] ?? true) || ($meta['wrapper_type'] ?? '') === 'plainfile';

        if ($read) {
            stream_set_read_buffer($stream, 0);

            // The event loop keeps this callback for as long as the watcher exists, and the watcher only goes
            // away when the handle is closed. The callback must not reference the handle: a handle dropped
            // without being closed would never be destroyed, and its stream would stay open. It shares the
            // waiting read by reference instead, which keeps a fiber waiting in read() alive, and with it the
            // handle that fiber uses.
            $readWaiter = &$this->readWaiter;
            $this->readWatcher = EventLoop::onReadable($stream, static function () use (&$readWaiter): void {
                $waiter = $readWaiter;
                $readWaiter = null;
                $waiter?->resume();
            });

            // The handle is passed with each operation rather than captured, for the same reason: a captured
            // handle would reference itself through its sequence, and only the cycle collector could free it.
            $this->readSequence = new Async\Sequence(
                /**
                 * @param array{self, null|int<1, max>, Async\CancellationTokenInterface} $input
                 */
                static function (array $input): string {
                    [$handle, $maxBytes, $cancellation] = $input;

                    return $handle->doRead($maxBytes, $cancellation);
                },
            );

            EventLoop::disable($this->readWatcher);
        }

        if ($write) {
            stream_set_write_buffer($stream, 0);

            // See the read watcher: neither callback may reference the handle.
            $writeWaiter = &$this->writeWaiter;
            $this->writeWatcher = EventLoop::onWritable($stream, static function () use (&$writeWaiter): void {
                $waiter = $writeWaiter;
                $writeWaiter = null;
                $waiter?->resume();
            });

            $this->writeSequence = new Async\Sequence(
                /**
                 * @param array{self, string, Async\CancellationTokenInterface, bool} $input
                 *
                 * @return int<0, max>
                 */
                static function (array $input): int {
                    [$handle, $bytes, $cancellation, $atomic] = $input;
                    if (!$atomic) {
                        return $handle->doWrite($bytes, 0, $cancellation);
                    }

                    return $handle->doWriteAll($bytes, $cancellation);
                },
            );
            EventLoop::disable($this->writeWatcher);
        }
    }

    /**
     * @param ?positive-int $maxBytes
     */
    private function doRead(null|int $maxBytes, Async\CancellationTokenInterface $cancellation): string
    {
        $chunk = $this->tryRead($maxBytes);
        if ('' !== $chunk || $this->blocks) {
            return $chunk;
        }

        Waiter::await($this->readWatcher, $this->readWaiter, $cancellation);

        return $this->tryRead($maxBytes);
    }

    /**
     * @return int<0, max>
     */
    private function doWriteAll(string $bytes, Async\CancellationTokenInterface $cancellation): int
    {
        $length = strlen($bytes);
        $offset = 0;
        while ($offset < $length) {
            $chunk = $this->doWrite($bytes, $offset, $cancellation);
            if (0 === $chunk) {
                throw new Exception\RuntimeException('writeAll: stream reported writable but accepted no data');
            }

            $offset += $chunk;
        }

        return $offset;
    }

    /**
     * Write the part of `$bytes` that starts at `$offset`.
     *
     * @param int<0, max> $offset
     *
     * @return int<0, max> The number of bytes written, counted from `$offset`.
     */
    private function doWrite(string $bytes, int $offset, Async\CancellationTokenInterface $cancellation): int
    {
        $length = strlen($bytes);
        $written = $this->tryWrite(0 === $offset ? $bytes : substr($bytes, $offset, self::MAXIMUM_WRITE_CHUNK_SIZE));

        if ($this->blocks) {
            return $written;
        }

        // Retry while the fd is still making progress before suspending.
        // This avoids unnecessary fiber suspension when the fd is ready.
        while (0 !== $written && ($offset + $written) < $length) {
            $chunk = $this->tryWrite(substr($bytes, $offset + $written, self::MAXIMUM_WRITE_CHUNK_SIZE));
            if ($chunk === 0) {
                break;
            }

            $written += $chunk;
        }

        if (($offset + $written) >= $length) {
            return $written;
        }

        Waiter::await($this->writeWatcher, $this->writeWaiter, $cancellation);

        $chunk = $this->tryWrite(substr($bytes, $offset + $written, self::MAXIMUM_WRITE_CHUNK_SIZE));
        if ($chunk === 0) {
            throw new Exception\RuntimeException(
                'Stream reported as writable but accepted no data; the connection may be broken.',
            );
        }

        return $written + $chunk;
    }

    /**
     * @return int<0, max>
     *
     * @inheritDoc
     */
    #[Override]
    public function write(
        string $bytes,
        Async\CancellationTokenInterface $cancellation = new Async\NullCancellationToken(),
    ): int {
        if (null === $this->writeSequence) {
            Psl\invariant_violation('The resource handle is not writable.');
        }

        return $this->writeSequence->waitFor([$this, $bytes, $cancellation, false]);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function writeAll(
        string $bytes,
        Async\CancellationTokenInterface $cancellation = new Async\NullCancellationToken(),
    ): void {
        if (null === $this->writeSequence) {
            Psl\invariant_violation('The resource handle is not writable.');
        }

        $this->writeSequence->waitFor([$this, $bytes, $cancellation, true]);
    }

    /**
     * @return int<0, max>
     *
     * @inheritDoc
     */
    #[Override]
    public function tryWrite(string $bytes): int
    {
        if (!is_resource($this->stream)) {
            throw new Exception\AlreadyClosedException('Handle has already been closed.');
        }

        $result = @fwrite($this->stream, $bytes);
        if (false === $result) {
            $error = error_get_last();

            throw new Exception\RuntimeException($error['message'] ?? 'unknown error.');
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function seek(int $offset): void
    {
        if (!is_resource($this->stream)) {
            throw new Exception\AlreadyClosedException('Handle has already been closed.');
        }

        $result = @fseek($this->stream, $offset);
        if (0 !== $result) {
            throw new Exception\RuntimeException('Failed to seek the specified position.');
        }

        // `fseek()` clears the stream's EOF indicator; our cached flag must follow.
        $this->reachedEof = false;
        // It also discards PHP's read buffer, which is disabled, so it holds nothing new.
        $this->buffered = 0;
    }

    /**
     * @return int<0, max>
     *
     * @inheritDoc
     */
    #[Override]
    public function tell(): int
    {
        if (!is_resource($this->stream)) {
            throw new Exception\AlreadyClosedException('Handle has already been closed.');
        }

        $result = @ftell($this->stream);
        if (false === $result) {
            $error = error_get_last();

            throw new Exception\RuntimeException($error['message'] ?? 'unknown error.');
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        if (!is_resource($this->stream)) {
            throw new Exception\AlreadyClosedException('Handle has already been closed.');
        }

        if ($this->reachedEof) {
            return true;
        }

        if ($this->buffered > 0) {
            return false;
        }

        return $this->reachedEof = $this->hasReachedNativeEof($this->stream);
    }

    /**
     * @param ?positive-int $maxBytes the maximum number of bytes to read
     *
     * @inheritDoc
     */
    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        if (!is_resource($this->stream)) {
            throw new Exception\AlreadyClosedException('Handle has already been closed.');
        }

        if (null !== $this->readError) {
            throw new Exception\RuntimeException($this->readError);
        }

        if (null === $maxBytes) {
            $maxBytes = self::DEFAULT_READ_BUFFER_SIZE;
        } elseif ($maxBytes > self::MAXIMUM_READ_BUFFER_SIZE) {
            $maxBytes = self::MAXIMUM_READ_BUFFER_SIZE;
        }

        // Bytes PHP buffered before the handle was created are returned on their own: a call that returned them
        // together with a native read could not report that native read's failure.
        if ($this->buffered > 0 && $maxBytes > $this->buffered) {
            $maxBytes = $this->buffered;
        }

        // Exactly one native read. `stream_get_contents()` reads until it has `$maxBytes` or reaches the end of
        // the data: on a socket, every short chunk then costs a second read and two liveness peeks, datagrams are
        // joined, and a failed read only ends the loop, so a connection reset reads as a clean end of data.
        error_clear_last();
        $result = @fread($this->stream, $maxBytes);
        if (false === $result) {
            // The failure is kept: after a reset, the next native read would report a clean end of data.
            // A socket read failure leaves no diagnostic.
            $this->readError =
                error_get_last()['message'] ?? 'Failed to read from the stream; the connection may have been reset.';

            throw new Exception\RuntimeException($this->readError);
        }

        if ($this->buffered > 0) {
            $this->buffered -= strlen($result);
        }

        if ('' === $result && $this->hasReachedNativeEof($this->stream)) {
            // On a TLS stream, `fread()` never returns false: a connection reset, or an error in the TLS layer,
            // returns nothing, sets the EOF bit and raises a warning (`ext/openssl/xp_ssl.c`: `php_openssl_sockop_io()`
            // returns 0 where a plain socket read returns -1). A socket read that reaches the end of the data cleanly
            // raises nothing, so the warning is the failure.
            $error = $this->socket ? error_get_last() : null;
            if (null !== $error) {
                $this->readError = $error['message'];

                throw new Exception\RuntimeException($this->readError);
            }

            $this->reachedEof = true;
        }

        return $result;
    }

    /**
     * Whether the last native operation on the stream reached the end of its data.
     *
     * @param resource $stream
     */
    private function hasReachedNativeEof(mixed $stream): bool
    {
        if ($this->socket) {
            // On a socket, `feof()` checks whether the connection is still alive: a poll and a recv() with
            // MSG_PEEK. That costs two system calls, and a peek that meets a connection reset consumes it, so the
            // next read sees a clean end of data. The EOF bit in the metadata is the one the last read set; like
            // `feof()`, ignore it while PHP still holds bytes in its buffer.
            $meta = stream_get_meta_data($stream);

            return $meta['eof'] && 0 === $meta['unread_bytes'];
        }

        return feof($stream);
    }

    /**
     * @param ?positive-int $maxBytes the maximum number of bytes to read
     *
     * @inheritDoc
     */
    #[Override]
    public function read(
        null|int $maxBytes = null,
        Async\CancellationTokenInterface $cancellation = new Async\NullCancellationToken(),
    ): string {
        if (null === $this->readSequence) {
            Psl\invariant_violation('The resource handle is not readable.');
        }

        return $this->readSequence->waitFor([$this, $maxBytes, $cancellation]);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function isClosed(): bool
    {
        return null === $this->stream;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function close(): void
    {
        EventLoop::cancel($this->readWatcher);
        EventLoop::cancel($this->writeWatcher);
        if (null !== $this->membership) {
            $membership = $this->membership;
            $this->membership = null;
            BlockingModeRegistry::leave($membership);
        }

        if (null !== $this->stream) {
            $exception = new Exception\AlreadyClosedException('Handle has already been closed.');

            $this->readSequence?->cancel($exception);
            $this->readWaiter?->fail($exception);

            $this->writeSequence?->cancel($exception);
            $this->writeWaiter?->fail($exception);

            // don't close the stream if `$this->close` is false, or if it's already closed.
            if ($this->close && is_resource($this->stream)) {
                $stream = $this->stream;
                $this->stream = null;
                $result = @fclose($stream);
                if (false === $result) {
                    /** @var array{message?: string} $error */
                    $error = error_get_last();

                    throw new Exception\RuntimeException($error['message'] ?? 'unknown error.');
                }

                return;
            }

            // Stream could be set to a non-null closed-resource,
            // if manually closed using `fclose($handle->getStream)`.
            $this->stream = null;
        }
    }

    /**
     * @return resource|object|null
     *
     * @inheritDoc
     */
    #[Override]
    public function getStream(): mixed
    {
        return $this->stream;
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (Exception\RuntimeException) {
            // @mago-expect lint:no-empty-catch-clause - A destructor cannot report an expected close failure (E11).
        }
    }
}
