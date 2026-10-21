<?php

declare(strict_types=1);

namespace Psl\IO\Internal;

use Override;
use Psl;
use Psl\Async;
use Psl\IO;
use Psl\IO\Exception;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

use function error_get_last;
use function fclose;
use function feof;
use function fread;
use function fseek;
use function ftell;
use function fwrite;
use function is_resource;
use function str_contains;
use function stream_get_contents;
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
     * @var null|Async\Sequence<array{string, Async\CancellationTokenInterface, bool}, int<0, max>>
     */
    private null|Async\Sequence $writeSequence = null;
    private null|Suspension $writeSuspension = null;
    private string $writeWatcher = 'invalid';

    /**
     * @var null|Async\Sequence<array{null|int<1, max>, Async\CancellationTokenInterface}, string>
     */
    private null|Async\Sequence $readSequence = null;
    private null|Suspension $readSuspension = null;
    private string $readWatcher = 'invalid';

    private bool $useSingleRead = false;
    private bool $reachedEof = false;
    private bool $blocks;

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

        stream_set_blocking($stream, false);

        $meta = stream_get_meta_data($stream);
        if ($read) {
            $this->useSingleRead = 'udp_socket' === $meta['stream_type'] || 'STDIO' === $meta['stream_type'];
        }

        /** @mago-expect analysis:redundant-null-coalesce */
        $this->blocks = ($meta['blocked'] ?? true) || ($meta['wrapper_type'] ?? '') === 'plainfile';
        if ($seek) {
            $seekable = $meta['seekable'];

            Psl\invariant($seekable, 'Handle is not seekable.');
        }

        if ($read) {
            $readable = str_contains($meta['mode'], 'r') || str_contains($meta['mode'], '+');

            Psl\invariant($readable, 'Handle is not readable.');

            stream_set_read_buffer($stream, 0);

            $this->readWatcher = EventLoop::onReadable($stream, function (): void {
                $suspension = $this->readSuspension;
                $this->readSuspension = null;
                $suspension?->resume();
            });

            $this->readSequence = new Async\Sequence(
                /**
                 * @param array{null|int<1, max>, Async\CancellationTokenInterface} $input
                 */
                function (array $input): string {
                    [$maxBytes, $cancellation] = $input;

                    return $this->doRead($maxBytes, $cancellation);
                },
            );

            EventLoop::disable($this->readWatcher);
        }

        if ($write) {
            $writable = false !== strpbrk($meta['mode'], 'xwca+');

            Psl\invariant($writable, 'Handle is not writeable.');

            stream_set_write_buffer($stream, 0);

            $this->writeWatcher = EventLoop::onWritable($stream, function (): void {
                $suspension = $this->writeSuspension;
                $this->writeSuspension = null;
                $suspension?->resume();
            });

            $this->writeSequence = new Async\Sequence(
                /**
                 * @param array{string, Async\CancellationTokenInterface, bool} $input
                 *
                 * @return int<0, max>
                 */
                function (array $input): int {
                    [$bytes, $cancellation, $atomic] = $input;
                    if (!$atomic) {
                        return $this->doWrite($bytes, 0, $cancellation);
                    }

                    $length = strlen($bytes);
                    $offset = 0;
                    while ($offset < $length) {
                        $chunk = $this->doWrite($bytes, $offset, $cancellation);
                        if (0 === $chunk) {
                            throw new Exception\RuntimeException(
                                'writeAll: stream reported writable but accepted no data',
                            );
                        }

                        $offset += $chunk;
                    }

                    return $offset;
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

        $cancellable = $cancellation->cancellable;

        if ($cancellable) {
            $cancellation->throwIfCancelled();
        }

        $suspension = EventLoop::getSuspension();
        $this->readSuspension = $suspension;
        EventLoop::enable($this->readWatcher);

        if (!$cancellable) {
            try {
                $suspension->suspend();

                return $this->tryRead($maxBytes);
            } finally {
                $this->readSuspension = null;
                EventLoop::disable($this->readWatcher);
            }
        }

        $id = $cancellation->subscribe(function (Async\Exception\CancelledException $e): void {
            $suspension = $this->readSuspension;
            $this->readSuspension = null;
            $suspension?->throw($e);
        });

        try {
            $suspension->suspend();

            return $this->tryRead($maxBytes);
        } finally {
            $this->readSuspension = null;
            EventLoop::disable($this->readWatcher);
            $cancellation->unsubscribe($id);
        }
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

        $cancellable = $cancellation->cancellable;

        if ($cancellable) {
            $cancellation->throwIfCancelled();
        }

        $suspension = EventLoop::getSuspension();
        $this->writeSuspension = $suspension;
        EventLoop::enable($this->writeWatcher);

        if (!$cancellable) {
            try {
                $suspension->suspend();

                $chunk = $this->tryWrite(substr($bytes, $offset + $written, self::MAXIMUM_WRITE_CHUNK_SIZE));
                if ($chunk === 0) {
                    throw new Exception\RuntimeException(
                        'Stream reported as writable but accepted no data; the connection may be broken.',
                    );
                }

                return $written + $chunk;
            } finally {
                $this->writeSuspension = null;
                EventLoop::disable($this->writeWatcher);
            }
        }

        $id = $cancellation->subscribe(function (Async\Exception\CancelledException $e): void {
            $suspension = $this->writeSuspension;
            $this->writeSuspension = null;
            $suspension?->throw($e);
        });

        try {
            $suspension->suspend();

            $chunk = $this->tryWrite(substr($bytes, $offset + $written, self::MAXIMUM_WRITE_CHUNK_SIZE));
            if ($chunk === 0) {
                throw new Exception\RuntimeException(
                    'Stream reported as writable but accepted no data; the connection may be broken.',
                );
            }

            return $written + $chunk;
        } finally {
            $this->writeSuspension = null;
            EventLoop::disable($this->writeWatcher);
            $cancellation->unsubscribe($id);
        }
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

        return $this->writeSequence->waitFor([$bytes, $cancellation, false]);
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

        $this->writeSequence->waitFor([$bytes, $cancellation, true]);
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

        return $this->reachedEof = feof($this->stream);
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

        if (null === $maxBytes) {
            $maxBytes = self::DEFAULT_READ_BUFFER_SIZE;
        } elseif ($maxBytes > self::MAXIMUM_READ_BUFFER_SIZE) {
            $maxBytes = self::MAXIMUM_READ_BUFFER_SIZE;
        }

        if ($this->useSingleRead) {
            $result = fread($this->stream, $maxBytes);
        } else {
            $result = stream_get_contents($this->stream, $maxBytes);
        }

        if (false === $result) {
            /** @var array{message?: string} $error */
            $error = error_get_last();

            throw new Exception\RuntimeException($error['message'] ?? 'unknown error.');
        }

        if ('' === $result && feof($this->stream)) {
            $this->reachedEof = true;
        }

        return $result;
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

        return $this->readSequence->waitFor([$maxBytes, $cancellation]);
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
        if (null !== $this->stream) {
            $exception = new Exception\AlreadyClosedException('Handle has already been closed.');

            $this->readSequence?->cancel($exception);
            $this->readSuspension?->throw($exception);

            $this->writeSequence?->cancel($exception);
            $this->writeSuspension?->throw($exception);

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
        $this->close();
    }
}
