<?php

declare(strict_types=1);

namespace Psl\H2;

use Override;
use Psl\Default\DefaultInterface;

/**
 * Configuration for an HTTP/2 connection (client or server).
 *
 * Replaces the role-specific {@see ClientConfiguration} and {@see ServerConfiguration}
 * with a unified configuration that supports all HTTP/2 features including
 * bandwidth-delay product (BDP) auto-tuning for receive window management.
 *
 * When {@see $maxReceiveWindowSize} is set, the connection uses a {@see Internal\BDPEstimator}
 * to dynamically size receive windows based on measured throughput and round-trip time.
 * When null, receive windows are managed with per-frame WINDOW_UPDATE acknowledgements.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.9 Flow Control
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.5 SETTINGS
 *
 * @api
 */
final readonly class Configuration implements DefaultInterface
{
    /**
     * @param array<positive-int, non-negative-int> $settings Local SETTINGS overrides (setting ID => value). These are sent to
     *  the peer in the connection preface and govern stream-level parameters such as INITIAL_WINDOW_SIZE, MAX_CONCURRENT_STREAMS,
     *  and MAX_FRAME_SIZE.
     * @param null|RateLimiter $rateLimiter Optional rate limiter for incoming frames. Used to detect and reject abusive peers
     *  that send excessive empty frames, SETTINGS floods, or PING floods. It also configures
     *  {@see RateLimiter::STREAM_ERROR_RESET}. With null, peer errors may cause 100 resets per 10 seconds;
     *  other frame types have no limit. Pass a limiter without that key to disable the reset limit.
     * @param int $maxHeaderBlockSize Maximum accumulated header block size in bytes before HPACK decoding. 0 means unlimited.
     *  Protects against decompression bombs from malicious peers.
     * @param null|int<1, max> $maxReceiveWindowSize Maximum receive window size in bytes for BDP auto-tuning. When set,
     *  the connection dynamically adjusts the receive window based on measured throughput and RTT using PING round-trips. When null,
     *  BDP auto-tuning is disabled and receive windows are managed with per-frame WINDOW_UPDATE acknowledgements.
     * @param positive-int $writeBufferThreshold Buffered write data is flushed when it reaches this size in bytes.
     *  Higher values reduce syscall overhead at the cost of latency.
     */
    public function __construct(
        public array $settings = [],
        public null|RateLimiter $rateLimiter = null,
        public int $maxHeaderBlockSize = 0,
        public null|int $maxReceiveWindowSize = null,
        public int $writeBufferThreshold = 65_536,
    ) {}

    #[Override]
    public static function default(): static
    {
        return new self();
    }

    /**
     * @param array<positive-int, non-negative-int> $settings
     */
    public function withSettings(array $settings): self
    {
        return new self(
            $settings,
            $this->rateLimiter,
            $this->maxHeaderBlockSize,
            $this->maxReceiveWindowSize,
            $this->writeBufferThreshold,
        );
    }

    public function withRateLimiter(null|RateLimiter $rateLimiter): self
    {
        return new self(
            $this->settings,
            $rateLimiter,
            $this->maxHeaderBlockSize,
            $this->maxReceiveWindowSize,
            $this->writeBufferThreshold,
        );
    }

    /**
     * @param int<0, max> $maxHeaderBlockSize 0 for unlimited.
     */
    public function withMaxHeaderBlockSize(int $maxHeaderBlockSize): self
    {
        return new self(
            $this->settings,
            $this->rateLimiter,
            $maxHeaderBlockSize,
            $this->maxReceiveWindowSize,
            $this->writeBufferThreshold,
        );
    }

    /**
     * @param null|int<1, max> $maxReceiveWindowSize Null to disable BDP auto-tuning, or the maximum window size in bytes.
     */
    public function withMaxReceiveWindowSize(null|int $maxReceiveWindowSize): self
    {
        return new self(
            $this->settings,
            $this->rateLimiter,
            $this->maxHeaderBlockSize,
            $maxReceiveWindowSize,
            $this->writeBufferThreshold,
        );
    }

    /**
     * @param positive-int $writeBufferThreshold
     */
    public function withWriteBufferThreshold(int $writeBufferThreshold): self
    {
        return new self(
            $this->settings,
            $this->rateLimiter,
            $this->maxHeaderBlockSize,
            $this->maxReceiveWindowSize,
            $writeBufferThreshold,
        );
    }
}
