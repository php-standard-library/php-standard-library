<?php

declare(strict_types=1);

namespace Psl\H2\Frame;

use Override;
use Psl\H2\Exception\FrameDecodingException;
use Psl\H2\Exception\ProtocolException;

use function ord;
use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * Notifies the peer of a stream the server intends to initiate.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.6
 *
 * @api
 */
final readonly class PushPromiseFrame implements FrameInterface
{
    /** The frame type, always {@see FrameType::PushPromise}. */
    public FrameType $type;

    /**
     * @param int<1, max> $streamId The stream associated with the push promise.
     * @param int<1, max> $promisedStreamId The stream reserved for the pushed response.
     * @param string $headerBlockFragment The HPACK-encoded header block fragment.
     * @param bool $endHeaders Whether the END_HEADERS flag is set.
     */
    public function __construct(
        public int $streamId,
        public int $promisedStreamId,
        public string $headerBlockFragment,
        public bool $endHeaders,
    ) {
        $this->type = FrameType::PushPromise;
    }

    /**
     * Parse a raw frame into a PushPromiseFrame, extracting the promised stream ID and header block fragment.
     *
     * @throws ProtocolException If the payload is too short or has an invalid fixed size.
     * @throws FrameDecodingException If the stream IDs are missing or the payload is malformed.
     */
    #[Override]
    public static function fromRaw(RawFrame $frame): static
    {
        $payload = $frame->payload;
        $offset = 0;
        $endHeaders = ($frame->flags & 0x04) !== 0;
        $padded = ($frame->flags & 0x08) !== 0;
        $payloadLength = strlen($payload);

        $padLength = 0;
        if ($padded) {
            if ($payloadLength < 1) {
                throw ProtocolException::forFrameSizeError(1, $payloadLength);
            }

            $padLength = ord($payload[0]);
            if ($padLength >= $payloadLength) {
                throw FrameDecodingException::forInvalidPaddingLength($padLength, $payloadLength);
            }

            $offset = 1;
        }

        if (($payloadLength - $offset) < 4) {
            throw ProtocolException::forFrameSizeError($offset + 4, $payloadLength);
        }

        /** @var int<0, max> $promisedStreamId */
        $promisedStreamId = unpack('N', $payload, $offset)[1] & 0x7FFF_FFFF;
        $offset += 4;

        $dataLength = $payloadLength - $offset - $padLength;
        if ($dataLength < 0) {
            throw FrameDecodingException::forInvalidPaddingLength($padLength, $payloadLength);
        }

        $headerBlockFragment = substr($payload, $offset, $dataLength);
        if (0 === $promisedStreamId || 0 === $frame->streamId) {
            throw FrameDecodingException::forStreamIdRequired($frame->type);
        }

        return new self($frame->streamId, $promisedStreamId, $headerBlockFragment, $endHeaders);
    }

    /**
     * Serialize this PUSH_PROMISE frame into a RawFrame with the promised stream ID and header block fragment.
     *
     * Padding is not added during serialization.
     */
    #[Override]
    public function toRaw(): RawFrame
    {
        return new RawFrame(
            FrameType::PushPromise->value,
            $this->endHeaders ? 0x04 : 0x00,
            $this->streamId,
            pack('N', $this->promisedStreamId & 0x7FFF_FFFF) . $this->headerBlockFragment,
        );
    }
}
