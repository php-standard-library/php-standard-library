<?php

declare(strict_types=1);

namespace Psl\TLS;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Network;
use Psl\TLS\Exception\HandshakeFailedException;

/**
 * Performs TLS server handshakes on incoming streams.
 *
 * Takes an accepted plain stream and upgrades it to a TLS-encrypted stream
 * using the provided {@see ServerConfiguration}.
 *
 * Usage:
 *   $acceptor = new Acceptor(ServerConfig::create($certificate));
 *   while (true) {
 *       $stream = $listener->accept();
 *       $tls = $acceptor->accept($stream);
 *   }
 *
 * @api
 */
final readonly class Acceptor
{
    public function __construct(
        private ServerConfiguration $serverConfiguration,
    ) {}

    /**
     * Perform a TLS handshake on an incoming stream.
     *
     * Streams accepted by `TCP\listen()` own a private stream context when ext-sockets is
     * available. Without it, all streams accepted from one listener share the listener's
     * context, and a stream whose context already carries client certificate trust settings
     * (`cafile`, `capath`) that this configuration does not set is refused rather than
     * upgraded with another configuration's trust settings.
     *
     * @throws HandshakeFailedException If the TLS handshake fails.
     * @throws Network\Exception\RuntimeException If the stream is not available, or if its context
     *                                            carries trust settings this configuration does not set.
     * @throws CancelledException If the cancellation token is cancelled during the handshake.
     */
    public function accept(
        Network\StreamInterface $stream,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): StreamInterface {
        return Internal\upgrade_server($stream, $this->serverConfiguration, $cancellation);
    }
}
