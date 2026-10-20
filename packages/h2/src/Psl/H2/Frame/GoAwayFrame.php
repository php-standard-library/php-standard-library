<?php

declare(strict_types=1);

namespace Psl\H2\Frame;

use Override;
use Psl\H2\Exception\ProtocolException;

use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * Initiates graceful connection shutdown.
 *
 * Streams up to lastStreamId may still complete; higher-numbered streams were not processed.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.8
 *
 * @api
 */
final readonly class GoAwayFrame implements FrameInterface
{
    /**
     * The stream identifier, always 0 for GOAWAY frames.
     *
     * @var int<0, max>
     */
    public int $streamId;

    /** The frame type, always {@see FrameType::GoAway}. */
    public FrameType $type;

    /**
     * @param int<0, max> $lastStreamId The highest peer-initiated stream ID that was processed.
     * @param int<0, max> $errorCode The error code indicating the reason for shutdown.
     * @param string $debugData Optional diagnostic data (opaque to the protocol).
     */
    public function __construct(
        public int $lastStreamId,
        public int $errorCode,
        public string $debugData,
    ) {
        $this->streamId = 0;
        $this->type = FrameType::GoAway;
    }

    /**
     * Parse a raw frame into a GoAwayFrame, extracting the last stream ID, error code, and optional debug data.
     *
     * @throws ProtocolException If the payload is less than 8 bytes.
     */
    #[Override]
    public static function fromRaw(RawFrame $frame): static
    {
        $payloadLength = strlen($frame->payload);
        if ($payloadLength < 8) {
            throw ProtocolException::forFrameSizeError(8, $payloadLength);
        }

        /** @var int<0, max> $lastStreamId */
        $lastStreamId = unpack('N', $frame->payload, 0)[1] & 0x7FFF_FFFF;
        /** @var int<0, max> $errorCode */
        $errorCode = unpack('N', $frame->payload, 4)[1];
        $debugData = $payloadLength > 8 ? substr($frame->payload, 8) : '';

        return new self($lastStreamId, $errorCode, $debugData);
    }

    /**
     * Serialize this GOAWAY frame into a RawFrame with the last stream ID, error code, and optional debug data.
     */
    #[Override]
    public function toRaw(): RawFrame
    {
        return new RawFrame(
            FrameType::GoAway->value,
            0,
            0,
            pack('NN', $this->lastStreamId & 0x7FFF_FFFF, $this->errorCode) . $this->debugData,
        );
    }
}
