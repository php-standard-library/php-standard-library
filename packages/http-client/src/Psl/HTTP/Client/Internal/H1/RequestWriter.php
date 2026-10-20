<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal\H1;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\HTTP\Client\Exception\RequestException;
use Psl\HTTP\Message\FieldMap;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\HTTP\Message\Request;
use Psl\IO;
use Psl\Network;
use Psl\URL\URL;

use function count;
use function ctype_digit;
use function dechex;
use function ltrim;
use function preg_match;
use function str_contains;
use function strlen;
use function strtolower;
use function trim;

use const PHP_INT_MAX;

/**
 * Serializes and writes an HTTP/1.x request message to a network stream.
 *
 * Produces the request-line, headers, and body per RFC 9112 Section 2.1.
 * When no Content-Length or Transfer-Encoding header is present and the request
 * has a body, chunked transfer encoding is applied automatically
 * (RFC 9112 Section 7.1).
 *
 * Invalid methods, fields, and body framing throw a request exception.
 *
 * @internal
 *
 * @see Transport Uses this writer to send requests during an exchange.
 */
final class RequestWriter
{
    private function __construct() {}

    /**
     * Write a complete HTTP/1.x request (request-line, headers, body) to the stream.
     *
     * Body framing is determined by the presence of Content-Length or
     * Transfer-Encoding headers. If neither is present and a body exists,
     * chunked transfer encoding is used with an optional trailer section.
     *
     * @param Network\StreamInterface $stream The stream to write to.
     * @param Request $request The request to serialize.
     * @param URL $url The resolved URL (used for the Host header if not already present).
     * @param null|FieldMap $trailers Trailer fields to append after a chunked body, or null.
     * @param CancellationTokenInterface $cancellation Token to cancel the write.
     *
     * @throws IO\Exception\RuntimeException If writing to the stream fails or the request target contains invalid characters.
     * @throws CancelledException If the write timeout is exceeded.
     */
    public static function write(
        Network\StreamInterface $stream,
        Request $request,
        URL $url,
        null|FieldMap $trailers = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
        bool $headersOnly = false,
    ): void {
        $target = $request->requestTarget;
        if (str_contains($target, "\r") || str_contains($target, "\n") || str_contains($target, "\0")) {
            throw new IO\Exception\RuntimeException('Request target contains invalid characters');
        }

        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $request->method) !== 1) {
            throw RequestException::forInvalidRequest('Method must be a token.');
        }

        foreach ($trailers ?? FieldMap::default() as [$name, $value]) {
            self::validateField($name, $value);
        }

        $protocol = $request->protocolVersion === ProtocolVersion::V10 ? '1.0' : '1.1';
        $head = $request->method . ' ' . $target . ' HTTP/' . $protocol . "\r\n";

        if (!$request->headers->has('host')) {
            $host = $url->authority->host->toString();
            if ($url->authority->port !== null) {
                $host .= ':' . $url->authority->port;
            }

            $head .= 'host: ' . $host . "\r\n";
        }

        foreach ($request->headers as [$name, $value]) {
            self::validateField($name, $value);
            $head .= $name . ': ' . $value . "\r\n";
        }

        $hasContentLength = $request->headers->has('content-length');
        $hasTransferEncoding = $request->headers->has('transfer-encoding');
        $contentLength = self::contentLength($request);
        if (
            $hasTransferEncoding
            && (
                $hasContentLength
                || strtolower(trim($request->headers->get('transfer-encoding') ?? '', " \t")) !== 'chunked'
                || count($request->headers->getAll('transfer-encoding')) !== 1
            )
        ) {
            throw RequestException::forInvalidRequest(
                'Transfer-Encoding must be chunked and cannot accompany Content-Length.',
            );
        }

        $body = $request->body;
        if ($body === null && ($contentLength ?? 0) !== 0) {
            throw RequestException::forInvalidRequest('Content-Length does not match the empty body.');
        }

        if ($body !== null && !$hasContentLength && !$hasTransferEncoding) {
            $head .= "transfer-encoding: chunked\r\n";
        } elseif ($body === null && !$hasContentLength && !$hasTransferEncoding) {
            $head .= "content-length: 0\r\n";
        }

        $head .= "\r\n";

        $stream->writeAll($head, $cancellation);

        if (!$headersOnly) {
            self::writeBody($stream, $request, $trailers, $cancellation);
        }
    }

    /**
     * Write the body after validated headers, including the Expect path.
     */
    public static function writeBody(
        Network\StreamInterface $stream,
        Request $request,
        null|FieldMap $trailers,
        CancellationTokenInterface $cancellation,
    ): void {
        $body = $request->body;
        $contentLength = self::contentLength($request);
        if ($contentLength !== null) {
            if ($body !== null) {
                self::writeRawBody($stream, $body, $contentLength, $cancellation);
            }
        } elseif ($body !== null) {
            self::writeChunkedBody($stream, $body, $trailers, $cancellation);
        } elseif ($request->headers->has('transfer-encoding')) {
            $stream->writeAll("0\r\n\r\n", $cancellation);
        }
    }

    private static function validateField(string $name, string $value): void
    {
        if (
            preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1
            || str_contains($value, "\r")
            || str_contains($value, "\n")
            || str_contains($value, "\0")
        ) {
            throw RequestException::forInvalidRequest('Invalid header or trailer field.');
        }
    }

    private static function contentLength(Request $request): null|int
    {
        $value = $request->headers->get('content-length');
        if ($value === null) {
            return null;
        }

        $value = trim($value, " \t");
        $digits = ltrim($value, '0');
        $maximum = (string) PHP_INT_MAX;
        if (
            !ctype_digit($value)
            || strlen($digits) > strlen($maximum)
            || strlen($digits) === strlen($maximum) && $digits > $maximum
            || count($request->headers->getAll('content-length')) !== 1
        ) {
            throw RequestException::forInvalidRequest('Invalid Content-Length.');
        }

        return (int) $value;
    }

    /**
     * Write the request body using chunked transfer encoding (RFC 9112 Section 7.1).
     *
     * Each chunk is prefixed with its size in hexadecimal. The body is terminated
     * with a zero-length chunk, optionally followed by trailer fields.
     *
     * @param Network\StreamInterface $stream The stream to write to.
     * @param IO\ReadHandleInterface $body The request body to stream.
     * @param null|FieldMap $trailers Trailer fields, or null.
     * @param CancellationTokenInterface $cancellation Token to cancel the write.
     *
     * @throws IO\Exception\RuntimeException If writing fails.
     * @throws CancelledException If the write timeout is exceeded.
     */
    private static function writeChunkedBody(
        Network\StreamInterface $stream,
        IO\ReadHandleInterface $body,
        null|FieldMap $trailers,
        CancellationTokenInterface $cancellation,
    ): void {
        while (!$body->reachedEndOfDataSource()) {
            $chunk = $body->read(8192, $cancellation);
            if ($chunk === '') {
                break;
            }

            $stream->writeAll(dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n", $cancellation);
        }

        $terminator = "0\r\n";
        if ($trailers !== null && !$trailers->isEmpty()) {
            foreach ($trailers as [$name, $value]) {
                $terminator .= $name . ': ' . $value . "\r\n";
            }
        }

        $terminator .= "\r\n";
        $stream->writeAll($terminator, $cancellation);
    }

    /**
     * Write the request body as raw bytes without any transfer encoding.
     *
     * Check that the body matches the declared Content-Length.
     *
     * @param Network\StreamInterface $stream The stream to write to.
     * @param IO\ReadHandleInterface $body The request body to stream.
     * @param CancellationTokenInterface $cancellation Token to cancel the write.
     *
     * @throws IO\Exception\RuntimeException If writing fails.
     * @throws CancelledException If the write timeout is exceeded.
     */
    private static function writeRawBody(
        Network\StreamInterface $stream,
        IO\ReadHandleInterface $body,
        int $contentLength,
        CancellationTokenInterface $cancellation,
    ): void {
        $written = 0;
        while (!$body->reachedEndOfDataSource()) {
            $chunk = $body->read(8192, $cancellation);
            if ($chunk === '') {
                break;
            }

            $written += strlen($chunk);
            if ($written > $contentLength) {
                throw RequestException::forInvalidRequest('Body exceeds Content-Length.');
            }

            $stream->writeAll($chunk, $cancellation);
        }

        if ($written !== $contentLength) {
            throw RequestException::forInvalidRequest('Body is shorter than Content-Length.');
        }
    }
}
