<?php

declare(strict_types=1);

namespace Psl\H2\Frame;

use Override;
use Psl\H2\Exception\FrameDecodingException;
use Psl\H2\Exception\ProtocolException;

use function pack;
use function strlen;
use function unpack;

/**
 * Increases the flow control window for a stream or the connection.
 *
 * A streamId of 0 applies to the connection-level flow control window.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.9
 *
 * @api
 */
final readonly class WindowUpdateFrame implements FrameInterface
{
    /** The frame type, always {@see FrameType::WindowUpdate}. */
    public FrameType $type;

    /**
     * @param int<0, max> $streamId The stream (0 for connection-level).
     * @param int<1, 2147483647> $windowSizeIncrement The number of bytes to add to the window.
     */
    public function __construct(
        public int $streamId,
        public int $windowSizeIncrement,
    ) {
        $this->type = FrameType::WindowUpdate;
    }

    /**
     * Parse a raw frame into a WindowUpdateFrame, extracting the window size increment.
     *
     * @throws ProtocolException If the payload is too short or has an invalid fixed size.
     * @throws FrameDecodingException If the payload is not exactly 4 bytes or the increment is zero.
     */
    #[Override]
    public static function fromRaw(RawFrame $frame): static
    {
        if (strlen($frame->payload) !== 4) {
            throw ProtocolException::forFrameSizeError(4, strlen($frame->payload));
        }

        $increment = unpack('N', $frame->payload, 0)[1] & 0x7FFF_FFFF;

        if ($increment === 0) {
            throw FrameDecodingException::forInvalidWindowUpdateIncrement(0);
        }

        return new self($frame->streamId, $increment);
    }

    /**
     * Serialize this WINDOW_UPDATE frame into a RawFrame with a 4-byte payload encoding the window size increment.
     */
    #[Override]
    public function toRaw(): RawFrame
    {
        return new RawFrame(
            FrameType::WindowUpdate->value,
            0,
            $this->streamId,
            pack('N', $this->windowSizeIncrement & 0x7FFF_FFFF),
        );
    }
}
