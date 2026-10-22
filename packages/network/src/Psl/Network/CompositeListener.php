<?php

declare(strict_types=1);

namespace Psl\Network;

use Override;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Throwable;

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
     * The waiting accept() calls that nothing has woken up yet, oldest first, by record object ID.
     *
     * Each call waits on a record of its own, which a wake-up or its cancellation listener settles, whichever comes
     * first. The records, and with them the suspensions of the waiting fibers, are referenced strongly: a fiber
     * waiting in accept() may not be referenced from anywhere else, and the cycle collector would destroy it, along
     * with its suspension, if they were only referenced weakly. A record stays in the list until it is woken up or
     * its call ends otherwise, so its object ID cannot be reused while it is listed.
     *
     * @var array<int, Internal\CompositeAcceptWaiter>
     */
    private array $waiters = [];

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
     *
     * The composite listener stops first: it wakes up every waiting {@see accept()} call, which throws
     * {@see Exception\AlreadyStoppedException}, as do later calls, and cancels the in-flight inner accepts. It then
     * closes every connection left in its backlog and every inner listener, all of which it owns. It attempts each of
     * those closes even after one fails, and throws the first failure once it has attempted them all. A connection
     * that an inner accept returns after this is closed. Closing a closed listener does nothing.
     *
     * @throws IO\Exception\RuntimeException If closing a connection of the backlog or an inner listener failed.
     * @throws Exception\RuntimeException If closing an inner listener failed with a network error.
     */
    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $backlog = $this->backlog;
        $this->backlog = [];

        // Settle every waiting call before closing anything, so that no failing close can leave one waiting. Do not
        // rely on inner listeners interrupting their pending accepts when closed either.
        $this->notifyAllSignals();
        $this->cancelPendingAccepts();

        $failure = null;
        foreach ($backlog as $result) {
            if (!$result instanceof StreamInterface) {
                continue;
            }

            try {
                $result->close();
            } catch (IO\Exception\RuntimeException|Exception\RuntimeException $e) {
                $failure ??= $e;
            }
        }

        foreach ($this->listeners as $listener) {
            try {
                $listener->close();
            } catch (IO\Exception\RuntimeException|Exception\RuntimeException $e) {
                $failure ??= $e;
            }
        }

        if (null !== $failure) {
            throw $failure;
        }
    }

    /**
     * Wait until an inner accept completes, an inner listener stops, or the listener is closed.
     *
     * The wait has a record of its own, which a wake-up and the cancellation listener settle, whichever comes first;
     * the other is then ignored. The listener may run during subscribe(), before the wait has suspended, or after
     * the wait has ended, when the fiber may already be waiting for something else, so it fails only a wait that has
     * not ended yet. A call that its cancellation ends leaves the list of waiting calls at once, and if no other call
     * is waiting, the in-flight inner accepts are cancelled.
     *
     * @throws Async\Exception\CancelledException If the cancellation token is cancelled while waiting.
     */
    private function wait(CancellationTokenInterface $cancellation): void
    {
        $waiter = new Internal\CompositeAcceptWaiter();
        $id = spl_object_id($waiter);
        $this->waiters[$id] = $waiter;

        $subscription = null;
        $wasWoken = false;

        try {
            if ($cancellation->cancellable) {
                $subscription = $cancellation->subscribe(function (Async\Exception\CancelledException $e) use (
                    $waiter,
                    $id,
                ): void {
                    // A wake-up may have ended the wait already: by an inner accept, or by close().
                    if ($waiter->fail($e)) {
                        $this->forget($id);
                    }
                });
            }

            $waiter->wait();
            $wasWoken = true;

            // @mago-expect analysis:invalid-property-assignment-value - every woken call was counted when woken up.
            $this->woken--;
        } finally {
            if (null !== $subscription) {
                $cancellation->unsubscribe($subscription);
            }

            // A wait that something other than a wake-up or its cancellation ended still leaves the list.
            if (!$wasWoken) {
                $this->forget($id);
            }
        }
    }

    /**
     * Take a waiting accept() call that has ended without being woken up out of the list of waiting calls, if it is
     * still there, and cancel the in-flight inner accepts if no other call is waiting for them.
     *
     * @param int $id The object ID of the call's record, its key in {@see CompositeListener::$waiters}.
     */
    private function forget(int $id): void
    {
        if (!isset($this->waiters[$id])) {
            return;
        }

        unset($this->waiters[$id]);
        $this->cancelPendingAcceptsIfNobodyIsWaiting();
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
                        self::closeLateConnection($result);
                    }

                    return;
                }

                $this->backlog[] = $result;
                $this->notifySignal();

                // Inner accepts are only started by accept() calls that start waiting: start this one
                // again for the calls still waiting, as no new call may come while they wait.
                if ([] !== $this->waiters) {
                    $this->startPendingAccepts();
                }
            });
        }
    }

    /**
     * Close a connection that an inner accept returned after the composite listener was closed.
     *
     * Nobody called for the connection, so nobody can be told that closing it failed: an expected close failure is
     * suppressed, as a destructor would, while any other failure still reaches the event loop.
     */
    private static function closeLateConnection(StreamInterface $connection): void
    {
        try {
            $connection->close();
        } catch (IO\Exception\RuntimeException|Exception\RuntimeException) {
            // @mago-expect lint:no-empty-catch-clause - Nobody can be told of an expected close failure here (E11).
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
        if ([] === $this->waiters && 0 === $this->woken) {
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
     * Wake up the longest waiting accept() call, to tell it that something happened.
     *
     * A call whose wait has already ended, which only something other than a wake-up or its cancellation can do, is
     * skipped and leaves the list.
     */
    private function notifySignal(): void
    {
        foreach ($this->waiters as $id => $waiter) {
            unset($this->waiters[$id]);
            if ($waiter->wake()) {
                $this->woken++;

                return;
            }
        }
    }

    /**
     * Wake up every waiting accept() call, to tell them that something happened.
     */
    private function notifyAllSignals(): void
    {
        $waiters = $this->waiters;
        $this->waiters = [];
        foreach ($waiters as $waiter) {
            // A call whose wait has already ended is not counted as woken up.
            if (!$waiter->wake()) {
                continue;
            }

            $this->woken++;
        }
    }
}
