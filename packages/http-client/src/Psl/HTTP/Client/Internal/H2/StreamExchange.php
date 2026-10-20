<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal\H2;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\H2\ClientConnectionInterface;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\ExceptionInterface;
use Psl\H2\Exception\StreamException;
use Psl\H2\StreamState;
use Psl\HPACK\Header;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Exception\RequestException;
use Psl\HTTP\Client\Exception\RuntimeException;
use Psl\HTTP\Message\FieldMap;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\HTTP\Message\Request;
use Psl\HTTP\Message\Response;
use Psl\HTTP\Message\Transaction;
use Psl\IO;
use Psl\URL;

use function explode;
use function in_array;
use function strtolower;
use function trim;

/**
 * Manages one HTTP/2 request/response exchange on a single stream.
 *
 * Orchestrates the full lifecycle of an HTTP/2 stream exchange:
 * 1. Acquires a stream slot from the {@see H2Session} (blocks if at concurrency limit).
 * 2. Creates an {@see H2Stream} and registers it with the {@see H2Multiplexer}.
 * 3. Sends HEADERS and (optionally) DATA frames, including trailers if present.
 * 4. Awaits the response HEADERS from the stream's deferred.
 * 5. Returns a {@see Transaction} with either a bodiless response (if END_STREAM
 *    was set on the response HEADERS) or a {@see ResponseBodyHandle} for lazy
 *    body consumption.
 *
 * The response body is read lazily through {@see ResponseBodyHandle}, which pulls
 * from the {@see H2Stream}'s buffer. This is fully decoupled from the
 * {@see H2Multiplexer}'s read fiber: the multiplexer pushes data into the stream
 * buffer, and the consumer pulls from it.
 *
 * Stream slot lifecycle: the stream slot is released as soon as the response
 * HEADERS arrive (not when the body is fully consumed), because HTTP/2 streams
 * can receive data independently of the concurrency limit once headers are received.
 *
 * Cancellation and stream errors unregister the stream and release its slot.
 * Connection and I/O errors also mark the session as closed.
 *
 * H1/H2 header mapping: connection-specific fields and fields named in
 * Connection are dropped from headers and trailers. Only te: trailers is
 * retained. Host maps to :authority per RFC 9113 Sections 8.2.2 and 8.3.
 *
 * @internal
 *
 * @see H2Session Provides stream slot acquisition and release.
 * @see H2Stream Per-stream state container receiving events from the multiplexer.
 * @see H2Multiplexer Dispatches events to the registered stream.
 * @see ResponseBodyHandle Lazy body handle backed by the H2Stream's buffer.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-8.3 HTTP/2 Request Pseudo-Headers
 */
final class StreamExchange
{
    private function __construct() {}

    /**
     * Perform a complete HTTP/2 request/response exchange on a new stream.
     *
     * Acquires a stream slot, sends the request (HEADERS + optional DATA + optional
     * trailer HEADERS), and awaits the response. Informational (1xx) responses are
     * collected by the {@see H2Stream} and included in the returned transaction.
     *
     * @param H2Session $session The session providing stream slots and the multiplexer.
     * @param Request $request The HTTP request to send.
     * @param URL\URL $url The resolved request URL (used for pseudo-headers).
     * @param ClientConfiguration $configuration Client configuration governing body size limits.
     * @param CancellationTokenInterface $cancellation Token to cancel the exchange.
     *
     * @return Transaction The transaction containing informational responses and the final response.
     *
     * @throws RequestException If the request is structurally invalid (e.g., trailers without body).
     * @throws RuntimeException If the H2 connection is closed or a protocol error occurs.
     * @throws CancelledException If the cancellation token fires.
     * @throws ExceptionInterface If an H2 framing error occurs.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     */
    public static function exchange(
        H2Session $session,
        Request $request,
        URL\URL $url,
        ClientConfiguration $configuration,
        CancellationTokenInterface $cancellation,
    ): Transaction {
        if ($request->body === null && $request->trailers !== null) {
            throw RequestException::forInvalidRequest(
                'Trailers cannot be sent without a message body (RFC 9110 Section 6.5).',
            );
        }

        $session->acquireStream();

        if ($session->isClosed()) {
            $session->releaseStream();
            throw new RuntimeException('HTTP/2 connection is closed.');
        }

        $connection = $session->connection;
        $streamId = $connection->nextStreamId();
        $multiplexer = $session->multiplexer;

        $path = $request->requestTarget;
        $authority = $url->authority->host->toString();
        if ($url->authority->port !== null) {
            $authority .= ':' . $url->authority->port;
        }

        /** @var list<Header> $headers */
        $headers = [
            new Header(':method', $request->method),
            new Header(':path', $path),
            new Header(':scheme', $url->scheme),
            new Header(':authority', $authority),
            ...self::filterHeaders($request->headers),
        ];

        $stream = new H2Stream($streamId, $configuration->onInformationalResponse);
        $headersSent = false;

        try {
            $multiplexer->register($stream);
            $connection->sendHeaders($streamId, $headers, $request->body === null);
            $headersSent = true;

            if ($request->body !== null) {
                self::sendBody($connection, $streamId, $request->body, $request->trailers === null);

                if ($request->trailers !== null) {
                    $trailerFields = $request->trailers->await();
                    $trailerHeaders = self::filterHeaders($trailerFields, $request->headers);

                    $connection->sendHeaders($streamId, $trailerHeaders, true);
                }
            }

            [$status, $responseHeaders, $endStream] = $stream->awaitResponse($cancellation);
            $informational = $stream->getInformationalResponses();

            if ($endStream) {
                $multiplexer->unregister($streamId);
                $session->releaseStream();

                return new Transaction(
                    $informational,
                    null,
                    new Response(status: $status, protocolVersion: ProtocolVersion::V20, headers: $responseHeaders),
                );
            }

            $session->releaseStream();

            $bodyHandle = new ResponseBodyHandle($stream, $multiplexer, $streamId, $configuration->maxResponseBodySize);

            return new Transaction(
                $informational,
                null,
                new Response(
                    status: $status,
                    protocolVersion: ProtocolVersion::V20,
                    headers: $responseHeaders,
                    body: $bodyHandle,
                ),
            );
        } catch (CancelledException|StreamException $e) {
            $multiplexer->unregister($streamId);
            $session->releaseStream();
            if (
                $e instanceof StreamException
                && $headersSent
                && in_array(
                    $connection->getStreamState($streamId),
                    [StreamState::Open, StreamState::HalfClosedRemote],
                    true,
                )
            ) {
                try {
                    $connection->resetStream($streamId, ErrorCode::Cancel);
                } catch (ExceptionInterface|IO\Exception\RuntimeException) {
                    $session->markClosed();
                }
            }

            throw $e;
        } catch (ExceptionInterface|RuntimeException|IO\Exception\RuntimeException $e) {
            $multiplexer->unregister($streamId);
            $session->releaseStream();
            if ($headersSent || !$e instanceof ExceptionInterface) {
                $session->markClosed();
            }

            throw $e;
        }
    }

    /**
     * @return list<Header>
     */
    private static function filterHeaders(FieldMap $fields, null|FieldMap $requestHeaders = null): array
    {
        $connectionFields = [
            'host' => true,
            'connection' => true,
            'keep-alive' => true,
            'proxy-connection' => true,
            'transfer-encoding' => true,
            'upgrade' => true,
        ];
        $connectionValues = [...$fields->getAll('connection'), ...($requestHeaders?->getAll('connection') ?? [])];
        foreach ($connectionValues as $value) {
            foreach (explode(',', $value) as $name) {
                $connectionFields[strtolower(trim($name))] = true;
            }
        }

        $headers = [];
        foreach ($fields as [$name, $value]) {
            $lower = strtolower($name);
            if (isset($connectionFields[$lower]) || $lower === 'te' && strtolower($value) !== 'trailers') {
                continue;
            }

            $headers[] = new Header($lower, $value);
        }

        return $headers;
    }

    /**
     * Stream the request body as HTTP/2 DATA frames.
     *
     * Reads from the body handle in 16,384-byte chunks (the default maximum
     * frame payload per RFC 9113 Section 4.2) and sends each as a DATA frame.
     * The END_STREAM flag is set on the final DATA frame if no trailers follow.
     *
     * If the body is empty (reaches EOF immediately), an empty DATA frame with
     * END_STREAM is sent to signal the end of the request.
     *
     * @param ClientConnectionInterface $connection The H2 connection to send DATA frames on.
     * @param positive-int $streamId The stream ID to send data on.
     * @param IO\ReadHandleInterface $body The request body to read from.
     * @param bool $endStreamOnFinish Whether to set END_STREAM on the last DATA frame. False when trailers will follow.
     */
    private static function sendBody(
        ClientConnectionInterface $connection,
        int $streamId,
        IO\ReadHandleInterface $body,
        bool $endStreamOnFinish = true,
    ): void {
        $sentEndStream = false;

        while (!$body->reachedEndOfDataSource()) {
            $chunk = $body->read(16_384);
            if ($chunk === '') {
                break;
            }

            $isLast = $body->reachedEndOfDataSource();
            $endStream = $endStreamOnFinish && $isLast;
            $connection->sendAllData($streamId, $chunk, $endStream);
            if ($endStream) {
                $sentEndStream = true;
            }
        }

        if ($endStreamOnFinish && !$sentEndStream) {
            $connection->sendData($streamId, '', endStream: true);
        }
    }
}
