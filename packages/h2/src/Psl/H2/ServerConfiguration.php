<?php

declare(strict_types=1);

namespace Psl\H2;

use Override;
use Psl\Default\DefaultInterface;

/**
 * Configuration for a server-side HTTP/2 connection.
 *
 * @deprecated Use {@see Configuration} instead.
 *
 * @see ServerConnection
 *
 * @mago-expect analysis:deprecated-class
 *
 * @api
 */
final readonly class ServerConfiguration implements DefaultInterface
{
    /**
     * @param array<positive-int, non-negative-int> $settings Local settings overrides (setting ID => value).
     * @param null|RateLimiter $rateLimiter Optional rate limiter for incoming frames.
     * @param int<0, max> $maxHeaderBlockSize Maximum accumulated header block size in bytes before HPACK decoding,
     *                                      {@see DEFAULT_MAX_HEADER_BLOCK_SIZE} (64 KiB) by default. Once the fragments of
     *                                      one received field block would exceed it, the connection closes with
     *                                      PROTOCOL_ERROR before buffering them; 0 lifts the limit, which lets a peer that
     *                                      never ends a field block make the connection buffer without bound.
     * @param null|int<1, max> $maxReceiveWindowSize Maximum receive window size for BDP auto-tuning.
     *                                               Null disables BDP auto-tuning; a value enables it
     *                                               and caps the dynamically adjusted window at this size.
     * @param positive-int $writeBufferThreshold Buffered write data is flushed when it reaches this size in bytes.
     */
    public function __construct(
        public array $settings = [],
        public null|RateLimiter $rateLimiter = null,
        public int $maxHeaderBlockSize = namespace\DEFAULT_MAX_HEADER_BLOCK_SIZE,
        public null|int $maxReceiveWindowSize = null,
        public int $writeBufferThreshold = 65_536,
    ) {}

    #[Override]
    public static function default(): static
    {
        return new self();
    }

    /**
     * Return a new configuration with the given settings overrides.
     *
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

    /**
     * Return a new configuration with the given rate limiter.
     */
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
     * Return a new configuration with the given maximum header block size.
     *
     * @param int<0, max> $maxHeaderBlockSize The most bytes the fragments of one received field block may add up to,
     *                                      {@see DEFAULT_MAX_HEADER_BLOCK_SIZE} by default; 0 for unlimited.
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
     * Return a new configuration with BDP auto-tuning enabled at the given window cap.
     *
     * @param null|int<1, max> $maxReceiveWindowSize Null to disable, or the maximum window size in bytes.
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
     * Return a new configuration with the given write buffer flush threshold.
     *
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
