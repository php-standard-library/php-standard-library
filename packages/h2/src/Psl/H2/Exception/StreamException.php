<?php

declare(strict_types=1);

namespace Psl\H2\Exception;

use Psl\H2\ErrorCode;

/**
 * Thrown when an operation targets an HTTP/2 stream that is in an
 * unexpected or unusable state.
 *
 * Each factory method corresponds to a specific stream-level failure
 * condition defined by RFC 9113.
 *
 * @api
 */
final class StreamException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $streamId,
        public readonly ErrorCode $errorCode = ErrorCode::ProtocolError,
    ) {
        parent::__construct($message);
    }

    /**
     * Create a stream error for malformed HTTP fields (RFC 9113 §8.1.1).
     */
    public static function forMalformedMessage(int $streamId, string $detail): self
    {
        return new self('Stream ' . $streamId . ' has malformed HTTP fields: ' . $detail . '.', $streamId);
    }

    /**
     * Create a stream exception when a frame is received for a stream
     * that is not in the expected state for that frame type.
     *
     * This is thrown, for example, when a DATA frame arrives on a
     * half-closed (remote) or idle stream.
     *
     * @param int $streamId The stream that is in the wrong state.
     * @param string $expected The state the stream was expected to be in.
     * @param string $actual The state the stream is actually in.
     */
    public static function forInvalidState(
        int $streamId,
        string $expected,
        string $actual,
        ErrorCode $errorCode = ErrorCode::ProtocolError,
    ): self {
        return new self(
            'Stream ' . $streamId . ' is in state ' . $actual . ', expected ' . $expected . '.',
            $streamId,
            $errorCode,
        );
    }

    /**
     * Create a stream exception when an operation is attempted on a
     * stream that has already been fully closed.
     *
     * @param int $streamId The closed stream identifier.
     */
    public static function forStreamClosed(int $streamId): self
    {
        return new self('Stream ' . $streamId . ' is closed.', $streamId, ErrorCode::StreamClosed);
    }

    /**
     * Create a stream exception when the remote peer refuses a newly
     * initiated stream via a RST_STREAM with REFUSED_STREAM.
     *
     * The request that opened this stream was never processed and may
     * be safely retried on a new stream.
     *
     * @param int $streamId The refused stream identifier.
     */
    public static function forStreamRefused(int $streamId): self
    {
        return new self('Stream ' . $streamId . ' was refused.', $streamId, ErrorCode::RefusedStream);
    }

    /**
     * Create a stream exception when a stream is terminated by the
     * remote peer via a RST_STREAM frame.
     *
     * @param int $streamId The reset stream identifier.
     * @param int $errorCode The HTTP/2 error code received in the RST_STREAM frame.
     */
    public static function forStreamReset(int $streamId, int $errorCode): self
    {
        return new self(
            'Stream ' . $streamId . ' was reset with error code ' . $errorCode . '.',
            $streamId,
            ErrorCode::tryFrom($errorCode) ?? ErrorCode::InternalError,
        );
    }

    /**
     * Create a stream exception when the total bytes received in DATA frames
     * do not match the content-length header declared by the peer
     * (RFC 9113 §8.1.1 and §8.1.2.6).
     *
     * @param int $streamId The stream identifier.
     * @param int $declared The content-length declared in the HEADERS frame.
     * @param int $actual The actual total bytes received.
     */
    public static function forContentLengthMismatch(int $streamId, int $declared, int $actual): self
    {
        return new self(
            'Stream '
            . $streamId
            . ' content-length mismatch: declared '
            . $declared
            . ' bytes, received '
            . $actual
            . ' bytes.',
            $streamId,
        );
    }

    /**
     * Create a stream exception when the content-length header contains a
     * value that is not a non-negative decimal integer (RFC 9110 §8.6).
     *
     * @param int $streamId The stream identifier.
     * @param string $value The malformed content-length value.
     */
    public static function forMalformedContentLength(int $streamId, string $value): self
    {
        return new self('Stream ' . $streamId . ' has malformed content-length: "' . $value . '".', $streamId);
    }

    /**
     * Create a stream exception when a message carries multiple content-length
     * fields that do not agree, which makes it invalid (RFC 9110 §8.6).
     *
     * @param int $streamId The stream identifier.
     * @param string $first The first content-length value seen.
     * @param string $second The conflicting value that followed it.
     */
    public static function forConflictingContentLength(int $streamId, string $first, string $second): self
    {
        return new self(
            'Stream ' . $streamId . ' has conflicting content-length fields: "' . $first . '" and "' . $second . '".',
            $streamId,
        );
    }
}
