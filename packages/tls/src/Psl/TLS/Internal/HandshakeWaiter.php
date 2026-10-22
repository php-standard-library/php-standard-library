<?php

declare(strict_types=1);

namespace Psl\TLS\Internal;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\Scheduler;
use Psl\DateTime\Duration;
use Revolt\EventLoop;

use function min;

/**
 * Waits between two steps of one TLS handshake.
 *
 * A step that returns 0 waits for the socket, but PHP 8.4 and 8.5 do not tell which way: OpenSSL may wait for the
 * peer's next flight, or for room in the socket's send buffer to write the rest of its own. It usually waits to read.
 * It waits to write only while the send buffer is full, for example after a ClientHello larger than the socket
 * buffers, and then the peer, which waits for the rest of that flight, sends nothing that would make the socket
 * readable. Waiting for writability instead would return at once while OpenSSL waits to read, and spin.
 *
 * So each wait ends when the socket becomes readable, or after a pause, whichever comes first, and the handshake then
 * takes its next step. The pause starts at 10 ms and doubles up to 320 ms for each wait that the pause ends, and goes
 * back to 10 ms once the socket was readable. A handshake that waits to write goes on as the peer makes room, and one
 * that waits for a slow peer takes at most a few extra steps a second, each of which only finds nothing to read.
 *
 * @internal
 */
final class HandshakeWaiter
{
    /**
     * The first pause, and the pause after a wait that the socket ended, in milliseconds.
     */
    private const int INITIAL_PAUSE_MILLISECONDS = 10;

    /**
     * The longest pause, in milliseconds.
     */
    private const int MAXIMUM_PAUSE_MILLISECONDS = 320;

    /**
     * The pause of the next wait, in milliseconds.
     */
    private int $pause = self::INITIAL_PAUSE_MILLISECONDS;

    /**
     * Prepare the waits of a handshake on the given stream.
     *
     * @param resource $stream The stream the handshake runs on.
     */
    public function __construct(
        /**
         * The stream the handshake runs on.
         *
         * @var resource
         */
        private readonly mixed $stream,
        /**
         * The token that ends every wait of the handshake.
         */
        private readonly CancellationTokenInterface $cancellation,
    ) {}

    /**
     * Wait until the socket is readable, or until the pause ends.
     *
     * @throws CancelledException If the cancellation token is cancelled while waiting.
     */
    public function wait(): void
    {
        $cancellable = $this->cancellation->cancellable;
        if ($cancellable) {
            $this->cancellation->throwIfCancelled();
        }

        // The wait has its own record, which the watcher, the timer and the cancellation listener settle: the
        // listener may run during subscribe(), before the wait has suspended, or after the wait has ended, when the
        // fiber may already be waiting for something else.
        $readable = false;
        $waiter = new Waiter();
        $watcher = EventLoop::onReadable($this->stream, static function () use ($waiter, &$readable): void {
            $readable = true;
            $waiter->resume();
        });
        $timer = Scheduler::delay(Duration::milliseconds($this->pause), static function () use ($waiter): void {
            $waiter->resume();
        });

        $cancellationId = null;

        try {
            if ($cancellable) {
                $cancellationId = $this->cancellation->subscribe(static function (CancelledException $e) use (
                    $waiter,
                ): void {
                    $waiter->fail($e);
                });
            }

            $waiter->wait();
        } finally {
            EventLoop::cancel($watcher);
            Scheduler::cancel($timer);
            if (null !== $cancellationId) {
                $this->cancellation->unsubscribe($cancellationId);
            }
        }

        $this->pause = $readable
            ? self::INITIAL_PAUSE_MILLISECONDS
            : min($this->pause * 2, self::MAXIMUM_PAUSE_MILLISECONDS);
    }
}
