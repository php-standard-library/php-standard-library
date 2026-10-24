<?php

declare(strict_types=1);

namespace Psl\H2\Internal;

use Psl\H2\Exception\ProtocolException;

use function strlen;

use const Psl\H2\DEFAULT_MAX_HEADER_BLOCK_SIZE;

/**
 * Reassembles CONTINUATION frames into complete header blocks.
 *
 * The fragments of one block are buffered until END_HEADERS arrives. A fragment that would take the buffer past the
 * size limit is refused before it is buffered, and the block is discarded, so the buffer never holds more than the
 * limit.
 *
 * @internal
 */
final class HeaderBlockAssembler
{
    /** @var null|int<0, max> Stream ID currently being assembled, or null if inactive. */
    private null|int $streamId = null;

    /** @var string Accumulated header block fragment bytes. */
    private string $buffer = '';

    /** @var bool Whether the END_STREAM flag was set on the initial HEADERS frame. */
    private bool $endStream = false;

    /** @var bool Whether the header block originates from a PUSH_PROMISE frame. */
    private bool $isPushPromise = false;

    /** @var int The promised stream ID when assembling a PUSH_PROMISE, or 0 otherwise. */
    private int $promisedStreamId = 0;

    /**
     * @param int<0, max> $maxBufferSize Maximum accumulated header block size in bytes,
     *                                   {@see DEFAULT_MAX_HEADER_BLOCK_SIZE} by default; 0 for unlimited.
     */
    public function __construct(
        private readonly int $maxBufferSize = DEFAULT_MAX_HEADER_BLOCK_SIZE,
    ) {}

    /**
     * Whether a header block is currently being assembled (awaiting CONTINUATION frames).
     */
    public function isActive(): bool
    {
        return $this->streamId !== null;
    }

    /**
     * Return the stream ID being assembled, or null if no assembly is in progress.
     */
    public function activeStreamId(): null|int
    {
        return $this->streamId;
    }

    /**
     * @param int<0, max> $streamId
     *
     * @throws ProtocolException If the fragment exceeds the maximum size; nothing is buffered then.
     */
    public function startHeaders(int $streamId, string $fragment, bool $endStream): void
    {
        $this->reset();
        $this->checkBufferSize($fragment);
        $this->streamId = $streamId;
        $this->buffer = $fragment;
        $this->endStream = $endStream;
    }

    /**
     * @param int<0, max> $streamId
     *
     * @throws ProtocolException If the fragment exceeds the maximum size; nothing is buffered then.
     */
    public function startPushPromise(int $streamId, int $promisedStreamId, string $fragment): void
    {
        $this->reset();
        $this->checkBufferSize($fragment);
        $this->streamId = $streamId;
        $this->buffer = $fragment;
        $this->isPushPromise = true;
        $this->promisedStreamId = $promisedStreamId;
    }

    /**
     * @throws ProtocolException If the accumulated header block would exceed the maximum size; the fragment is not
     *                           buffered then, and the block being assembled is discarded.
     */
    public function append(string $fragment): void
    {
        $this->checkBufferSize($fragment);
        $this->buffer .= $fragment;
    }

    /**
     * @return array{string, bool, bool, int}
     */
    public function complete(): array
    {
        $result = [$this->buffer, $this->endStream, $this->isPushPromise, $this->promisedStreamId];
        $this->reset();

        return $result;
    }

    /**
     * Reset the assembler to its initial state, discarding any accumulated data.
     */
    public function reset(): void
    {
        $this->streamId = null;
        $this->buffer = '';
        $this->endStream = false;
        $this->isPushPromise = false;
        $this->promisedStreamId = 0;
    }

    /**
     * Refuse a fragment that would take the buffer past the configured maximum, before it is buffered.
     *
     * @throws ProtocolException If the buffer and the fragment together exceed the configured maximum.
     */
    private function checkBufferSize(string $fragment): void
    {
        if ($this->maxBufferSize > 0 && (strlen($this->buffer) + strlen($fragment)) > $this->maxBufferSize) {
            $this->reset();

            throw ProtocolException::forConnectionError(
                'Header block size exceeds maximum of ' . $this->maxBufferSize . ' bytes',
            );
        }
    }
}
