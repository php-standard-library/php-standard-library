<?php

declare(strict_types=1);

namespace Psl\H2\Frame;

use Override;
use Psl\H2\Exception\FrameDecodingException;
use Psl\H2\Exception\StreamException;

use function ord;
use function pack;
use function strlen;
use function unpack;

/**
 * Specifies the sender-advised priority of a stream (deprecated in RFC 9113).
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.3
 *
 * @api
 */
final readonly class PriorityFrame implements FrameInterface
{
    /** The frame type, always {@see FrameType::Priority}. */
    public FrameType $type;

    /**
     * @param int<1, max> $streamId The stream whose priority is being updated.
     * @param int<0, max> $streamDependency The stream this one depends on.
     * @param int<1, 256> $weight The weight for priority (1-256).
     * @param bool $exclusive Whether this is an exclusive dependency.
     */
    public function __construct(
        public int $streamId,
        public int $streamDependency,
        public int $weight,
        public bool $exclusive,
    ) {
        $this->type = FrameType::Priority;
    }

    /**
     * Parse a raw frame into a PriorityFrame, extracting stream dependency, weight, and exclusivity.
     *
     * @throws StreamException If the payload is too short or has an invalid fixed size.
     * @throws FrameDecodingException If the stream ID is zero or the payload is not exactly 5 bytes.
     */
    #[Override]
    public static function fromRaw(RawFrame $frame): static
    {
        if ($frame->streamId === 0) {
            throw FrameDecodingException::forStreamIdRequired($frame->type);
        }

        if (strlen($frame->payload) !== 5) {
            throw StreamException::forFrameSizeError($frame->streamId, 5, strlen($frame->payload));
        }

        /** @var int<0, max> $depValue */
        $depValue = unpack('N', $frame->payload, 0)[1];
        $exclusive = ($depValue & 0x8000_0000) !== 0;
        /** @var int<0, max> $streamDependency */
        $streamDependency = $depValue & 0x7FFF_FFFF;
        $weight = ord($frame->payload[4]) + 1;

        return new self($frame->streamId, $streamDependency, $weight, $exclusive);
    }

    /**
     * Serialize this PRIORITY frame into a RawFrame with a 5-byte payload encoding the dependency and weight.
     */
    #[Override]
    public function toRaw(): RawFrame
    {
        $depValue = $this->streamDependency;
        if ($this->exclusive) {
            $depValue |= 0x8000_0000;
        }

        $payload = pack('NC', $depValue, $this->weight - 1);

        return new RawFrame(FrameType::Priority->value, 0, $this->streamId, $payload);
    }
}
