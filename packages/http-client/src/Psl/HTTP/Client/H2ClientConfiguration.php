<?php

declare(strict_types=1);

namespace Psl\HTTP\Client;

use Psl\H2;

/**
 * HTTP/2-specific session configuration parameters.
 *
 * These settings control the HTTP/2 connection-level behavior as defined in RFC 9113.
 * They are communicated to the server via the SETTINGS frame during the HTTP/2 connection
 * preface and govern flow control, frame sizes, HPACK header compression limits, and
 * concurrency.
 *
 * Each setting has a sensible default that works well for typical HTTP client workloads.
 * The `with*()` methods return new instances with the specified parameter changed,
 * leaving the original configuration unmodified.
 *
 * ## Flow control
 *
 * HTTP/2 uses a credit-based flow-control mechanism at both the connection and stream
 * levels. The {@see $initialWindowSize} controls the initial per-stream window, while
 * {@see $maxReceiveWindowSize} sets the upper bound for the auto-tuned receive window
 * based on bandwidth-delay product (BDP) estimation.
 *
 * ## Header compression
 *
 * HPACK (RFC 7541) compresses headers before transmission. The {@see $maxHeaderListSize}
 * limits the decompressed size of the header list, while {@see $maxHeaderBlockSize}
 * limits the raw (pre-decompression) header block fragment size to protect against
 * compression-based denial-of-service attacks (HPACK bomb).
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113 HTTP/2
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.5 SETTINGS Frame
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-5.2 Flow Control
 * @link https://datatracker.ietf.org/doc/html/rfc7541 HPACK Header Compression
 *
 * @api
 */
final readonly class H2ClientConfiguration
{
    /**
     * @param positive-int $initialWindowSize Initial per-stream flow-control window size in bytes. This value is sent as the SETTINGS_INITIAL_WINDOW_SIZE
     *  parameter. A larger window allows the server to send more data before waiting for a WINDOW_UPDATE, reducing latency on high-bandwidth links.
     *  Defaults to 1,048,576 bytes (1 MiB). Valid range per RFC 9113 Section 6.5.2: 0 to 2^31-1.
     * @param positive-int $maxFrameSize Maximum size of a single frame payload in bytes. This value is sent as the SETTINGS_MAX_FRAME_SIZE
     *  parameter. Must be between 16,384 (the protocol minimum) and 16,777,215 (2^24-1) per RFC 9113 Section 6.5.2. Defaults to the protocol
     *  minimum (16,384 bytes).
     * @param positive-int $maxHeaderListSize Maximum decompressed size of a header list in bytes. This value is sent as the SETTINGS_MAX_HEADER_LIST_SIZE
     *  parameter. It limits the total size of header field names and values after HPACK decompression. Defaults to 16,384 bytes (16 KiB).
     * @param positive-int $maxHeaderBlockSize Maximum accumulated size of raw header block fragments in bytes before HPACK decoding. This is a local safety
     *  limit (not an HTTP/2 setting) that protects against HPACK compression bombs where a small compressed payload decompresses into an enormous header list.
     *  A value of 0 disables the limit. Defaults to 65,536 bytes (64 KiB).
     * @param positive-int $maxConcurrentStreams Maximum number of concurrent streams the client will accept per HTTP/2 connection.
     *  This value is sent as the SETTINGS_MAX_CONCURRENT_STREAMS parameter. The peer's setting limits outgoing requests.
     *  Defaults to 100.
     * @param positive-int $maxConcurrentPushes Maximum number of server push (PUSH_PROMISE) streams the client will accept per request.
     *  Push promises exceeding this limit are rejected with RST_STREAM. Defaults to 10.
     * @param positive-int $maxReceiveWindowSize Maximum connection-level receive window size in bytes for bandwidth-delay product (BDP) auto-tuning.
     *  The flow-control layer may dynamically increase the window up to this limit based on observed throughput and RTT. Defaults to 16,777,216 bytes (16 MiB).
     *
     * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.5.2 Defined Settings Parameters
     */
    public function __construct(
        public int $initialWindowSize = 1_048_576,
        public int $maxFrameSize = H2\DEFAULT_MAX_FRAME_SIZE,
        public int $maxHeaderListSize = 16_384,
        public int $maxHeaderBlockSize = 65_536,
        public int $maxConcurrentStreams = 100,
        public int $maxConcurrentPushes = 10,
        public int $maxReceiveWindowSize = 16_777_216,
    ) {}

    /**
     * Return a new configuration with the given initial per-stream flow-control window size.
     *
     * @param positive-int $initialWindowSize
     *
     * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.5.2 SETTINGS_INITIAL_WINDOW_SIZE
     */
    public function withInitialWindowSize(int $initialWindowSize): self
    {
        return new self(
            $initialWindowSize,
            $this->maxFrameSize,
            $this->maxHeaderListSize,
            $this->maxHeaderBlockSize,
            $this->maxConcurrentStreams,
            $this->maxConcurrentPushes,
            $this->maxReceiveWindowSize,
        );
    }

    /**
     * Return a new configuration with the given maximum frame payload size.
     *
     * @param positive-int $maxFrameSize
     *
     * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.5.2 SETTINGS_MAX_FRAME_SIZE
     */
    public function withMaxFrameSize(int $maxFrameSize): self
    {
        return new self(
            $this->initialWindowSize,
            $maxFrameSize,
            $this->maxHeaderListSize,
            $this->maxHeaderBlockSize,
            $this->maxConcurrentStreams,
            $this->maxConcurrentPushes,
            $this->maxReceiveWindowSize,
        );
    }

    /**
     * Return a new configuration with the given maximum decompressed header list size.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.5.2 SETTINGS_MAX_HEADER_LIST_SIZE
     *
     * @param positive-int $maxHeaderListSize
     */
    public function withMaxHeaderListSize(int $maxHeaderListSize): self
    {
        return new self(
            $this->initialWindowSize,
            $this->maxFrameSize,
            $maxHeaderListSize,
            $this->maxHeaderBlockSize,
            $this->maxConcurrentStreams,
            $this->maxConcurrentPushes,
            $this->maxReceiveWindowSize,
        );
    }

    /**
     * Return a new configuration with the given maximum raw header block fragment size.
     *
     * This is a local safety limit (not an HTTP/2 SETTINGS parameter) that bounds the
     * accumulated size of raw header block fragments before HPACK decompression.
     *
     * @param positive-int $maxHeaderBlockSize
     */
    public function withMaxHeaderBlockSize(int $maxHeaderBlockSize): self
    {
        return new self(
            $this->initialWindowSize,
            $this->maxFrameSize,
            $this->maxHeaderListSize,
            $maxHeaderBlockSize,
            $this->maxConcurrentStreams,
            $this->maxConcurrentPushes,
            $this->maxReceiveWindowSize,
        );
    }

    /**
     * Return a new configuration with the given maximum number of concurrent streams.
     *
     * @param positive-int $maxConcurrentStreams
     *
     * @link https://datatracker.ietf.org/doc/html/rfc9113#section-6.5.2 SETTINGS_MAX_CONCURRENT_STREAMS
     */
    public function withMaxConcurrentStreams(int $maxConcurrentStreams): self
    {
        return new self(
            $this->initialWindowSize,
            $this->maxFrameSize,
            $this->maxHeaderListSize,
            $this->maxHeaderBlockSize,
            $maxConcurrentStreams,
            $this->maxConcurrentPushes,
            $this->maxReceiveWindowSize,
        );
    }

    /**
     * Return a new configuration with the given maximum number of server push streams per request.
     *
     * @param positive-int $maxConcurrentPushes Maximum number of PUSH_PROMISE streams accepted per request.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc9113#section-8.4 Server Push
     */
    public function withMaxConcurrentPushes(int $maxConcurrentPushes): self
    {
        return new self(
            $this->initialWindowSize,
            $this->maxFrameSize,
            $this->maxHeaderListSize,
            $this->maxHeaderBlockSize,
            $this->maxConcurrentStreams,
            $maxConcurrentPushes,
            $this->maxReceiveWindowSize,
        );
    }

    /**
     * Return a new configuration with the given maximum receive window size for BDP auto-tuning.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc9113#section-5.2 Flow Control
     *
     * @param positive-int $maxReceiveWindowSize
     */
    public function withMaxReceiveWindowSize(int $maxReceiveWindowSize): self
    {
        return new self(
            $this->initialWindowSize,
            $this->maxFrameSize,
            $this->maxHeaderListSize,
            $this->maxHeaderBlockSize,
            $this->maxConcurrentStreams,
            $this->maxConcurrentPushes,
            $maxReceiveWindowSize,
        );
    }
}
