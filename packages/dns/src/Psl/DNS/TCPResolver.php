<?php

declare(strict_types=1);

namespace Psl\DNS;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\DNS\Internal\Decoder;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\RecordType;
use Psl\IO;
use Psl\Network;
use Psl\TCP;

/**
 * DNS resolver that sends queries over pooled TCP connections.
 *
 * Connections are pooled internally and reused across queries.
 * On failure the connection is discarded from the pool.
 *
 * For DNS-over-TLS (DoT, RFC 7858), pass a TLS-enabled connector
 * such as {@see \\Psl\\TLS\\TCPConnector}.
 *
 * @api
 */
final readonly class TCPResolver implements ResolverInterface
{
    use ResolverConvenienceMethodsTrait;

    /**
     * The connection pool for reusing TCP connections across queries.
     */
    private TCP\SocketPoolInterface $pool;

    /**
     * @param non-empty-string $host The nameserver hostname or IP address.
     * @param int<0, 65535> $port The nameserver port.
     * @param bool $dnssec Whether to set the DNSSEC OK (DO) flag in queries.
     * @param TCP\ConnectorInterface $connector The connector used to establish TCP connections.
     */
    public function __construct(
        private string $host,
        private int $port = 53,
        private bool $dnssec = false,
        TCP\ConnectorInterface $connector = new TCP\Connector(),
    ) {
        $this->pool = new TCP\SocketPool($connector);
    }

    /**
     * Close all pooled TCP connections when this resolver is destroyed.
     */
    public function __destruct()
    {
        $this->pool->close();
    }

    /**
     * {@inheritDoc}
     */
    public function query(
        string $name,
        RecordType $type,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
        array $ednsOptions = [],
    ): Response {
        [$id, $packet] = Encoder::encode($name, $type, $this->dnssec, ednsOptions: $ednsOptions);

        try {
            $stream = $this->pool->checkout($this->host, $this->port, $cancellation);

            try {
                $responseData = Internal\tcp_exchange($stream, $packet, $cancellation);
            } catch (CancelledException|Network\Exception\RuntimeException|IO\Exception\RuntimeException $e) {
                $this->pool->clear($stream);

                throw $e;
            }

            try {
                $response = Decoder::decode($responseData);
                if ($response->id !== $id) {
                    throw Exception\ProtocolException::forTransactionIDMismatch($id, $response->id);
                }

                if (!Decoder::matchesQuestion($responseData, $packet)) {
                    throw Exception\ProtocolException::forQuestionMismatch();
                }
            } catch (Exception\ProtocolException $e) {
                $this->pool->clear($stream);

                throw $e;
            }

            $this->pool->checkin($stream);
        } catch (Network\Exception\RuntimeException|IO\Exception\RuntimeException $e) {
            throw Exception\NetworkException::forQueryFailed('TCP', $e->getMessage(), $e);
        }

        return $response;
    }
}
