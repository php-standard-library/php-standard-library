<?php

declare(strict_types=1);

namespace Psl\H2\Internal;

use Psl\H2\Exception\FlowControlException;
use Psl\H2\StreamState;

use function max;

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
     * @var int<0, max> Number of active streams initiated by this endpoint.
     */
    private int $localActiveCount = 0;

    /**
     * @var int<0, max> Number of active streams initiated by the peer.
     */
    private int $peerActiveCount = 0;

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
        private readonly bool $isClient = true,
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
        $this->checkCanOpen($streamId);

        $entry = $this->getOrCreate($streamId);
        $entry->state = StreamState::Open;
        $this->incrementActive($streamId);

        return $entry;
    }

    /**
     * @throws FlowControlException If max concurrent streams exceeded.
     */
    public function checkCanOpen(int $streamId): void
    {
        $isLocal = (($streamId % 2) === 1) === $this->isClient;
        $count = $isLocal ? $this->localActiveCount : $this->peerActiveCount;
        $limit = $isLocal ? $this->maxConcurrent : $this->peerMaxConcurrent;
        if ($count >= $limit) {
            throw FlowControlException::forMaxConcurrentStreamsExceeded($limit);
        }
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
            || $entry->state === StreamState::HalfClosedRemote;

        if ($wasActive) {
            if ((($streamId % 2) === 1) === $this->isClient) {
                $this->localActiveCount = max(0, $this->localActiveCount - 1);
            } else {
                $this->peerActiveCount = max(0, $this->peerActiveCount - 1);
            }
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
        return $this->localActiveCount + $this->peerActiveCount;
    }

    /**
     * Whether we can accept a new peer-initiated stream.
     */
    public function canAcceptPeerStream(): bool
    {
        return $this->peerActiveCount < $this->peerMaxConcurrent;
    }

    /**
     * Increment active count for streams opened via receive path.
     */
    public function incrementActive(int $streamId): void
    {
        if ((($streamId % 2) === 1) === $this->isClient) {
            $this->localActiveCount++;
        } else {
            $this->peerActiveCount++;
        }
    }
}
