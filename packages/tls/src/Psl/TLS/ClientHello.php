<?php

declare(strict_types=1);

namespace Psl\TLS;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Network;
use Psl\TLS\Exception\HandshakeFailedException;

/**
 * Represents a parsed TLS ClientHello, allowing SNI/ALPN inspection before completing the handshake.
 *
 * Obtained from {@see LazyAcceptor::accept()}, this class lets you inspect the client's
 * SNI hostname and ALPN protocols before choosing a {@see ServerConfiguration} for the handshake.
 *
 * @api
 */
final readonly class ClientHello
{
    /**
     * @param ?non-empty-string $serverName
     * @param ?list<non-empty-string> $alpnProtocols
     */
    public function __construct(
        private Network\StreamInterface $stream,
        private null|string $serverName,
        private null|array $alpnProtocols,
    ) {}

    /**
     * The SNI hostname the client requested, or null if not present.
     *
     * @return ?non-empty-string
     */
    public function getServerName(): null|string
    {
        return $this->serverName;
    }

    /**
     * The ALPN protocols the client advertised, or null if not present.
     *
     * @return ?list<non-empty-string>
     */
    public function getAlpnProtocols(): null|array
    {
        return $this->alpnProtocols;
    }

    /**
     * Complete the TLS handshake with the given configuration.
     *
     * Streams accepted by `TCP\listen()` own a private stream context when ext-sockets is
     * available. Without it, all streams accepted from one listener share the listener's
     * context, and a stream whose context already carries client certificate trust settings
     * (`cafile`, `capath`) that the given configuration does not set is refused rather than
     * upgraded with another configuration's trust settings.
     *
     * @throws HandshakeFailedException If the TLS handshake fails.
     * @throws Network\Exception\RuntimeException If the stream is not available, or if its context
     *                                            carries trust settings this configuration does not set.
     * @throws CancelledException If the cancellation token is cancelled during the handshake.
     */
    public function complete(
        ServerConfiguration $serverConfiguration,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): StreamInterface {
        return Internal\upgrade_server($this->stream, $serverConfiguration, $cancellation);
    }
}
