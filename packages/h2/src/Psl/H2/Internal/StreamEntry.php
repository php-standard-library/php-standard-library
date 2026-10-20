<?php

declare(strict_types=1);

namespace Psl\H2\Internal;

use Psl\H2\StreamState;

use const Psl\H2\DEFAULT_INITIAL_WINDOW_SIZE;

/**
 * Per-stream state tracking.
 *
 * @internal
 */
final class StreamEntry
{
    /**
     * Current state of this stream in the HTTP/2 lifecycle.
     */
    public StreamState $state = StreamState::Idle;

    /**
     * Remaining send flow control window size in bytes.
     */
    public int $sendWindow;

    /**
     * Remaining receive flow control window size in bytes.
     */
    public int $receiveWindow;

    /**
     * Whether initial HEADERS have been received on this stream.
     */
    public bool $receivedHeaders = false;

    /**
     * Request method used to determine whether a response carries content.
     */
    public null|string $requestMethod = null;

    /**
     * Value of the content-length header declared by the peer in the initial
     * HEADERS block, or null if absent (RFC 9113 §8.1.1).
     */
    public null|int $declaredContentLength = null;

    /**
     * Total bytes received in DATA frames on this stream so far.
     */
    public int $receivedDataLength = 0;

    public function __construct(
        int $initialSendWindow = DEFAULT_INITIAL_WINDOW_SIZE,
        int $initialReceiveWindow = DEFAULT_INITIAL_WINDOW_SIZE,
    ) {
        $this->sendWindow = $initialSendWindow;
        $this->receiveWindow = $initialReceiveWindow;
    }
}
