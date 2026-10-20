<?php

declare(strict_types=1);

namespace Psl\H2\Frame;

use Override;
use Psl\H2\Exception\FrameDecodingException;
use Psl\H2\Exception\ProtocolException;

use function ord;
use function strlen;
use function substr;

/**
 * Carries the application data payload for a stream.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.1
 *
 * @api
 */
final readonly class DataFrame implements FrameInterface
{
    /** The frame type, always {@see FrameType::Data}. */
    public FrameType $type;

    /**
     * @param int<1, max> $streamId The stream this data belongs to (must not be 0).
     * @param string $data The application data payload (may be empty for END_STREAM-only frames).
     * @param bool $endStream Whether the END_STREAM flag is set.
     */
    public function __construct(
        public int $streamId,
        public string $data,
        public bool $endStream,
    ) {
        $this->type = FrameType::Data;
    }

    /**
     * Parse a raw frame into a DataFrame, handling padding removal if present.
     *
     * @throws ProtocolException If the payload is too short or has an invalid fixed size.
     * @throws FrameDecodingException If the frame payload is malformed or missing required fields.
     */
    #[Override]
    public static function fromRaw(RawFrame $frame): static
    {
        if ($frame->streamId === 0) {
            throw FrameDecodingException::forStreamIdRequired($frame->type);
        }

        $payload = $frame->payload;
        $endStream = ($frame->flags & 0x01) !== 0;
        $padded = ($frame->flags & 0x08) !== 0;

        if ($padded) {
            $payloadLength = strlen($payload);
            if ($payloadLength < 1) {
                throw ProtocolException::forFrameSizeError(1, $payloadLength);
            }

            $padLength = ord($payload[0]);
            if ($padLength >= $payloadLength) {
                throw FrameDecodingException::forInvalidPaddingLength($padLength, $payloadLength);
            }

            $dataLength = $payloadLength - 1 - $padLength;
            if ($dataLength < 0) {
                throw FrameDecodingException::forInvalidPaddingLength($padLength, $payloadLength);
            }

            $payload = substr($payload, 1, $dataLength);
        }

        return new self($frame->streamId, $payload, $endStream);
    }

    /**
     * Serialize this DATA frame into a RawFrame with the END_STREAM flag set as appropriate.
     *
     * Padding is not added during serialization.
     */
    #[Override]
    public function toRaw(): RawFrame
    {
        return new RawFrame(FrameType::Data->value, $this->endStream ? 0x01 : 0x00, $this->streamId, $this->data);
    }
}
