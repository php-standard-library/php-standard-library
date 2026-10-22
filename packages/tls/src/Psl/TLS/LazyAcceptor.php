<?php

declare(strict_types=1);

namespace Psl\TLS;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Async\Scheduler;
use Psl\DateTime\Duration;
use Psl\Default\DefaultInterface;
use Psl\Network;
use Psl\TLS\Exception\HandshakeFailedException;
use Revolt\EventLoop;

use function is_resource;
use function min;
use function stream_socket_recvfrom;
use function strlen;

use const STREAM_PEEK;

/**
 * Lazily accepts TLS connections by peeking at the ClientHello before completing the handshake.
 *
 * Peeks at the raw TLS ClientHello to inspect SNI hostname and ALPN protocols,
 * then lets you choose the appropriate {@see ServerConfiguration} before completing the handshake.
 *
 * Usage:
 *   $lazy = LazyAcceptor::default();
 *   while (true) {
 *       $stream = $listener->accept();
 *       $hello = $lazy->accept($stream);
 *       $config = match($hello->getServerName()) {
 *           'api.example.com' => $apiConfig,
 *           default => $defaultConfig,
 *       };
 *       $tls = $hello->complete($config);
 *   }
 *
 * @api
 */
final class LazyAcceptor implements DefaultInterface
{
    /**
     * The most bytes inspection peeks at, as many as one record can carry. A ClientHello that needs more is reported
     * from the extensions that end within them.
     */
    private const int MAXIMUM_INSPECTED_BYTES = 16_384;

    /**
     * The first wait for more of an incomplete ClientHello, and the wait after each peek that found more of it.
     */
    private const int INITIAL_DELAY_MILLISECONDS = 10;

    /**
     * The longest wait for more of an incomplete ClientHello: the wait doubles up to this while nothing new arrives.
     */
    private const int MAXIMUM_DELAY_MILLISECONDS = 320;

    /**
     * @pure
     */
    #[Override]
    public static function default(): static
    {
        return new self();
    }

    /**
     * Peek at the ClientHello without consuming data, then return a {@see ClientHello}
     * that can be used to complete the handshake with a chosen config.
     *
     * The client may send its ClientHello in several pieces, and across several TLS records.
     * Inspection waits until the whole ClientHello has arrived: the bytes received so far keep
     * the socket readable, so it peeks again after a pause, which starts at 10 ms and doubles up
     * to 320 ms while nothing new arrives. A peer that stops sending holds the call until the
     * cancellation token ends it. A ClientHello longer than 16 KiB is reported from the
     * extensions that end within its first 16 KiB, and the handshake completes as usual.
     *
     * @throws Network\Exception\RuntimeException If the stream is not available.
     * @throws HandshakeFailedException If the ClientHello data cannot be read or is malformed.
     * @throws CancelledException If the cancellation token is cancelled while waiting.
     */
    public function accept(
        Network\StreamInterface $stream,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): ClientHello {
        $resource = $stream->getStream();
        if (!is_resource($resource)) {
            throw new Network\Exception\RuntimeException('Stream resource is not available.');
        }

        $cancellable = $cancellation->cancellable;
        if ($cancellable) {
            $cancellation->throwIfCancelled();
        }

        // Wait for data to be available. The wait has its own record, which the watcher and the cancellation listener
        // settle: the listener may run during subscribe(), before the wait has suspended, or after the wait has
        // ended, when the fiber may already be waiting for something else.
        $waiter = new Internal\Waiter();
        $watcher = EventLoop::onReadable($resource, static function () use ($waiter): void {
            $waiter->resume();
        });

        $cancellationId = null;

        try {
            if ($cancellable) {
                $cancellationId = $cancellation->subscribe(static function (CancelledException $e) use ($waiter): void {
                    $waiter->fail($e);
                });
            }

            $waiter->wait();
        } finally {
            EventLoop::cancel($watcher);
            if (null !== $cancellationId) {
                $cancellation->unsubscribe($cancellationId);
            }
        }

        // The stream may have been closed by another fiber while this one waited.
        if ($stream->isClosed()) {
            throw new Network\Exception\RuntimeException('Stream closed while waiting for ClientHello.');
        }

        $delay = self::INITIAL_DELAY_MILLISECONDS;
        $peeked = 0;
        while (true) {
            $data = @stream_socket_recvfrom($resource, self::MAXIMUM_INSPECTED_BYTES, STREAM_PEEK);
            if ($data === false || $data === '') {
                throw new HandshakeFailedException('Failed to peek ClientHello data.');
            }

            $length = strlen($data);
            $parsed = Internal\ClientHelloParser::parse($data, truncated: $length >= self::MAXIMUM_INSPECTED_BYTES);
            if (null !== $parsed) {
                return new ClientHello($stream, $parsed['server_name'], $parsed['alpn_protocols']);
            }

            // The rest of the ClientHello is on its way. The bytes already here keep the socket readable, so waiting
            // for readability would return at once.
            $delay = $length > $peeked
                ? self::INITIAL_DELAY_MILLISECONDS
                : min($delay * 2, self::MAXIMUM_DELAY_MILLISECONDS);
            $peeked = $length;
            self::pause(Duration::milliseconds($delay), $cancellation);
            if ($stream->isClosed()) {
                throw new Network\Exception\RuntimeException('Stream closed while waiting for ClientHello.');
            }
        }
    }

    /**
     * Wait for the given delay, or until the cancellation token is cancelled.
     *
     * @throws CancelledException If the cancellation token is cancelled before the delay elapses.
     */
    private static function pause(Duration $delay, CancellationTokenInterface $cancellation): void
    {
        $cancellable = $cancellation->cancellable;
        if ($cancellable) {
            $cancellation->throwIfCancelled();
        }

        $waiter = new Internal\Waiter();
        $timer = Scheduler::delay($delay, static function () use ($waiter): void {
            $waiter->resume();
        });

        $cancellationId = null;

        try {
            if ($cancellable) {
                $cancellationId = $cancellation->subscribe(static function (CancelledException $e) use ($waiter): void {
                    $waiter->fail($e);
                });
            }

            $waiter->wait();
        } finally {
            Scheduler::cancel($timer);
            if (null !== $cancellationId) {
                $cancellation->unsubscribe($cancellationId);
            }
        }
    }
}
