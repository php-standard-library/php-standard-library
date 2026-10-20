<?php

declare(strict_types=1);

namespace Psl\H2\Frame;

use Override;
use Psl\H2\Exception\FrameDecodingException;

use function pack;
use function strlen;
use function unpack;

/**
 * Conveys configuration parameters for the connection.
 *
 * A SETTINGS ACK has an empty settings array and ack set to true.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.5
 *
 * @api
 */
final readonly class SettingsFrame implements FrameInterface
{
    /**
     * The stream identifier, always 0 for SETTINGS frames.
     *
     * @var int<0, max>
     */
    public int $streamId;

    /** The frame type, always {@see FrameType::Settings}. */
    public FrameType $type;

    /**
     * The last value for each setting identifier.
     *
     * @var array<positive-int, non-negative-int>
     */
    public array $settings;

    /**
     * Setting pairs in wire order, including duplicate identifiers.
     *
     * @var list<array{positive-int, non-negative-int}>
     */
    public array $settingPairs;

    /**
     * @param array<int<1, max>, int<0, max>> $settings Map of setting identifiers to values.
     * @param bool $ack Whether this is a SETTINGS ACK.
     * @param null|list<array{positive-int, non-negative-int}> $settingPairs Ordered pairs; when set, these define the settings map.
     */
    public function __construct(
        array $settings,
        public bool $ack,
        null|array $settingPairs = null,
    ) {
        $this->streamId = 0;
        $this->type = FrameType::Settings;

        if ($settingPairs === null) {
            $settingPairs = [];
            foreach ($settings as $id => $value) {
                $settingPairs[] = [$id, $value];
            }
        } else {
            $settings = [];
            foreach ($settingPairs as [$id, $value]) {
                $settings[$id] = $value;
            }
        }

        $this->settings = $settings;
        $this->settingPairs = $settingPairs;
    }

    /**
     * Parse a raw frame into a SettingsFrame, extracting key-value setting pairs.
     *
     * @throws FrameDecodingException If an ACK frame has a non-empty payload or the payload length is not a multiple of 6.
     */
    #[Override]
    public static function fromRaw(RawFrame $frame): static
    {
        $ack = ($frame->flags & 0x01) !== 0;
        $payloadLength = strlen($frame->payload);

        if ($ack && $payloadLength !== 0) {
            throw FrameDecodingException::forInvalidPayload('SETTINGS', 'ACK frame must have empty payload');
        }

        if (($payloadLength % 6) !== 0) {
            throw FrameDecodingException::forInvalidPayload('SETTINGS', 'payload length must be a multiple of 6');
        }

        /** @var list<array{positive-int, non-negative-int}> $settingPairs */
        $settingPairs = [];
        for ($i = 0; $i < $payloadLength; $i += 6) {
            $id = unpack('n', $frame->payload, $i)[1];
            $value = unpack('N', $frame->payload, $i + 2)[1];
            if ($id === 0) {
                continue;
            }

            $settingPairs[] = [$id, $value];
        }

        return new self([], $ack, $settingPairs);
    }

    /**
     * Serialize this SETTINGS frame into a RawFrame with 6-byte key-value pairs as the payload.
     *
     * An ACK frame produces an empty payload with the ACK flag set.
     */
    #[Override]
    public function toRaw(): RawFrame
    {
        $flags = $this->ack ? 0x01 : 0;
        $payload = '';
        foreach ($this->settingPairs as [$id, $value]) {
            $payload .= pack('nN', $id, $value);
        }

        return new RawFrame(FrameType::Settings->value, $flags, 0, $payload);
    }
}
