<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal;

use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\ProxyConfiguration;
use Psl\IO;
use Psl\Network;
use Psl\TCP;
use Psl\TLS\ClientConfiguration;
use Psl\TLS\Connector;

use function preg_match;
use function str_ends_with;
use function strlen;
use function substr;

/**
 * Establishes an HTTP CONNECT tunnel through an HTTP proxy.
 *
 * Connects to the tunnel address, sends an HTTP/1.1 CONNECT request for the
 * target host:port, and reads the proxy's response. On success (2xx status),
 * the underlying TCP stream becomes a transparent byte pipe to the target,
 * ready for TLS handshake or plaintext HTTP.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9110#section-9.3.6 CONNECT Method
 *
 * @internal
 */
final class HttpTunnel
{
    private function __construct() {}

    /**
     * Open a CONNECT tunnel to the target through the given HTTP proxy.
     *
     * @param non-empty-string $targetHost The target hostname to tunnel to.
     * @param int<1, 65535> $targetPort The target port to tunnel to.
     *
     * @throws Network\Exception\RuntimeException If the TCP connection to the HTTP proxy fails.
     * @throws ProtocolException If the HTTP proxy returns a non-2xx response.
     * @throws IO\Exception\RuntimeException If an I/O error occurs during the handshake.
     * @throws Async\Exception\CancelledException If the cancellation token fires.
     */
    public static function connect(
        TCP\ConnectorInterface $tcpConnector,
        ProxyConfiguration $proxy,
        string $targetHost,
        int $targetPort,
        CancellationTokenInterface $cancellation,
        ClientConfiguration $tlsConfiguration,
    ): TCP\StreamInterface {
        $tunnelHost = $proxy->url->authority->host->toString();
        $tunnelPort = $proxy->url->authority->port ?? ($proxy->url->scheme === 'https' ? 443 : 80);
        $tunnelTls = $proxy->url->scheme === 'https';

        $stream = $tcpConnector->connect($tunnelHost, $tunnelPort, $cancellation);

        if ($tunnelTls) {
            $tlsConnector = new Connector($tlsConfiguration);
            $stream = $tlsConnector->connect($stream, $proxy->sni ?? $tunnelHost, $cancellation);
        }

        $authority = $targetHost . ':' . $targetPort;
        $connectRequest = "CONNECT {$authority} HTTP/1.1\r\nHost: {$authority}\r\n";

        if ($proxy->authorization !== null) {
            $connectRequest .= "Proxy-Authorization: {$proxy->authorization}\r\n";
        }

        $connectRequest .= "\r\n";

        $stream->writeAll($connectRequest, $cancellation);

        try {
            $statusLine = self::readLine($stream, 8192, $cancellation);
        } catch (IO\Exception\OverflowException) {
            throw ProtocolException::forMalformedResponse('CONNECT status line exceeds maximum size.');
        }

        if ($statusLine === null) {
            throw ProtocolException::forUnexpectedEndOfStream();
        }

        $matches = null;
        if (!preg_match('#^HTTP/1\.[01] (\d{3})#', $statusLine, $matches)) {
            throw ProtocolException::forMalformedResponse('Invalid CONNECT response: ' . $statusLine);
        }

        $statusCode = (int) $matches[1];
        if ($statusCode < 200 || $statusCode >= 300) {
            throw ProtocolException::forMalformedResponse('CONNECT tunnel failed with status ' . $statusCode);
        }

        $remaining = 8192;
        while (true) {
            if ($remaining <= 0) {
                throw ProtocolException::forMalformedResponse('CONNECT headers exceed maximum size.');
            }

            try {
                $line = self::readLine($stream, $remaining, $cancellation);
            } catch (IO\Exception\OverflowException) {
                throw ProtocolException::forMalformedResponse('CONNECT headers exceed maximum size.');
            }

            if ($line === null) {
                throw ProtocolException::forUnexpectedEndOfStream();
            }

            $remaining -= strlen($line) + 2;
            if ($remaining < 0) {
                throw ProtocolException::forMalformedResponse('CONNECT headers exceed maximum size.');
            }

            if ($line === '') {
                break;
            }
        }

        return $stream;
    }

    /** @param positive-int $maxBytes */
    private static function readLine(
        TCP\StreamInterface $stream,
        int $maxBytes,
        CancellationTokenInterface $cancellation,
    ): null|string {
        $line = '';
        // The caller uses the raw stream for TLS, so leave tunnel bytes unread.
        while (strlen($line) <= ($maxBytes + 1)) {
            $byte = $stream->read(1, $cancellation);
            if ($byte === '') {
                if ($stream->reachedEndOfDataSource()) {
                    return null;
                }

                continue;
            }

            $line .= $byte;
            if (str_ends_with($line, "\r\n")) {
                return substr($line, 0, -2);
            }
        }

        throw new IO\Exception\OverflowException('CONNECT line exceeds maximum size.');
    }
}
