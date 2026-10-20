<?php

declare(strict_types=1);

namespace Psl\DNS;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\DNS\Internal\Decoder;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\RecordType;
use Psl\IO;
use Psl\Network;
use Psl\UDP;
use UnexpectedValueException;

use function gethostbyname;
use function inet_pton;
use function str_contains;
use function strlen;
use function trim;
use function unpack;

/**
 * DNS resolver that sends queries over UDP.
 *
 * Throws {@see Exception\ProtocolException} when the response has the
 * TC flag set, allowing a {@see FallbackResolver} to fall through to a
 * TCP-based resolver.
 *
 * @api
 */
final readonly class UDPResolver implements ResolverInterface
{
    use ResolverConvenienceMethodsTrait;

    /**
     * @param non-empty-string $host The nameserver hostname or IP address.
     * @param int<0, 65535> $port The nameserver port.
     * @param bool $dnssec Whether to set the DNSSEC OK (DO) flag in queries.
     * @param int $udpPayloadSize The maximum UDP payload size advertised via EDNS0.
     */
    public function __construct(
        private string $host,
        private int $port = 53,
        private bool $dnssec = false,
        private int $udpPayloadSize = 1232,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function query(
        string $name,
        RecordType $type,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
        array $ednsOptions = [],
    ): Response {
        [$id, $packet] = Encoder::encode($name, $type, $this->dnssec, $this->udpPayloadSize, $ednsOptions);

        try {
            $cancellation->throwIfCancelled();
            $host = trim($this->host, '[]');
            if (!str_contains($host, ':')) {
                $host = gethostbyname($host);
            }

            if ($host === '' || inet_pton($host) === false) {
                throw Exception\NetworkException::forQueryFailed('UDP', 'Could not resolve nameserver ' . $this->host);
            }

            $ipv6 = str_contains($host, ':');
            $socket = UDP\Socket::bind($ipv6 ? '[::]' : '0.0.0.0');
            try {
                $address = Network\Address::udp($ipv6 ? '[' . $host . ']' : $host, $this->port);
                $socket->sendTo($packet, $address, $cancellation);
                /** @var positive-int $bufferSize */
                $bufferSize = $this->udpPayloadSize;
                [$responseData, $peer] = $socket->receiveFrom($bufferSize, $cancellation);
                $sameHost = $ipv6 ? inet_pton($peer->host) === inet_pton($host) : $peer->host === $host;
                if (!$sameHost || $peer->port !== $this->port) {
                    throw Exception\ProtocolException::forUnexpectedSource(
                        $this->host . ':' . $this->port,
                        $peer->host . ':' . ($peer->port ?? 0),
                    );
                }

                if (strlen($responseData) < 12) {
                    throw Exception\ProtocolException::forTooShort(strlen($responseData));
                }

                $responseId = unpack('n', $responseData)[1];
                if ($responseId !== $id) {
                    throw Exception\ProtocolException::forTransactionIDMismatch($id, $responseId);
                }

                if (!Decoder::matchesQuestion($responseData, $packet, allowEmptyError: true)) {
                    $exception = new UnexpectedValueException(
                        'Response question does not match the query name, type, and class',
                    );
                    throw Exception\ProtocolException::forDecodingFailure($exception->getMessage(), $exception);
                }

                $response = Decoder::decode($responseData);
                if (Decoder::isTruncated($responseData)) {
                    throw Exception\ProtocolException::forUDPResponse();
                }

                return $response;
            } finally {
                $socket->close();
            }
        } catch (Network\Exception\InvalidArgumentException $e) {
            throw Exception\NetworkException::forInvalidServerAddress($e->getMessage(), $e);
        } catch (IO\Exception\AlreadyClosedException $e) {
            throw Exception\NetworkException::forSocketClosed($e);
        } catch (Network\Exception\RuntimeException $e) {
            throw Exception\NetworkException::forQueryFailed('UDP', $e->getMessage(), $e);
        }
    }
}
