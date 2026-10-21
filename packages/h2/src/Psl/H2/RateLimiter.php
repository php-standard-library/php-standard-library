<?php

declare(strict_types=1);

namespace Psl\H2;

use Psl\DateTime;
use Psl\Default\DefaultInterface;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame\FrameType;

use function bin2hex;
use function chr;

/**
 * Sliding-window rate limiter for HTTP/2 control frames.
 *
 * Protects against flood attacks by limiting how many frames of each type
 * a peer can send within a configurable time window. When a limit is exceeded,
 * a {@see ProtocolException} is thrown, which results in a GOAWAY being sent.
 *
 * Use {@see self::EMPTY_DATA_FRAME} to limit empty DATA frames (zero-length
 * DATA without the END_STREAM flag), which are a common denial-of-service vector.
 *
 * @api
 */
final class RateLimiter implements DefaultInterface
{
    public const int EMPTY_DATA_FRAME = -1;

    public const int STREAM_ERROR_RESET = -2;

    /**
     * @var array<int, int>
     */
    private array $counts = [];

    /**
     * @var array<int, DateTime\Timestamp>
     */
    private array $windowStarts = [];

    /**
     * Create a new rate limiter with the given per-frame-type limits.
     *
     * @param array<-2|-1|int<0, 9>, array{int, DateTime\Duration}> $limits Map of frame type value to [max count, window duration].
     *  Use {@see FrameType} values, {@see self::EMPTY_DATA_FRAME}, or {@see self::STREAM_ERROR_RESET} as keys.
     */
    public function __construct(
        private readonly array $limits,
    ) {}

    /**
     * Create a rate limiter with sensible defaults for all commonly abused frame types.
     *
     * Default limits (per 10-second window):
     * - SETTINGS: 100
     * - PING: 50
     * - RST_STREAM: 100
     * - PRIORITY: 100
     * - Empty DATA: 100
     * - Resets caused by peer errors: 100
     */
    public static function default(): static
    {
        return new static([
            FrameType::Settings->value => [100, DateTime\Duration::seconds(10)],
            FrameType::Ping->value => [50, DateTime\Duration::seconds(10)],
            FrameType::RstStream->value => [100, DateTime\Duration::seconds(10)],
            FrameType::Priority->value => [100, DateTime\Duration::seconds(10)],
            self::EMPTY_DATA_FRAME => [100, DateTime\Duration::seconds(10)],
            self::STREAM_ERROR_RESET => [100, DateTime\Duration::seconds(10)],
        ]);
    }

    /**
     * Create a rate limiter with the same limits and no recorded frames.
     *
     * A {@see Configuration} may be reused for many connections, so each connection counts its peer's frames
     * on a limiter of its own instead of the configured one.
     *
     * @internal
     */
    public function fresh(): self
    {
        return new self($this->limits);
    }

    /**
     * Record a received frame or a reset caused by a peer error, and enforce its limit.
     *
     * If the frame type has no configured limit, this is a no-op.
     * When the count exceeds the limit within the current window, a
     * connection error is raised.
     *
     * @param -2|-1|int<0, 9> $frameType The frame type value (e.g. {@see FrameType::Ping->value}), or {@see self::EMPTY_DATA_FRAME} or {@see self::STREAM_ERROR_RESET}.
     *
     * @throws ProtocolException If the rate limit for this frame type is exceeded.
     */
    public function record(int $frameType): void
    {
        if (!isset($this->limits[$frameType])) {
            return;
        }

        [$maxCount, $windowSeconds] = $this->limits[$frameType];
        $now = DateTime\Timestamp::monotonic();

        if (!isset($this->windowStarts[$frameType])) {
            $this->windowStarts[$frameType] = $now;
            $this->counts[$frameType] = 0;
        }

        $elapsed = $now->since($this->windowStarts[$frameType]);
        if ($elapsed->getTotalSeconds() >= $windowSeconds->getTotalSeconds()) {
            $this->windowStarts[$frameType] = $now;
            $this->counts[$frameType] = 0;
        }

        $this->counts[$frameType]++;

        if ($this->counts[$frameType] > $maxCount) {
            if (self::STREAM_ERROR_RESET === $frameType) {
                throw ProtocolException::forExcessiveResets();
            }

            if (self::EMPTY_DATA_FRAME === $frameType) {
                throw ProtocolException::forConnectionError('Rate limit exceeded for empty DATA frames');
            }

            $typeName = FrameType::tryFrom($frameType)->name ?? '0x' . bin2hex(chr($frameType));

            throw ProtocolException::forConnectionError('Rate limit exceeded for frame type ' . $typeName);
        }
    }
}
