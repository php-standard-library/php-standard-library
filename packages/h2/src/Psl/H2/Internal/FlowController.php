<?php

declare(strict_types=1);

namespace Psl\H2\Internal;

use Psl\H2\Exception\FlowControlException;

use function min;

use const Psl\H2\DEFAULT_INITIAL_WINDOW_SIZE;
use const Psl\H2\MAX_WINDOW_SIZE;

/**
 * Connection-level and stream-level flow control window tracking.
 *
 * @internal
 */
final class FlowController
{
    /** @var int Current connection-level send window size in bytes. */
    private int $connectionSendWindow;

    /** @var int Current connection-level receive window size in bytes. */
    private int $connectionReceiveWindow;

    public function __construct(int $initialWindow = DEFAULT_INITIAL_WINDOW_SIZE)
    {
        $this->connectionSendWindow = $initialWindow;
        $this->connectionReceiveWindow = $initialWindow;
    }

    /**
     * Return the current connection-level send window size.
     */
    public function connectionSendWindow(): int
    {
        return $this->connectionSendWindow;
    }

    /**
     * Return the current connection-level receive window size.
     */
    public function connectionReceiveWindow(): int
    {
        return $this->connectionReceiveWindow;
    }

    /**
     * @throws FlowControlException If send window is exhausted.
     */
    public function consumeSendWindow(StreamEntry $stream, int $size): void
    {
        if ($this->connectionSendWindow < $size) {
            throw FlowControlException::forWindowExhausted(0, $this->connectionSendWindow, $size);
        }

        if ($stream->sendWindow < $size) {
            throw FlowControlException::forWindowExhausted(0, $stream->sendWindow, $size);
        }

        $this->connectionSendWindow -= $size;
        $stream->sendWindow -= $size;
    }

    /**
     * @throws FlowControlException If receive window would go negative.
     */
    public function consumeReceiveWindow(StreamEntry $stream, int $size): void
    {
        if ($this->connectionReceiveWindow < $size) {
            throw FlowControlException::forWindowExhausted(0, $this->connectionReceiveWindow, $size);
        }

        if ($stream->receiveWindow < $size) {
            throw FlowControlException::forWindowExhausted(0, $stream->receiveWindow, $size);
        }

        $this->connectionReceiveWindow -= $size;
        $stream->receiveWindow -= $size;
    }

    /**
     * Account for DATA on a closed stream before returning its connection credit.
     *
     * @throws FlowControlException If the connection receive window would go negative.
     */
    public function consumeConnectionReceiveWindow(int $size): void
    {
        if ($this->connectionReceiveWindow < $size) {
            throw FlowControlException::forWindowExhausted(0, $this->connectionReceiveWindow, $size);
        }

        $this->connectionReceiveWindow -= $size;
    }

    /**
     * @throws FlowControlException If window would overflow.
     */
    public function applyConnectionWindowUpdate(int $increment): void
    {
        $newWindow = $this->connectionSendWindow + $increment;
        if ($newWindow > MAX_WINDOW_SIZE) {
            throw FlowControlException::forWindowOverflow(0, $this->connectionSendWindow, $increment);
        }

        $this->connectionSendWindow = $newWindow;
    }

    /**
     * @throws FlowControlException If window would overflow.
     */
    public function applyStreamWindowUpdate(StreamEntry $stream, int $streamId, int $increment): void
    {
        $newWindow = $stream->sendWindow + $increment;
        if ($newWindow > MAX_WINDOW_SIZE) {
            throw FlowControlException::forWindowOverflow($streamId, $stream->sendWindow, $increment);
        }

        $stream->sendWindow = $newWindow;
    }

    /**
     * @throws FlowControlException If window would overflow.
     */
    public function applyConnectionReceiveWindowUpdate(int $increment): void
    {
        $newWindow = $this->connectionReceiveWindow + $increment;
        if ($newWindow > MAX_WINDOW_SIZE) {
            throw FlowControlException::forWindowOverflow(0, $this->connectionReceiveWindow, $increment);
        }

        $this->connectionReceiveWindow = $newWindow;
    }

    /**
     * Return the available send window for a stream (minimum of connection and stream windows).
     */
    public function availableSendWindow(StreamEntry $stream): int
    {
        return min($this->connectionSendWindow, $stream->sendWindow);
    }
}
