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
use Psl\H2\Frame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\StreamState;
use Psl\IO;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

use function array_filter;
use function array_values;
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
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-properties
 */
trait ConnectionTrait
{
    private readonly IO\ReadHandleInterface&IO\WriteHandleInterface $handle;

    private IO\Reader $reader;

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
     * @var array<int, list<array{int, Suspension}>>
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
        } catch (ConnectionException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // Auto-send GOAWAY before re-throwing.
            $errorCode = match (true) {
                $e instanceof ProtocolException => $e->errorCode,
                $e instanceof FlowControlException => ErrorCode::FlowControlError,
                default => ErrorCode::ProtocolError,
            };

            // @mago-expect lint:no-empty-catch-clause - Best effort, connection may already be broken.
            try {
                $goaway = $this->stateMachine->goAway($errorCode, $e->getMessage());
                $this->writeFrames($goaway);
            } catch (Throwable) {
            }

            throw $e;
        }

        if ($responseFrames !== []) {
            $data = '';
            foreach ($responseFrames as $frame) {
                $data .= $frame instanceof Frame\RawFrame ? Frame\encode($frame) : $frame;
            }

            $this->write($data, $cancellation);
        }

        if ($this->windowWaiters !== []) {
            $this->notifyWindowWaiters($rawFrame->streamId);
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
        if ($this->stateMachine->availableSendWindow($streamId) >= $bytes) {
            return;
        }

        if ($this->stateMachine->shutdown) {
            throw ConnectionException::forConnectionClosed();
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

        if ($streamId === 0) {
            foreach ($this->windowWaiters as $sid => $waiters) {
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

            return;
        }

        if (!isset($this->windowWaiters[$streamId])) {
            return;
        }

        $remaining = [];
        foreach ($this->windowWaiters[$streamId] as $waiter) {
            [$bytes, $suspension] = $waiter;
            if ($this->stateMachine->availableSendWindow($streamId) < $bytes) {
                $remaining[] = $waiter;
                continue;
            }

            $suspension->resume();
        }

        if ($remaining === []) {
            unset($this->windowWaiters[$streamId]);
        } else {
            $this->windowWaiters[$streamId] = $remaining;
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
            $chunk = $this->handle->read(4096, $cancellation);
            if ($chunk === '') {
                throw ConnectionException::forConnectionClosed();
            }

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
            $chunk = $this->handle->read($missing, $cancellation);
            if ($chunk === '') {
                throw ConnectionException::forConnectionClosed();
            }

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
