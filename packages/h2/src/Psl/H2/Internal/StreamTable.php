<?php

declare(strict_types=1);

namespace Psl\H2\Internal;

use Psl\H2\Exception\FlowControlException;
use Psl\H2\StreamState;

use const PHP_INT_MAX;
use const Psl\H2\DEFAULT_INITIAL_WINDOW_SIZE;
use const Psl\H2\MAX_WINDOW_SIZE;

/**
 * Registry of active streams with max concurrent enforcement.
 *
 * @internal
 */
final class StreamTable
{
    /**
     * @var array<int, StreamEntry>
     */
    private array $streams = [];

    /**
     * @var int<0, max> Number of currently active (open or half-closed) streams.
     */
    private int $activeCount = 0;

    /**
     * Maximum concurrent streams WE can initiate (set by the peer's SETTINGS).
     */
    private int $maxConcurrent;

    /**
     * Maximum concurrent streams the PEER can initiate (set by our local SETTINGS).
     */
    private int $peerMaxConcurrent;

    /**
     * Initial send window size for newly created streams.
     */
    private int $initialSendWindow;

    /**
     * Initial receive window size for newly created streams.
     */
    private int $initialReceiveWindow;

    public function __construct(
        int $maxConcurrent = PHP_INT_MAX,
        int $initialSendWindow = DEFAULT_INITIAL_WINDOW_SIZE,
        int $initialReceiveWindow = DEFAULT_INITIAL_WINDOW_SIZE,
        int $peerMaxConcurrent = PHP_INT_MAX,
    ) {
        $this->maxConcurrent = $maxConcurrent;
        $this->initialSendWindow = $initialSendWindow;
        $this->initialReceiveWindow = $initialReceiveWindow;
        $this->peerMaxConcurrent = $peerMaxConcurrent;
    }

    /**
     * Look up a stream entry by its ID, or return null if not found.
     */
    public function get(int $streamId): null|StreamEntry
    {
        return $this->streams[$streamId] ?? null;
    }

    /**
     * @throws FlowControlException If max concurrent streams exceeded.
     */
    public function open(int $streamId): StreamEntry
    {
        if ($this->activeCount >= $this->maxConcurrent) {
            throw FlowControlException::forMaxConcurrentStreamsExceeded($this->maxConcurrent);
        }

        $entry = new StreamEntry($this->initialSendWindow, $this->initialReceiveWindow);
        $entry->state = StreamState::Open;
        $this->streams[$streamId] = $entry;
        $this->activeCount++;

        return $entry;
    }

    /**
     * Return an existing stream entry or create a new idle one.
     */
    public function getOrCreate(int $streamId): StreamEntry
    {
        if (isset($this->streams[$streamId])) {
            return $this->streams[$streamId];
        }

        $entry = new StreamEntry($this->initialSendWindow, $this->initialReceiveWindow);
        $this->streams[$streamId] = $entry;

        return $entry;
    }

    /**
     * Close a stream and remove it from the table, decrementing the active count if applicable.
     */
    public function close(int $streamId): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }

        $entry = $this->streams[$streamId];
        $wasActive =
            $entry->state === StreamState::Open
            || $entry->state === StreamState::HalfClosedLocal
            || $entry->state === StreamState::HalfClosedRemote
            || $entry->state === StreamState::ReservedLocal
            || $entry->state === StreamState::ReservedRemote;

        if ($wasActive && $this->activeCount > 0) {
            $this->activeCount--;
        }

        unset($this->streams[$streamId]);
    }

    /**
     * Transition a stream to a half-closed state.
     *
     * @param int $streamId The stream to transition.
     * @param StreamState $newState The target half-closed state.
     */
    public function markHalfClosed(int $streamId, StreamState $newState): void
    {
        if (isset($this->streams[$streamId])) {
            $this->streams[$streamId]->state = $newState;
        }
    }

    /**
     * Update the maximum number of concurrent streams allowed.
     */
    public function setMaxConcurrent(int $max): void
    {
        $this->maxConcurrent = $max;
    }

    /**
     * Update the maximum number of concurrent streams the peer may open.
     *
     * Applied when the peer acknowledges our SETTINGS_MAX_CONCURRENT_STREAMS.
     */
    public function setPeerMaxConcurrent(int $max): void
    {
        $this->peerMaxConcurrent = $max;
    }

    /**
     * Update the initial send window size for newly created streams.
     */
    public function setInitialSendWindow(int $window): void
    {
        $this->initialSendWindow = $window;
    }

    /**
     * Update the initial receive window size for newly created streams.
     */
    public function setInitialReceiveWindow(int $window): void
    {
        $this->initialReceiveWindow = $window;
    }

    /**
     * Adjust all open stream send windows when INITIAL_WINDOW_SIZE changes.
     *
     * @throws FlowControlException If any stream window would exceed the maximum.
     */
    public function adjustSendWindows(int $delta): void
    {
        foreach ($this->streams as $entry) {
            if (!($entry->state === StreamState::Open || $entry->state === StreamState::HalfClosedRemote)) {
                continue;
            }

            $newWindow = $entry->sendWindow + $delta;
            if ($newWindow > MAX_WINDOW_SIZE) {
                throw FlowControlException::forWindowOverflow(0, $entry->sendWindow, $delta);
            }

            $entry->sendWindow = $newWindow;
        }
    }

    /**
     * Adjust the receive windows of streams the peer can still send on when
     * our acknowledged SETTINGS_INITIAL_WINDOW_SIZE changes (RFC 9113 Section 6.9.2).
     *
     * Windows may become negative; the peer applies the same delta to its send
     * windows and waits for WINDOW_UPDATE before sending again.
     */
    public function adjustReceiveWindows(int $delta): void
    {
        foreach ($this->streams as $entry) {
            if (!($entry->state === StreamState::Open || $entry->state === StreamState::HalfClosedLocal)) {
                continue;
            }

            $entry->receiveWindow += $delta;
        }
    }

    /**
     * Return the number of currently active streams.
     *
     * @return int<0, max>
     */
    public function activeCount(): int
    {
        return $this->activeCount;
    }

    /**
     * Whether we can accept a new peer-initiated stream.
     */
    public function canAcceptPeerStream(): bool
    {
        return $this->activeCount < $this->peerMaxConcurrent;
    }

    /**
     * Increment active count for streams opened via receive path.
     */
    public function incrementActive(): void
    {
        $this->activeCount++;
    }
}
