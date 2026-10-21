<?php

declare(strict_types=1);

namespace Psl\TLS;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\LinkedCancellationToken;
use Psl\Async\NullCancellationToken;
use Psl\Async\TimeoutCancellationToken;
use Psl\DateTime\Duration;
use Psl\Network;
use Throwable;

/**
 * A TLS listener that wraps a plain listener and performs TLS handshakes on accepted connections.
 *
 * Usage:
 *
 *   $listener = new Listener(TCP\listen('0.0.0.0', 443), ServerConfig::create($certificate));
 *   while (true) {
 *       $stream = $listener->accept();
 *       // $stream is TLS-encrypted
 *   }
 *
 * The handshake runs inside {@see accept()}, one connection at a time: a peer that
 * connects and then stays silent holds up every later connection until its handshake
 * deadline passes. Servers that must keep accepting while a handshake is slow should
 * accept plain streams from the inner listener and run an {@see Acceptor} per connection
 * in its own fiber instead.
 *
 * @api
 */
final readonly class Listener implements ListenerInterface
{
    private Acceptor $acceptor;
    private Duration $handshakeTimeout;

    /**
     * @param null|Duration $handshakeTimeout How long an accepted connection may take to complete its
     *                                        TLS handshake; defaults to 10 seconds. The deadline starts
     *                                        once the inner listener has accepted the connection.
     */
    public function __construct(
        private Network\ListenerInterface $listener,
        ServerConfiguration $serverConfiguration,
        null|Duration $handshakeTimeout = null,
    ) {
        $this->acceptor = new Acceptor($serverConfiguration);
        $this->handshakeTimeout = $handshakeTimeout ?? Duration::seconds(10);
    }

    /**
     * @inheritDoc
     *
     * A connection whose handshake fails, exceeds the handshake deadline, or is cancelled is closed.
     * An exceeded deadline is reported as {@see Exception\HandshakeFailedException}, with the
     * {@see CancelledException} that ended the handshake as its previous exception.
     */
    #[Override]
    public function accept(CancellationTokenInterface $cancellation = new NullCancellationToken()): StreamInterface
    {
        $stream = $this->listener->accept($cancellation);
        $deadline = new TimeoutCancellationToken($this->handshakeTimeout);

        try {
            return $this->acceptor->accept($stream, new LinkedCancellationToken($cancellation, $deadline));
        } catch (Throwable $exception) {
            $stream->close();

            if ($exception instanceof CancelledException) {
                $source = $exception->getToken();
                if ($source === $deadline) {
                    throw new Exception\HandshakeFailedException('TLS handshake timed out.', previous: $exception);
                }
            }

            throw $exception;
        }
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getLocalAddress(): Network\Address
    {
        return $this->listener->getLocalAddress();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function isClosed(): bool
    {
        return $this->listener->isClosed();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function close(): void
    {
        $this->listener->close();
    }
}
