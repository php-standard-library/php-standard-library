<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use Closure;
use Psl\Async;
use Psl\IO;
use Psl\Network;
use Psl\TCP;

/**
 * A plain TCP relay between one client and an upstream listener, which records the bytes it forwards.
 *
 * Each direction runs in its own fiber. By default a direction writes every chunk on unchanged; a custom forwarder
 * may instead split, delay or alter the bytes it writes.
 */
final class Relay
{
    /**
     * The listener the client connects to.
     */
    private readonly TCP\ListenerInterface $listener;

    /**
     * Cancels both directions when the relay stops.
     */
    private readonly Async\SignalCancellationToken $stop;

    /**
     * The fibers that forward each direction, once the client has connected.
     *
     * @var list<Async\Awaitable<void>>
     */
    private array $forwarders = [];

    /**
     * The relay's end of the client's connection, once the client has connected.
     */
    private null|Network\StreamInterface $downstream = null;

    /**
     * The relay's connection to the upstream listener, once the client has connected.
     */
    private null|Network\StreamInterface $upstream = null;

    /**
     * Every byte the client sent, in order.
     */
    private string $fromClient = '';

    /**
     * Every byte the upstream listener sent, in order.
     */
    private string $fromServer = '';

    /**
     * Listen for one client on a free local port.
     *
     * @param int<0, 65535> $upstreamPort The port of the upstream listener on 127.0.0.1.
     * @param null|(Closure(string, Network\StreamInterface, Async\CancellationTokenInterface): void) $forwardToServer
     *        Writes a chunk the client sent to the upstream connection; null writes it unchanged.
     */
    public function __construct(
        private readonly int $upstreamPort,
        private readonly null|Closure $forwardToServer = null,
    ) {
        $this->listener = TCP\listen('127.0.0.1', 0);
        $this->stop = new Async\SignalCancellationToken();
    }

    /**
     * The port the client connects to.
     *
     * @return int<0, 65535>
     */
    public function port(): int
    {
        return $this->listener->getLocalAddress()->port;
    }

    /**
     * Accept the client, connect to the upstream listener, and start forwarding in both directions.
     */
    public function accept(Async\CancellationTokenInterface $cancellation): void
    {
        $downstream = $this->listener->accept($cancellation);
        $upstream = TCP\connect('127.0.0.1', $this->upstreamPort, cancellation: $cancellation);
        $this->downstream = $downstream;
        $this->upstream = $upstream;

        $toServer = $this->forwardToServer ?? self::writeUnchanged(...);
        $this->forwarders = [
            Async\run(function () use ($downstream, $upstream, $toServer): void {
                $this->forward($downstream, $upstream, $toServer, true);
            }),
            Async\run(function () use ($upstream, $downstream): void {
                $this->forward($upstream, $downstream, self::writeUnchanged(...), false);
            }),
        ];
    }

    /**
     * Every byte the client sent so far.
     */
    public function fromClient(): string
    {
        return $this->fromClient;
    }

    /**
     * Every byte the upstream listener sent so far.
     */
    public function fromServer(): string
    {
        return $this->fromServer;
    }

    /**
     * Stop forwarding, and close both connections and the listener.
     */
    public function close(): void
    {
        $this->stop->cancel();
        Async\all($this->forwarders);
        $this->forwarders = [];
        $this->downstream?->close();
        $this->upstream?->close();
        $this->listener->close();
    }

    /**
     * Copy one direction until its source ends or the relay stops, recording what arrives.
     *
     * @param (Closure(string, Network\StreamInterface, Async\CancellationTokenInterface): void) $write
     */
    private function forward(
        Network\StreamInterface $from,
        Network\StreamInterface $to,
        Closure $write,
        bool $fromClient,
    ): void {
        try {
            while (true) {
                $chunk = $from->read(null, $this->stop);
                if ('' === $chunk && $from->reachedEndOfDataSource()) {
                    $to->shutdown();

                    return;
                }

                if ($fromClient) {
                    $this->fromClient .= $chunk;
                } else {
                    $this->fromServer .= $chunk;
                }

                $write($chunk, $to, $this->stop);
            }
        } catch (Async\Exception\CancelledException|IO\Exception\ExceptionInterface) {
            // The relay stopped, or a test broke one of the connections on purpose.
            return;
        }
    }

    /**
     * Write a chunk on unchanged.
     */
    private static function writeUnchanged(
        string $chunk,
        Network\StreamInterface $to,
        Async\CancellationTokenInterface $cancellation,
    ): void {
        $to->writeAll($chunk, $cancellation);
    }
}
