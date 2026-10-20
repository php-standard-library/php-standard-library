<?php

declare(strict_types=1);

namespace Psl\H2\Frame;

use Override;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\FrameDecodingException;
use Psl\H2\Exception\ProtocolException;

use function pack;
use function strlen;
use function unpack;

/**
 * Terminates a stream immediately.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.4
 *
 * @api
 */
final readonly class RstStreamFrame implements FrameInterface
{
    /** The frame type, always {@see FrameType::RstStream}. */
    public FrameType $type;

    /**
     * @param int<0, max> $streamId The stream being terminated.
     * @param ErrorCode $errorCode The reason for termination.
     */
    public function __construct(
        public int $streamId,
        public ErrorCode $errorCode,
    ) {
        $this->type = FrameType::RstStream;
    }

    /**
     * Parse a raw frame into a RstStreamFrame, extracting the error code.
     *
     * @throws ProtocolException If the payload is too short or has an invalid fixed size.
     * @throws FrameDecodingException If the stream ID is zero or the payload is not exactly 4 bytes.
     */
    #[Override]
    public static function fromRaw(RawFrame $frame): static
    {
        if ($frame->streamId === 0) {
            throw FrameDecodingException::forStreamIdRequired($frame->type);
        }

        if (strlen($frame->payload) !== 4) {
            throw ProtocolException::forFrameSizeError(4, strlen($frame->payload));
        }

        $errorCode = ErrorCode::tryFrom(unpack('N', $frame->payload, 0)[1]) ?? ErrorCode::InternalError;

        return new self($frame->streamId, $errorCode);
    }

    /**
     * Serialize this RST_STREAM frame into a RawFrame with a 4-byte payload encoding the error code.
     */
    #[Override]
    public function toRaw(): RawFrame
    {
        return new RawFrame(FrameType::RstStream->value, 0, $this->streamId, pack('N', $this->errorCode->value));
    }
}
