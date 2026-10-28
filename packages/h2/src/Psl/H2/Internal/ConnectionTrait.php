<?php

declare(strict_types=1);

namespace Psl\H2\Internal;

use Closure;
use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\H2\ConnectionInterface;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\ConnectionException;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Exception\RuntimeException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\StreamState;
use Psl\IO;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

use function array_filter;
use function array_values;
use function in_array;
use function min;
use function ord;
use function strlen;
use function substr;
use function unpack;

use const Psl\H2\FRAME_HEADER_SIZE;

/**
 * Shared IO and protocol plumbing for HTTP/2 connections.
 *
 * Classes using this trait must declare:
 * - `private StateMachine $stateMachine`
 * - `private readonly IO\ReadHandleInterface&IO\WriteHandleInterface $handle`
 *
 * @internal
 *
 * @require-implements ConnectionInterface
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-properties
 */
trait ConnectionTrait
{
    private readonly IO\ReadHandleInterface&IO\WriteHandleInterface $handle;

    private IO\Reader $reader;

    /**
     * Whether the reader may still hold bytes that a caller's reader buffered before the connection took over the
     * handle, such as the first HTTP/2 bytes after an upgrade from HTTP/1.1.
     */
    private bool $drainReader = false;

    private string $readBuffer = '';

    private int $readBufferLength = 0;

    private int $readBufferOffset = 0;

    private string $writeBuffer = '';

    private int $writeBufferLength = 0;

    private int $writeBufferThreshold = 65_536;

    private bool $buffering = false;

    private StateMachine $stateMachine;

    /**
     * Registered window waiters, keyed by stream ID.
     *
     * Each entry is a list of [bytesNeeded, suspension] tuples waiting
     * for that stream's send window to open.
     *
     * @var array<int<0, max>, list<array{positive-int, Suspension}>>
     */
    private array $windowWaiters = [];

    /**
     * @inheritDoc
     */
    #[Override]
    public function isConnected(): bool
    {
        return !$this->stateMachine->shutdown;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function nextStreamId(): int
    {
        return $this->stateMachine->nextStreamId();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function lastPeerStreamId(): int
    {
        return $this->stateMachine->lastPeerStreamId();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getStreamState(int $streamId): StreamState
    {
        return $this->stateMachine->getStreamState($streamId);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function activeStreamCount(): int
    {
        return $this->stateMachine->activeStreamCount();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function availableSendWindow(int $streamId): int
    {
        return $this->stateMachine->availableSendWindow($streamId);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function sendSettings(
        array $settings,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $frames = $this->stateMachine->sendSettings($settings);
        $this->writeFrames($frames, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function readEvent(CancellationTokenInterface $cancellation = new NullCancellationToken()): array
    {
        try {
            $rawFrame = $this->readFrame($cancellation);
            [$responseFrames, $events] = $this->stateMachine->receive($rawFrame);
        } catch (ConnectionException|IO\Exception\RuntimeException $e) {
            $this->stateMachine->goAway(ErrorCode::NoError);
            $this->notifyWindowWaiters(0);
            throw $e;
        } catch (RuntimeException $e) {
            // Report the error to the peer before re-throwing.
            $errorCode = match (true) {
                $e instanceof ProtocolException => $e->errorCode,
                $e instanceof FlowControlException => ErrorCode::FlowControlError,
                default => ErrorCode::ProtocolError,
            };

            // @mago-expect lint:no-empty-catch-clause - Best effort, connection may already be broken.
            try {
                $frames = $e instanceof StreamException
                    ? $this->stateMachine->resetStreamForError($e->streamId, $e->errorCode)
                    : $this->stateMachine->goAway($errorCode, $e->getMessage());
                $this->notifyWindowWaiters(0);
                $this->writeFrames($frames);
            } catch (ProtocolException $limitError) {
                // @mago-expect lint:no-empty-catch-clause - Best effort after the reset limit is reached.
                try {
                    $this->goAway($limitError->errorCode, $limitError->getMessage());
                } catch (Throwable) {
                }

                throw $limitError;
            } catch (Throwable) {
            }

            throw $e;
        }

        if ($this->windowWaiters !== []) {
            $this->notifyWindowWaiters($rawFrame->streamId);
        }

        if ($responseFrames !== []) {
            $data = '';
            foreach ($responseFrames as $frame) {
                $data .= $frame instanceof Frame\RawFrame ? Frame\encode($frame) : $frame;
            }

            $this->write($data, $cancellation);
        }

        return $events;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function sendAllData(
        int $streamId,
        string $data,
        bool $endStream = false,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $offset = 0;
        $remaining = strlen($data);

        while ($remaining > 0) {
            $this->waitForSendWindow($streamId, 1, $cancellation);

            $window = $this->stateMachine->availableSendWindow($streamId);
            $maxFrameSize = $this->stateMachine->getRemoteMaxFrameSize();
            $chunkSize = min($remaining, $window, $maxFrameSize);
            $isLast = $chunkSize === $remaining;

            $chunk = substr($data, $offset, $chunkSize);
            $this->sendData($streamId, $chunk, $endStream && $isLast, $cancellation);

            $offset += $chunkSize;
            $remaining -= $chunkSize;
        }

        if ($remaining === 0 && $endStream && $data === '') {
            // Empty data with endStream - send empty DATA frame.
            $this->sendData($streamId, '', true, $cancellation);
        }
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function rejectPush(
        int $promisedStreamId,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $this->resetStream($promisedStreamId, ErrorCode::Cancel, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function sendHeaders(
        int $streamId,
        array $headers,
        bool $endStream = false,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $encoded = $this->stateMachine->sendHeadersEncoded($streamId, $headers, $endStream);
        $this->notifyWindowWaiters($streamId);
        $this->write($encoded, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function sendData(
        int $streamId,
        string $data,
        bool $endStream = false,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $encoded = $this->stateMachine->sendDataEncoded($streamId, $data, $endStream);
        $this->notifyWindowWaiters($streamId);
        $this->write($encoded, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function resetStream(
        int $streamId,
        ErrorCode $errorCode,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $frames = $this->stateMachine->resetStream($streamId, $errorCode);
        $this->notifyWindowWaiters($streamId);
        $this->writeFrames($frames, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function ping(
        string $opaqueData,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $frames = $this->stateMachine->ping($opaqueData);
        $this->writeFrames($frames, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function goAway(
        ErrorCode $errorCode,
        string $debugData = '',
        null|int $lastStreamId = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $frames = $this->stateMachine->goAway($errorCode, $debugData, $lastStreamId);
        $this->notifyWindowWaiters(0);
        $this->writeFrames($frames, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function waitForSendWindow(
        int $streamId,
        int $bytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        if ($this->hasSendWindow($streamId, $bytes)) {
            return;
        }

        $cancellation->throwIfCancelled();

        // Flush pending buffered writes before suspending; otherwise frames written
        // inside a buffered() block never reach the wire and a peer that only sends
        // WINDOW_UPDATE after seeing our DATA would deadlock.
        if ($this->writeBuffer !== '') {
            $data = $this->writeBuffer;
            $this->writeBuffer = '';
            $this->writeBufferLength = 0;
            $this->handle->writeAll($data, $cancellation);
            if ($this->hasSendWindow($streamId, $bytes)) {
                return;
            }
        }

        $suspension = EventLoop::getSuspension();

        $this->windowWaiters[$streamId] ??= [];
        $this->windowWaiters[$streamId][] = [$bytes, $suspension];

        $id = null;
        if ($cancellation->cancellable) {
            $id = $cancellation->subscribe(static function (CancelledException $e) use ($suspension): void {
                $suspension->throw($e);
            });
        }

        try {
            $suspension->suspend();
        } finally {
            if ($id !== null) {
                $cancellation->unsubscribe($id);
            }

            // Remove this waiter from the stream's list.
            if (isset($this->windowWaiters[$streamId])) {
                $this->windowWaiters[$streamId] = array_values(array_filter(
                    $this->windowWaiters[$streamId],
                    static fn(array $w): bool => $w[1] !== $suspension,
                ));

                if ($this->windowWaiters[$streamId] === []) {
                    unset($this->windowWaiters[$streamId]);
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function buffered(Closure $fn, CancellationTokenInterface $cancellation = new NullCancellationToken()): void
    {
        if ($this->buffering) {
            throw ProtocolException::forConnectionError('Already buffering');
        }

        $this->buffering = true;
        try {
            $fn();
        } finally {
            $this->buffering = false;
            if ($this->writeBufferLength > 0) {
                $data = $this->writeBuffer;
                $this->writeBuffer = '';
                $this->writeBufferLength = 0;
                $this->handle->writeAll($data, $cancellation);
            }
        }
    }

    /**
     * @param int<0, max> $streamId
     * @param positive-int $bytes
     *
     * @throws ConnectionException If the connection is closed.
     * @throws StreamException If the stream can no longer send.
     */
    private function hasSendWindow(int $streamId, int $bytes): bool
    {
        if ($this->stateMachine->shutdown) {
            throw ConnectionException::forConnectionClosed();
        }

        if (
            $streamId !== 0
            && in_array(
                $this->stateMachine->getStreamState($streamId),
                [StreamState::Closed, StreamState::HalfClosedLocal],
                true,
            )
        ) {
            throw StreamException::forStreamClosed($streamId);
        }

        return $this->stateMachine->availableSendWindow($streamId) >= $bytes;
    }

    /**
     * Check window waiters for a specific stream and resume those whose condition is met.
     *
     * When $streamId is 0 (connection-level window update), all waiters across all
     * streams are checked since the connection window affects every stream.
     *
     * @param int<0, max> $streamId The stream that received a window update, or 0 for connection-level.
     */
    private function notifyWindowWaiters(int $streamId): void
    {
        if ($this->stateMachine->shutdown) {
            $allWaiters = $this->windowWaiters;
            $this->windowWaiters = [];
            foreach ($allWaiters as $waiters) {
                foreach ($waiters as [$bytes, $suspension]) {
                    $suspension->throw(ConnectionException::forConnectionClosed());
                }
            }

            return;
        }

        foreach ($this->windowWaiters as $sid => $waiters) {
            if ($streamId !== 0 && $streamId !== $sid) {
                continue;
            }

            if (
                $sid !== 0
                && in_array(
                    $this->stateMachine->getStreamState($sid),
                    [StreamState::Closed, StreamState::HalfClosedLocal],
                    true,
                )
            ) {
                unset($this->windowWaiters[$sid]);
                foreach ($waiters as [$bytes, $suspension]) {
                    $suspension->throw(StreamException::forStreamClosed($sid));
                }

                continue;
            }

            $remaining = [];
            foreach ($waiters as $waiter) {
                [$bytes, $suspension] = $waiter;
                if ($this->stateMachine->availableSendWindow($sid) < $bytes) {
                    $remaining[] = $waiter;
                    continue;
                }

                $suspension->resume();
            }

            if ($remaining === []) {
                unset($this->windowWaiters[$sid]);
            } else {
                $this->windowWaiters[$sid] = $remaining;
            }
        }
    }

    /**
     * @param positive-int $size
     *
     * @throws ConnectionException If the transport ends before the requested bytes arrive.
     * @throws IO\Exception\RuntimeException If the read fails.
     * @throws CancelledException If the read is cancelled.
     */
    private function readTransportBytes(int $size, CancellationTokenInterface $cancellation): string
    {
        try {
            return $this->reader->readFixedSize($size, $cancellation);
        } catch (IO\Exception\RuntimeException $exception) {
            if ($this->reader->getHandle()->reachedEndOfDataSource()) {
                throw ConnectionException::forConnectionClosed();
            }

            throw $exception;
        }
    }

    /**
     * Read the next bytes of a frame from the transport, waiting until some arrive.
     *
     * A read that returns nothing has not always reached the end of the data. A TLS stream wakes a read for
     * records that carry no application data, such as the session tickets a TLS 1.3 server sends after its
     * handshake, and for a record that has only partly arrived: the read then returns nothing although the
     * connection is still open. Only the end of the transport's data closes the connection.
     *
     * @param positive-int $maxBytes
     *
     * @throws ConnectionException If the transport reached the end of its data.
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    private function readFrameBytes(int $maxBytes, CancellationTokenInterface $cancellation): string
    {
        if ($this->drainReader) {
            $chunk = $this->reader->tryRead($maxBytes);
            if ('' !== $chunk) {
                return $chunk;
            }

            $this->drainReader = false;
        }

        do {
            $chunk = $this->handle->read($maxBytes, $cancellation);
            if ('' !== $chunk) {
                return $chunk;
            }
        } while (!$this->handle->reachedEndOfDataSource());

        throw ConnectionException::forConnectionClosed();
    }

    /**
     * Read a complete frame from the underlying transport.
     *
     * @throws ConnectionException If the connection is closed.
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    private function readFrame(CancellationTokenInterface $cancellation = new NullCancellationToken()): RawFrame
    {
        $available = $this->readBufferLength - $this->readBufferOffset;

        while ($available < FRAME_HEADER_SIZE) {
            $chunk = $this->readFrameBytes(4096, $cancellation);
            $this->readBuffer .= $chunk;
            $this->readBufferLength += strlen($chunk);
            $available = $this->readBufferLength - $this->readBufferOffset;
        }

        $o = $this->readBufferOffset;
        $length =
            (ord($this->readBuffer[$o]) << 16) | (ord($this->readBuffer[$o + 1]) << 8) | ord($this->readBuffer[$o + 2]);
        $this->stateMachine->validateFrameSize($length);
        $totalNeeded = FRAME_HEADER_SIZE + $length;

        while ($available < $totalNeeded) {
            /** @var positive-int $missing */
            $missing = $totalNeeded - $available;
            $chunk = $this->readFrameBytes($missing, $cancellation);
            $this->readBuffer .= $chunk;
            $this->readBufferLength += strlen($chunk);
            $available = $this->readBufferLength - $this->readBufferOffset;
        }

        $type = ord($this->readBuffer[$o + 3]);
        $flags = ord($this->readBuffer[$o + 4]);
        /** @var int<0, max> $streamId */
        $streamId = unpack('N', $this->readBuffer, $o + 5)[1] & 0x7FFF_FFFF;
        $payload = $length > 0 ? substr($this->readBuffer, $o + FRAME_HEADER_SIZE, $length) : '';
        $this->readBufferOffset += $totalNeeded;

        // Compact the buffer when the consumed portion exceeds 64KB to bound memory usage.
        if ($this->readBufferOffset > 65_536) {
            $this->readBuffer = substr($this->readBuffer, $this->readBufferOffset);
            $this->readBufferLength -= $this->readBufferOffset;
            $this->readBufferOffset = 0;
        }

        return new RawFrame($type, $flags, $streamId, $payload);
    }

    /**
     * Write data to the underlying handle, buffering if enabled.
     *
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    private function write(string $data, CancellationTokenInterface $cancellation = new NullCancellationToken()): void
    {
        if ($this->buffering) {
            $this->writeBuffer .= $data;
            $this->writeBufferLength += strlen($data);
            if ($this->writeBufferLength >= $this->writeBufferThreshold) {
                $data = $this->writeBuffer;
                $this->writeBuffer = '';
                $this->writeBufferLength = 0;
                $this->handle->writeAll($data, $cancellation);
            }

            return;
        }

        $this->handle->writeAll($data, $cancellation);
    }

    /**
     * Encode and write a list of raw frames.
     *
     * @param list<RawFrame> $frames
     *
     * @throws IO\Exception\AlreadyClosedException If the handle has been closed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    private function writeFrames(
        array $frames,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $data = '';
        foreach ($frames as $frame) {
            $data .= Frame\encode($frame);
        }

        $this->write($data, $cancellation);
    }
}
