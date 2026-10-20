<?php

declare(strict_types=1);

namespace Psl\H2\Frame;

use Override;
use Psl\H2\Exception\ProtocolException;

use function strlen;

/**
 * Measures round-trip time and verifies connection liveness.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.7
 *
 * @api
 */
final readonly class PingFrame implements FrameInterface
{
    /**
     * The stream identifier, always 0 for PING frames.
     *
     * @var int<0, max>
     */
    public int $streamId;
    /**
     * The frame type, always {@see FrameType::Ping}.
     */
    public FrameType $type;

    /**
     * @param non-empty-string $opaqueData Exactly 8 bytes of opaque data.
     * @param bool $ack Whether this is a PING ACK (response to a received PING).
     */
    public function __construct(
        public string $opaqueData,
        public bool $ack,
    ) {
        $this->streamId = 0;
        $this->type = FrameType::Ping;
    }

    /**
     * Parse a raw frame into a PingFrame, extracting the opaque data and ACK flag.
     *
     * @throws ProtocolException If the payload is not exactly 8 bytes.
     */
    #[Override]
    public static function fromRaw(RawFrame $frame): static
    {
        $payload = $frame->payload;
        if ('' === $payload || strlen($payload) !== 8) {
            throw ProtocolException::forFrameSizeError(8, strlen($frame->payload));
        }

        $ack = ($frame->flags & 0x01) !== 0;

        return new self($payload, $ack);
    }

    /**
     * Serialize this PING frame into a RawFrame with the 8-byte opaque data payload and ACK flag.
     */
    #[Override]
    public function toRaw(): RawFrame
    {
        return new RawFrame(FrameType::Ping->value, $this->ack ? 0x01 : 0x00, 0, $this->opaqueData);
    }
}
