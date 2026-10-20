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
 * Opens a stream and carries a header block fragment, optionally with priority.
 *
 * If END_HEADERS is not set, one or more CONTINUATION frames must follow.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.2
 *
 * @api
 */
final readonly class HeadersFrame implements FrameInterface
{
    /** The frame type, always {@see FrameType::Headers}. */
    public FrameType $type;

    /**
     * @param int<1, max> $streamId The stream identifier.
     * @param string $headerBlockFragment The HPACK-encoded header block fragment.
     * @param bool $endStream Whether the END_STREAM flag is set.
     * @param bool $endHeaders Whether the END_HEADERS flag is set.
     * @param null|int<0, max> $streamDependency The dependent stream (present when PRIORITY flag is set).
     * @param null|int<1, 256> $weight The weight for priority (1-256, present when PRIORITY flag is set).
     * @param bool $exclusive Whether this is an exclusive dependency.
     */
    public function __construct(
        public int $streamId,
        public string $headerBlockFragment,
        public bool $endStream,
        public bool $endHeaders,
        public null|int $streamDependency = null,
        public null|int $weight = null,
        public bool $exclusive = false,
    ) {
        $this->type = FrameType::Headers;
    }

    /**
     * Parse a raw frame into a HeadersFrame, extracting padding, priority, and header block fragment.
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
        $offset = 0;
        $endStream = ($frame->flags & 0x01) !== 0;
        $endHeaders = ($frame->flags & 0x04) !== 0;
        $padded = ($frame->flags & 0x08) !== 0;
        $priority = ($frame->flags & 0x20) !== 0;
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

        $streamDependency = null;
        $weight = null;
        $exclusive = false;
        if ($priority) {
            if (($payloadLength - $offset) < 5) {
                throw ProtocolException::forFrameSizeError($offset + 5, $payloadLength);
            }

            /** @var int<0, max> $depValue */
            $depValue = unpack('N', $payload, $offset)[1];
            $exclusive = ($depValue & 0x8000_0000) !== 0;
            /** @var int<0, max> $streamDependency */
            $streamDependency = $depValue & 0x7FFF_FFFF;
            $weight = ord($payload[$offset + 4]) + 1;
            $offset += 5;
        }

        $dataLength = $payloadLength - $offset - $padLength;
        if ($dataLength < 0) {
            throw FrameDecodingException::forInvalidPaddingLength($padLength, $payloadLength);
        }

        $headerBlockFragment = substr($payload, $offset, $dataLength);

        return new self(
            $frame->streamId,
            $headerBlockFragment,
            $endStream,
            $endHeaders,
            $streamDependency,
            $weight,
            $exclusive,
        );
    }

    /**
     * Serialize this HEADERS frame into a RawFrame with appropriate flags and optional priority fields.
     *
     * Padding is not added during serialization. When stream dependency is present, the PRIORITY flag
     * is set and the dependency/weight fields are prepended to the payload.
     */
    #[Override]
    public function toRaw(): RawFrame
    {
        $flags = 0;
        $payload = '';

        if ($this->endStream) {
            $flags |= 0x01;
        }

        if ($this->endHeaders) {
            $flags |= 0x04;
        }

        if ($this->streamDependency !== null) {
            $flags |= 0x20;
            $depValue = $this->streamDependency;
            if ($this->exclusive) {
                $depValue |= 0x8000_0000;
            }

            $payload .= pack('NC', $depValue, ($this->weight ?? 16) - 1);
        }

        $payload .= $this->headerBlockFragment;

        return new RawFrame(FrameType::Headers->value, $flags, $this->streamId, $payload);
    }
}
