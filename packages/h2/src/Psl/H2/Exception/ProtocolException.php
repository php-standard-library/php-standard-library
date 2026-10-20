<?php

declare(strict_types=1);

namespace Psl\H2\Exception;

use Psl\H2\ErrorCode;
use Throwable;

/**
 * Thrown when an HTTP/2 protocol violation is detected.
 *
 * Carries the appropriate {@see ErrorCode} to include in the GOAWAY
 * or RST_STREAM frame sent to the peer.
 *
 * @api
 */
final class ProtocolException extends RuntimeException
{
    /**
     * The HTTP/2 error code associated with this protocol violation.
     */
    public readonly ErrorCode $errorCode;

    /**
     * @param string $message Human-readable description of the violation.
     * @param ErrorCode $errorCode The error code to report to the peer.
     * @param null|Throwable $previous Optional cause of this exception.
     */
    private function __construct(
        string $message,
        ErrorCode $errorCode = ErrorCode::ProtocolError,
        null|Throwable $previous = null,
    ) {
        parent::__construct($message, $previous);
        $this->errorCode = $errorCode;
    }

    /**
     * Create a protocol exception for a general connection-level error.
     *
     * @param string $detail Description of the error condition.
     * @param null|Throwable $previous Optional cause of this exception.
     */
    public static function forConnectionError(string $detail, null|Throwable $previous = null): self
    {
        return new self('HTTP/2 connection error: ' . $detail . '.', previous: $previous);
    }

    /**
     * Create a connection error when HPACK cannot decode a field block (RFC 9113 §4.3).
     */
    public static function forCompressionError(Throwable $previous): self
    {
        return new self('HTTP/2 field block decompression failed.', ErrorCode::CompressionError, $previous);
    }

    /**
     * Create a protocol exception for an incorrect frame payload size.
     *
     * @param int $expected The expected payload size in bytes.
     * @param int $actual The actual payload size received.
     */
    public static function forFrameSizeError(int $expected, int $actual): self
    {
        return new self(
            'HTTP/2 frame size error: expected ' . $expected . ' bytes, got ' . $actual . '.',
            ErrorCode::FrameSizeError,
        );
    }

    /**
     * Create a protocol exception for an invalid stream identifier.
     *
     * @param int $streamId The invalid stream ID.
     * @param string $context Description of why the stream ID is invalid.
     */
    public static function forInvalidStreamId(int $streamId, string $context): self
    {
        return new self('HTTP/2 invalid stream ID ' . $streamId . ': ' . $context . '.');
    }

    /**
     * Create a protocol exception for a SETTINGS parameter value outside the allowed range.
     *
     * @param string $setting The name of the setting.
     * @param int $value The out-of-range value received.
     */
    public static function forSettingsValueOutOfRange(string $setting, int $value): self
    {
        return new self('HTTP/2 SETTINGS value out of range: ' . $setting . ' = ' . $value . '.');
    }

    /**
     * Create a protocol exception for a header block that was interrupted by a non-CONTINUATION frame.
     *
     * @param int $expectedStream The stream ID that was expecting a CONTINUATION frame.
     * @param int $receivedType The frame type that was received instead.
     */
    public static function forHeaderBlockInterrupted(int $expectedStream, int $receivedType): self
    {
        return new self(
            'HTTP/2 header block interrupted: expected CONTINUATION for stream '
            . $expectedStream
            . ', got frame type '
            . $receivedType
            . '.',
        );
    }
}
