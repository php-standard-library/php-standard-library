<?php

declare(strict_types=1);

namespace Psl\Network;

use Override;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Revolt\EventLoop\Suspension;
use Throwable;

use function array_key_first;
use function array_map;
use function array_shift;
use function count;
use function spl_object_id;

/**
 * A listener that accepts connections from multiple inner listeners concurrently.
 *
 * When {@see accept()} is called and no backlog is available, each inner listener
 * that doesn't already have a pending accept gets one in-flight accept fiber.
 * The first to return is delivered; others fill the backlog (bounded by N where
 * N = number of listeners) for subsequent calls.
 *
 * If an inner listener's accept() fails (e.g. a TLS handshake failure), the error
 * is delivered to a caller of {@see accept()} like a connection would be; the
 * other listeners keep accepting. An inner listener that reports it has been
 * stopped is no longer used, and once all of them are stopped, {@see accept()}
 * throws {@see Exception\AlreadyStoppedException}.
 *
 * In-flight inner accepts are cancelled when the last waiting {@see accept()} call
 * is cancelled, and when the composite listener is closed.
 *
 * Backpressure propagates naturally: if the composite consumer is slow, inner
 * listeners' accept() calls block, their own backlogs fill, and they stop
 * accepting from the OS.
 *
 * @api
 */
final class CompositeListener implements ListenerInterface
{
    /**
     * @var non-empty-list<ListenerInterface>
     */
    private readonly array $listeners;

    private bool $closed = false;

    /**
     * Accept results waiting to be consumed: accepted streams, or errors thrown by
     * an inner listener's accept(). Bounded by the number of listeners.
     *
     * @var list<StreamInterface|Throwable>
     */
    private array $backlog = [];

    /**
     * Tracks which listeners have an in-flight accept fiber, and the token it was started with.
     *
     * @var array<int, Async\SignalCancellationToken>
     */
    private array $pending = [];

    /**
     * Tracks which listeners have reported being stopped.
     *
     * @var array<int, true>
     */
    private array $stopped = [];

    /**
     * Token given to newly started inner accepts.
     */
    private Async\SignalCancellationToken $acceptCancellation;

    /**
     * Waiting accept() calls, by suspension object ID.
     *
     * The suspensions are referenced strongly: a fiber waiting in accept() may not be referenced from
     * anywhere else, and the cycle collector would destroy it, along with its suspension, if they were
     * only referenced weakly.
     *
     * @var array<int, Suspension<null>>
     */
    private array $signal = [];

    /**
     * Number of waiting accept() calls that have been woken up, but have not run yet.
     *
     * @var int<0, max>
     */
    private int $woken = 0;

    /**
     * @param non-empty-list<ListenerInterface> $listeners
     */
    public function __construct(array $listeners)
    {
        $this->listeners = $listeners;
        $this->acceptCancellation = new Async\SignalCancellationToken();
    }

    /**
     * Accept the next connection from any of the inner listeners.
     *
     * Errors thrown by an inner listener's accept() are rethrown as-is.
     *
     * @throws Exception\AlreadyStoppedException If all listeners have been stopped.
     * @throws Exception\RuntimeException If an inner listener failed to accept an incoming connection.
     * @throws Async\Exception\CancelledException If the cancellation token is cancelled while waiting.
     */
    #[Override]
    public function accept(CancellationTokenInterface $cancellation = new NullCancellationToken()): StreamInterface
    {
        $waited = false;
        while (true) {
            if ($this->closed) {
                throw new Exception\AlreadyStoppedException('All listeners have been stopped.');
            }

            if ($this->backlog !== []) {
                $result = array_shift($this->backlog);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            if (count($this->stopped) === count($this->listeners)) {
                throw new Exception\AlreadyStoppedException('All listeners have been stopped.');
            }

            if ($cancellation->isCancelled()) {
                // A call cancelled after being woken up, that finds nothing to take, was still waiting:
                // like a call cancelled while waiting, it must not leave inner accepts running for nobody.
                if ($waited) {
                    $this->cancelPendingAcceptsIfNobodyIsWaiting();
                }

                $cancellation->throwIfCancelled();
            }

            $this->startPendingAccepts();

            // Being woken up does not guarantee a result: another caller may have taken it from
            // the backlog before this fiber got to run, in which case the loop waits again.
            $waited = true;
            $this->wait($cancellation);
        }
    }

    /**
     * Returns the local address of the first inner listener.
     */
    #[Override]
    public function getLocalAddress(): Address
    {
        return $this->listeners[0]->getLocalAddress();
    }

    /**
     * Returns the local addresses of all inner listeners.
     *
     * @return non-empty-list<Address>
     */
    public function getLocalAddresses(): array
    {
        return array_map(
            static fn(ListenerInterface $listener): Address => $listener->getLocalAddress(),
            $this->listeners,
        );
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Close all inner listeners and stop accepting connections.
     */
    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->backlog as $result) {
            if (!$result instanceof StreamInterface) {
                continue;
            }

            $result->close();
        }

        $this->backlog = [];

        foreach ($this->listeners as $listener) {
            $listener->close();
        }

        // Do not rely on inner listeners interrupting their pending accepts when closed.
        $this->cancelPendingAccepts();

        $this->notifyAllSignals();
    }

    /**
     * Wait until an inner accept completes, or the listener is closed.
     *
     * @throws Async\Exception\CancelledException If the cancellation token is cancelled while waiting.
     */
    private function wait(CancellationTokenInterface $cancellation): void
    {
        /** @var Suspension<null> $signal */
        $signal = Async\Scheduler::getSuspension();
        $id = spl_object_id($signal);
        $this->signal[$id] = $signal;
        $subscription = $cancellation->subscribe(function (Async\Exception\CancelledException $e) use (
            $id,
            $signal,
        ): void {
            // The signal may have already been resumed by an inner accept, or by close().
            if (($this->signal[$id] ?? null) !== $signal) {
                return;
            }

            unset($this->signal[$id]);
            $signal->throw($e);

            $this->cancelPendingAcceptsIfNobodyIsWaiting();
        });

        try {
            $signal->suspend();

            // @mago-expect analysis:invalid-property-assignment-value - every resumed signal was counted when woken up.
            $this->woken--;
        } finally {
            $cancellation->unsubscribe($subscription);
        }
    }

    /**
     * Start an accept fiber for each listener that doesn't already have one.
     */
    private function startPendingAccepts(): void
    {
        $acceptCancellation = $this->acceptCancellation;
        foreach ($this->listeners as $i => $listener) {
            if (isset($this->pending[$i]) || isset($this->stopped[$i])) {
                continue;
            }

            $this->pending[$i] = $acceptCancellation;

            Async\Scheduler::defer(function () use ($i, $listener, $acceptCancellation): void {
                $stopped = false;
                $result = null;

                try {
                    $result = $listener->accept($acceptCancellation);
                } catch (Exception\AlreadyStoppedException) {
                    $stopped = true;
                } catch (Throwable $error) {
                    // Errors caused by cancelling the accept are of no interest to anyone.
                    if (!$acceptCancellation->isCancelled()) {
                        $result = $error;
                    }
                }

                if (($this->pending[$i] ?? null) === $acceptCancellation) {
                    unset($this->pending[$i]);
                }

                if ($stopped) {
                    $this->stopped[$i] = true;
                    // Every waiting call must find out if all listeners are now stopped.
                    $this->notifyAllSignals();

                    return;
                }

                if (null === $result) {
                    return;
                }

                if ($this->closed) {
                    if ($result instanceof StreamInterface) {
                        $result->close();
                    }

                    return;
                }

                $this->backlog[] = $result;
                $this->notifySignal();

                // Inner accepts are only started by accept() calls that start waiting: start this one
                // again for the calls still waiting, as no new call may come while they wait.
                if ([] !== $this->signal) {
                    $this->startPendingAccepts();
                }
            });
        }
    }

    /**
     * Cancel all in-flight inner accepts, unless an accept() call is still waiting for them.
     *
     * Do not leave the in-flight inner accepts running once nobody is waiting for them. A call that has
     * been woken up, but has not run yet, still counts: it waits again if another call took what it was
     * woken up for, and cancelling would abort inner accepts in the middle of a TLS handshake.
     */
    private function cancelPendingAcceptsIfNobodyIsWaiting(): void
    {
        if ([] === $this->signal && 0 === $this->woken) {
            $this->cancelPendingAccepts();
        }
    }

    /**
     * Cancel all in-flight inner accepts; new ones are started with a fresh token.
     */
    private function cancelPendingAccepts(): void
    {
        if ($this->pending === []) {
            return;
        }

        $acceptCancellation = $this->acceptCancellation;
        $this->acceptCancellation = new Async\SignalCancellationToken();
        $this->pending = [];

        $acceptCancellation->cancel();
    }

    /**
     * Notify the longest waiting accept() call that something happened.
     */
    private function notifySignal(): void
    {
        $id = array_key_first($this->signal);
        if (null === $id) {
            return;
        }

        $signal = $this->signal[$id];
        unset($this->signal[$id]);
        $this->woken++;
        $signal->resume(null);
    }

    /**
     * Notify all waiting accept() calls that something happened.
     */
    private function notifyAllSignals(): void
    {
        $signals = $this->signal;
        $this->signal = [];
        foreach ($signals as $signal) {
            $this->woken++;
            $signal->resume(null);
        }
    }
}
