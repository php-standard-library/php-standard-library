<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal\H1;

use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Message\FieldMap;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\HTTP\Message\Response;
use Psl\IO;

use function ctype_digit;
use function str_contains;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Parses HTTP/1.x response messages from a buffered reader.
 *
 * Reads and validates the status line, header fields, and determines the
 * appropriate body framing strategy per RFC 9112:
 * - Chunked transfer encoding (Section 7.1): {@see ChunkedBodyHandle}
 * - Content-Length (Section 6.2): {@see IO\FixedLengthReadHandle}
 * - Read-until-close (Section 7.2): {@see UntilCloseBodyHandle}
 *
 * Also determines whether the connection supports keep-alive based on the
 * Connection header and protocol version (RFC 9112 Section 9.3).
 *
 * @internal
 *
 * @see Transport Calls this reader after sending the request.
 * @see ChunkedBodyHandle Body handle for chunked responses.
 * @see IO\FixedLengthReadHandle Body handle for Content-Length responses.
 * @see UntilCloseBodyHandle Body handle for connection-close responses.
 */
final class ResponseReader
{
    private function __construct() {}

    /**
     * Read a complete response (status-line + headers) and build the body handle.
     *
     * Parses the status line for the HTTP version and status code, then reads
     * header fields line by line. Body framing is determined by
     * Transfer-Encoding, Content-Length, or falls back to read-until-close.
     *
     * @param IO\Reader $reader Buffered reader wrapping the connection stream.
     * @param positive-int $maxHeaderSize Maximum allowed total header size in bytes.
     * @param CancellationTokenInterface $cancellation Token to cancel read operations.
     * @param bool $isHead Whether this is a HEAD request (no body expected).
     * @param null|string $preReadStatusLine A status line already read (e.g., from Expect: 100-continue handling).
     * @param int $maxResponseBodySize Maximum allowed body size (0 = unlimited).
     *
     * @return array{Response, bool} The response and whether keep-alive is indicated.
     *
     * @throws ProtocolException If the response is malformed (invalid status line, headers, or version).
     * @throws IO\Exception\RuntimeException If an I/O error occurs during reading.
     * @throws CancelledException If a read operation is cancelled.
     */
    public static function read(
        IO\Reader $reader,
        int $maxHeaderSize,
        CancellationTokenInterface $cancellation,
        bool $isHead,
        null|string $preReadStatusLine = null,
        int $maxResponseBodySize = 0,
    ): array {
        if ($preReadStatusLine !== null) {
            $statusLine = $preReadStatusLine;
        } else {
            try {
                $statusLine = $reader->readUntilBounded("\r\n", $maxHeaderSize, $cancellation);
            } catch (IO\Exception\OverflowException) {
                throw ProtocolException::forMalformedResponse('Status line exceeds maximum size.');
            }

            if ($statusLine === null) {
                throw ProtocolException::forUnexpectedEndOfStream();
            }
        }

        $sp1 = strpos($statusLine, ' ');
        if ($sp1 === false) {
            throw ProtocolException::forMalformedResponse('Invalid status line.');
        }

        $versionString = substr($statusLine, 0, $sp1);
        $version = match ($versionString) {
            'HTTP/1.1' => ProtocolVersion::V11,
            'HTTP/1.0' => ProtocolVersion::V10,
            default => throw ProtocolException::forMalformedResponse('Unsupported HTTP version: ' . $versionString),
        };

        $remaining = substr($statusLine, $sp1 + 1);
        $sp2 = strpos($remaining, ' ');
        $statusCode = $sp2 !== false ? substr($remaining, 0, $sp2) : $remaining;

        if (!ctype_digit($statusCode) || strlen($statusCode) !== 3) {
            throw ProtocolException::forMalformedResponse('Invalid status code.');
        }

        /** @var int<100, 999> $status */
        $status = (int) $statusCode;

        /** @var list<list{non-empty-string, string}> $headers */
        $headers = [];
        $headerBytes = 0;
        $contentLength = null;
        $transferEncoding = null;
        $connectionHeader = null;

        while (true) {
            $maxRemaining = $maxHeaderSize - $headerBytes;
            if ($maxRemaining <= 0) {
                throw ProtocolException::forMalformedResponse('Response headers exceed maximum size.');
            }

            try {
                $line = $reader->readUntilBounded("\r\n", $maxRemaining, $cancellation);
            } catch (IO\Exception\OverflowException) {
                throw ProtocolException::forMalformedResponse('Response headers exceed maximum size.');
            }

            if ($line === null || $line === '') {
                break;
            }

            $headerBytes += strlen($line) + 2;

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                throw ProtocolException::forMalformedResponse('Invalid header line.');
            }

            $name = substr($line, 0, $colonPos);
            $value = trim(substr($line, $colonPos + 1), " \t");

            if ($name === '') {
                throw ProtocolException::forMalformedResponse('Empty header name.');
            }

            $headers[] = [$name, $value];

            $nameLen = $colonPos;
            if ($nameLen === 14 && strtolower($name) === 'content-length') {
                // RFC 9110 §8.6: duplicate content-length fields that disagree make the
                // message framing invalid. Identical duplicates collapse to one value.
                if ($contentLength !== null && $contentLength !== $value) {
                    throw ProtocolException::forMalformedResponse('Conflicting content-length header fields.');
                }

                $contentLength = $value;
            } elseif ($nameLen === 17 && strtolower($name) === 'transfer-encoding') {
                $transferEncoding = $transferEncoding === null ? $value : $transferEncoding . ', ' . $value;
            } elseif ($nameLen === 10 && $connectionHeader === null && strtolower($name) === 'connection') {
                $connectionHeader = $value;
            }
        }

        [$body, $trailers] = self::buildBody(
            $reader,
            $contentLength,
            $transferEncoding,
            $status,
            $isHead,
            $maxResponseBodySize,
        );

        $keepAlive = self::isKeepAlive($connectionHeader, $version);

        $response = new Response(
            status: $status,
            protocolVersion: $version,
            headers: FieldMap::from($headers),
            body: $body,
            trailers: $trailers,
        );

        return [$response, $keepAlive];
    }

    /**
     * Read all 1xx informational responses followed by the final (2xx+) response.
     *
     * Loops calling {@see read()} until a response with status >= 200 is received.
     * All intermediate 1xx responses are collected and returned alongside the
     * final response (RFC 9110 Section 15.2).
     *
     * If {@see ClientConfiguration::$onInformationalResponse} is set, it is
     * invoked for each 1xx response as it arrives, in addition to collecting
     * them in the returned list.
     *
     * @param IO\Reader $reader Buffered reader wrapping the connection stream.
     * @param ClientConfiguration $configuration Client configuration with header size limits and informational callback.
     * @param CancellationTokenInterface $cancellation Token to cancel read operations.
     * @param bool $isHead Whether this is a HEAD request (no body expected).
     * @param null|string $preReadStatusLine A status line already read (e.g., from Expect handling).
     *
     * @return array{list<Response>, Response, bool} Informational responses, final response, and keep-alive.
     *
     * @throws ProtocolException If the response is malformed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs during reading.
     * @throws CancelledException If a read operation is cancelled.
     */
    public static function readWithInformational(
        IO\Reader $reader,
        ClientConfiguration $configuration,
        CancellationTokenInterface $cancellation,
        bool $isHead,
        null|string $preReadStatusLine = null,
    ): array {
        /** @var list<Response> $informational */
        $informational = [];

        $first = true;
        while (true) {
            $statusLine = $first && $preReadStatusLine !== null ? $preReadStatusLine : null;
            $first = false;

            [$response, $keepAlive] = self::read(
                $reader,
                $configuration->maxResponseHeaderSize,
                $cancellation,
                $isHead,
                $statusLine,
                $configuration->maxResponseBodySize,
            );

            if ($response->status >= 200) {
                return [$informational, $response, $keepAlive];
            }

            if ($configuration->onInformationalResponse !== null) {
                ($configuration->onInformationalResponse)($response);
            }

            $informational[] = $response;
        }
    }

    /**
     * Build the appropriate response body handle based on the framing headers.
     *
     * Selects the body strategy per RFC 9112:
     * - No body for HEAD, 204, 304, and 1xx responses.
     * - {@see ChunkedBodyHandle} for Transfer-Encoding: chunked (Section 7.1).
     *   Trailers are parsed lazily when the body is fully consumed.
     * - {@see IO\FixedLengthReadHandle} for Content-Length (Section 6.2).
     * - {@see UntilCloseBodyHandle} as fallback (Section 7.2).
     *
     * When a max response body size is configured, the handle is wrapped in
     * {@see IO\BoundedReadHandle} for size enforcement.
     *
     * @param IO\Reader $reader Buffered reader wrapping the connection stream.
     * @param null|string $contentLength Content-Length header value, or null.
     * @param null|string $transferEncoding Combined Transfer-Encoding field value, or null.
     * @param int $status The HTTP status code.
     * @param bool $isHead Whether this is a HEAD request.
     * @param int $maxResponseBodySize Maximum allowed body size (0 = unlimited).
     *
     * @return array{null|IO\ReadHandleInterface, null|Async\Awaitable<FieldMap>} Body handle and trailers awaitable.
     *
     * @throws ProtocolException If the transfer encoding is invalid or unsupported.
     */
    private static function buildBody(
        IO\Reader $reader,
        null|string $contentLength,
        null|string $transferEncoding,
        int $status,
        bool $isHead,
        int $maxResponseBodySize = 0,
    ): array {
        if ($isHead || $status === 204 || $status === 304 || $status >= 100 && $status < 200) {
            return [null, null];
        }

        $trailers = null;

        if ($transferEncoding !== null) {
            // Only chunked is supported; ignore empty list elements (RFC 9110 Section 5.6.1).
            if (strtolower(trim($transferEncoding, " \t,")) !== 'chunked') {
                throw ProtocolException::forMalformedResponse('Invalid or unsupported transfer-encoding.');
            }

            /** @var Async\Deferred<FieldMap> $deferred */
            $deferred = new Async\Deferred();
            $handle = new ChunkedBodyHandle($reader, $deferred);
            $trailers = $deferred->getAwaitable();
        } elseif ($contentLength !== null && $contentLength !== '' && ctype_digit($contentLength)) {
            $length = (int) $contentLength;
            if ($length === 0) {
                return [null, null];
            }

            /** @var non-negative-int $length */
            $handle = new IO\FixedLengthReadHandle($reader, $length);
        } else {
            $handle = new UntilCloseBodyHandle($reader);
        }

        if ($maxResponseBodySize > 0) {
            return [new IO\BoundedReadHandle($handle, $maxResponseBodySize), $trailers];
        }

        return [$handle, $trailers];
    }

    /**
     * Determine whether the connection supports keep-alive.
     *
     * Per RFC 9112 Section 9.3: HTTP/1.1 defaults to keep-alive unless
     * "Connection: close" is present. HTTP/1.0 defaults to close unless
     * "Connection: keep-alive" is present.
     *
     * @param null|string $connectionHeader The Connection header value, or null.
     * @param ProtocolVersion $version The HTTP protocol version.
     *
     * @return bool True if the connection can be reused.
     */
    private static function isKeepAlive(null|string $connectionHeader, ProtocolVersion $version): bool
    {
        if ($version === ProtocolVersion::V11) {
            return $connectionHeader === null || !str_contains(strtolower($connectionHeader), 'close');
        }

        return $connectionHeader !== null && str_contains(strtolower($connectionHeader), 'keep-alive');
    }
}
