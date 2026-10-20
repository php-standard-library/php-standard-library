<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal\H1;

use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\DateTime\Duration;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Exception;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Message;
use Psl\HTTP\Message\Request;
use Psl\HTTP\Message\Transaction;
use Psl\IO;
use Psl\URL\URL;

use function strtolower;

/**
 * Stateless HTTP/1.x transport that writes a request and reads the response.
 *
 * Handles the Expect: 100-continue flow (RFC 9110 Section 10.1.1), chunked
 * transfer encoding with trailers (RFC 9112 Section 7.1), and content-length
 * framed bodies. The response is parsed by {@see ResponseReader}.
 *
 * @internal
 *
 * @see H1Connection Owns the stream and delegates here for the wire exchange.
 * @see RequestWriter Serializes the request to the wire.
 * @see ResponseReader Parses the response from the wire.
 */
final class Transport
{
    private function __construct() {}

    /**
     * Send a request over an H1 connection and return the transaction.
     *
     * Handles three request-sending paths:
     * 1. Expect: 100-continue - sends headers first, waits for 100 Continue,
     *    then sends the body (RFC 9110 Section 10.1.1).
     * 2. Normal with body - sends headers + body in one pass.
     * 3. No body - sends headers only.
     *
     * @param H1Connection $connection The connection to exchange on.
     * @param Request $request The request to send.
     * @param URL $url The resolved request URL.
     * @param ClientConfiguration $configuration Client configuration governing transport behavior.
     * @param CancellationTokenInterface $cancellation Token to cancel the exchange.
     *
     * @return array{Transaction, bool} The transaction and whether the connection can be reused (keep-alive).
     *
     * @throws Exception\RequestException If the request is structurally invalid.
     * @throws ProtocolException If the response is malformed or exceeds header size limits.
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException If the cancellation token fires.
     */
    public static function exchange(
        H1Connection $connection,
        Request $request,
        URL $url,
        ClientConfiguration $configuration,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): array {
        if ($connection->isForwardProxy) {
            $absoluteTarget = $url->toString();
            $request = $request->withRequestTarget($absoluteTarget);

            if ($connection->proxyAuthorization !== null && !$request->headers->has('proxy-authorization')) {
                $request = $request->withHeaders($request->headers->with(
                    'proxy-authorization',
                    $connection->proxyAuthorization,
                ));
            }
        }

        $hasExpect = false;
        $expect = $request->headers->get('expect');
        if ($expect !== null && strtolower($expect) === '100-continue') {
            $hasExpect = true;
        }

        try {
            $resolvedTrailers = null;
            $hasTrailers = $request->trailers !== null;

            if ($hasTrailers && $request->body === null) {
                throw Exception\RequestException::forInvalidRequest(
                    'Trailers cannot be sent without a message body (RFC 9110 Section 6.5).',
                );
            }

            if ($hasTrailers) {
                $resolvedTrailers = $request->trailers->await();
                if ($resolvedTrailers->isEmpty()) {
                    $resolvedTrailers = null;
                }
            }

            $isHead = $request->method === Message\METHOD_HEAD;
            if ($hasExpect && $request->body !== null) {
                RequestWriter::write(
                    $connection->stream,
                    $request,
                    $url,
                    $resolvedTrailers,
                    $cancellation,
                    headersOnly: true,
                );

                /** @var bool $bodySent */
                $bodySent = false;
                $stop = new Async\SignalCancellationToken();
                $exchangeCancellation = new Async\LinkedCancellationToken($cancellation, $stop);
                $sendBody = static function () use (
                    $connection,
                    $request,
                    $resolvedTrailers,
                    $exchangeCancellation,
                    &$bodySent,
                ): void {
                    if ($bodySent) {
                        return;
                    }

                    $bodySent = true;
                    RequestWriter::writeBody($connection->stream, $request, $resolvedTrailers, $exchangeCancellation);
                };

                // Keep one parser alive across the timeout, including partial status lines and fields.
                $pending = Async\run(static fn() => ResponseReader::readWithInformational(
                    $connection->reader,
                    $configuration,
                    $exchangeCancellation,
                    $isHead,
                    onContinue: $sendBody,
                ));

                try {
                    try {
                        [$informationalResponses, $response, $keepAlive] = $pending->await(
                            new Async\LinkedCancellationToken(
                                $cancellation,
                                new Async\TimeoutCancellationToken(Duration::seconds(1)),
                            ),
                        );
                    } catch (CancelledException) {
                        $cancellation->throwIfCancelled();
                        $sendBody();
                        [$informationalResponses, $response, $keepAlive] = $pending->await($cancellation);
                    }
                } finally {
                    $stop->cancel();
                    $pending->ignore();
                }

                $keepAlive = $keepAlive && $bodySent;
            } else {
                RequestWriter::write($connection->stream, $request, $url, $resolvedTrailers, $cancellation);
                [$informationalResponses, $response, $keepAlive] = ResponseReader::readWithInformational(
                    $connection->reader,
                    $configuration,
                    $cancellation,
                    $isHead,
                );
            }

            $transaction = new Transaction($informationalResponses, null, $response);

            return [$transaction, $keepAlive];
        } catch (IO\Exception\OverflowException) {
            throw ProtocolException::forMalformedResponse('Response exceeds maximum header size.');
        }
    }
}
