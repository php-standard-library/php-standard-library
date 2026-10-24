<?php

declare(strict_types=1);

namespace Psl\TLS;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\LinkedCancellationToken;
use Psl\Async\NullCancellationToken;
use Psl\Async\Scheduler;
use Psl\Async\SignalCancellationToken;
use Psl\Async\TimeoutCancellationToken;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Network;
use Throwable;

use function array_shift;
use function sprintf;

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
 * Handshakes run concurrently. While an {@see accept()} call waits, the listener accepts connections from the inner
 * listener and starts the handshake of each in a fiber of its own, with its own deadline, up to the maximum number of
 * pending handshakes given to the constructor at a time. While that many handshakes are pending it takes no further
 * connection from the inner listener, whose backlog, and then the operating system's, hold the connections that
 * arrive meanwhile. {@see accept()} returns the next connection whose handshake completes, in completion order, so a
 * peer that connects and then stays silent holds only its own slot, until its handshake deadline passes.
 *
 * A handshake that fails, or that exceeds its deadline, closes its connection, and the
 * {@see Exception\HandshakeFailedException} that describes it is delivered to the next {@see accept()} call, in
 * completion order, as it was when handshakes ran one at a time: a server that wants connections only catches it and
 * calls accept() again. A caller's cancellation ends its own wait only: the pending handshakes finish or time out on
 * their own, and their outcomes wait for the next call. Once no call waits, the listener takes no further connection
 * from the inner listener until one does, while the handshakes already pending run on. {@see close()} cancels every
 * pending handshake, which closes those connections, closes the connections whose handshakes completed for nobody,
 * fails every waiting call with {@see Network\Exception\AlreadyStoppedException}, and closes the inner listener.
 *
 * @mago-expect lint:cyclomatic-complexity - Accepting, each handshake and close() each settle the same records.
 * @mago-expect lint:too-many-properties - The accept loop, the pending handshakes and the waiting calls each need their state.
 *
 * @api
 */
final class Listener implements ListenerInterface
{
    /**
     * The most handshakes that may be pending at a time unless the constructor is given another limit: as many
     * connections as a TCP listener keeps in its own backlog.
     */
    public const int DEFAULT_MAX_PENDING_HANDSHAKES = 256;

    /**
     * The handshake deadline, in seconds, unless the constructor is given another.
     */
    private const int DEFAULT_HANDSHAKE_TIMEOUT_SECONDS = 10;

    /**
     * Performs the handshakes with the server configuration given to the constructor.
     */
    private readonly Acceptor $acceptor;

    /**
     * How long an accepted connection may take to complete its handshake.
     */
    private readonly Duration $handshakeTimeout;

    /**
     * The most handshakes that may be pending at a time.
     *
     * @var int<1, max>
     */
    private readonly int $maxPendingHandshakes;

    /**
     * Whether {@see close()} has run.
     */
    private bool $closed = false;

    /**
     * The exception with which the inner listener reported that it has been stopped, once it has.
     */
    private null|Network\Exception\AlreadyStoppedException $stopped = null;

    /**
     * Whether an accept on the inner listener is in flight.
     */
    private bool $accepting = false;

    /**
     * The number of handshakes in flight.
     *
     * @var int<0, max>
     */
    private int $pending = 0;

    /**
     * The outcomes of handshakes that ended while no accept() call was waiting, oldest first: the connection whose
     * handshake completed, or the failure that ended a handshake. There are never more than the pending limit, plus
     * the one connection an accept in flight may still return once the last waiting call has left.
     *
     * @var list<StreamInterface|Throwable>
     */
    private array $ready = [];

    /**
     * The accept() calls waiting for the next outcome, oldest first.
     *
     * The records, and with them the suspensions of the waiting fibers, are referenced strongly: a fiber waiting in
     * accept() may not be referenced from anywhere else, and the cycle collector would destroy it, along with its
     * suspension, if they were only referenced weakly.
     *
     * @var list<Internal\AcceptWaiter>
     */
    private array $waiters = [];

    /**
     * The token given to the accept in flight on the inner listener; replaced once it is cancelled.
     */
    private SignalCancellationToken $acceptCancellation;

    /**
     * The token that {@see close()} cancels, which ends every pending handshake.
     */
    private readonly SignalCancellationToken $closeCancellation;

    /**
     * @param Network\ListenerInterface $listener The listener whose connections are upgraded to TLS; the TLS listener
     *                                            owns it and closes it with {@see close()}.
     * @param ServerConfiguration $serverConfiguration The configuration every handshake uses.
     * @param null|Duration $handshakeTimeout How long an accepted connection may take to complete its TLS handshake;
     *                                        defaults to 10 seconds. The deadline starts once the inner listener has
     *                                        accepted the connection.
     * @param int<1, max> $maxPendingHandshakes The most handshakes that may be pending at a time; defaults to
     *                                          {@see Listener::DEFAULT_MAX_PENDING_HANDSHAKES}. Each pending handshake
     *                                          holds its connection, a fiber and its deadline timer, so the limit bounds
     *                                          what silent peers can cost; while it is reached, no further connection
     *                                          is taken from the inner listener.
     *
     * @throws Network\Exception\InvalidArgumentException If $maxPendingHandshakes is below 1, which would let
     *                                                    {@see accept()} wait forever.
     */
    public function __construct(
        private readonly Network\ListenerInterface $listener,
        ServerConfiguration $serverConfiguration,
        null|Duration $handshakeTimeout = null,
        int $maxPendingHandshakes = self::DEFAULT_MAX_PENDING_HANDSHAKES,
    ) {
        // @mago-expect analysis:redundant-comparison,impossible-condition,no-value - runtime guard for untyped callers
        if ($maxPendingHandshakes < 1) {
            throw new Network\Exception\InvalidArgumentException(sprintf(
                'Expected $maxPendingHandshakes to be a positive integer, %d given.',
                $maxPendingHandshakes,
            ));
        }

        $this->acceptor = new Acceptor($serverConfiguration);
        $this->handshakeTimeout = $handshakeTimeout ?? Duration::seconds(self::DEFAULT_HANDSHAKE_TIMEOUT_SECONDS);
        $this->maxPendingHandshakes = $maxPendingHandshakes;
        $this->acceptCancellation = new SignalCancellationToken();
        $this->closeCancellation = new SignalCancellationToken();
    }

    /**
     * @inheritDoc
     *
     * Returns the next connection whose handshake completes, in completion order; an outcome that ended while no
     * call was waiting is returned first, to a call whose token is already cancelled too, as the TCP listener returns
     * a connection from its backlog. A handshake that failed or exceeded its deadline has closed its connection, and
     * is reported as {@see Exception\HandshakeFailedException}, with the {@see CancelledException} that ended an
     * exceeded deadline as its previous exception; a failure of the inner listener's own accept is rethrown as is.
     * Each outcome reaches one call. A call cancelled while it waits throws {@see CancelledException} with its own
     * token and takes no outcome: the pending handshakes run on, and once no call waits, no further connection is
     * taken from the inner listener until one does.
     *
     * @throws Network\Exception\AlreadyStoppedException If the listener is closed, before or while the call waits, or
     *                                                   the inner listener has been stopped and no handshake is pending.
     */
    #[Override]
    public function accept(CancellationTokenInterface $cancellation = new NullCancellationToken()): StreamInterface
    {
        if ($this->closed) {
            throw new Network\Exception\AlreadyStoppedException('TLS listener has been closed.');
        }

        if ($this->ready !== []) {
            $outcome = array_shift($this->ready);
            if ($outcome instanceof Throwable) {
                throw $outcome;
            }

            return $outcome;
        }

        if (null !== $this->stopped && 0 === $this->pending) {
            throw $this->stopped;
        }

        $cancellable = $cancellation->cancellable;
        if ($cancellable) {
            $cancellation->throwIfCancelled();
        }

        // The wait has its own record, which a handshake outcome, the cancellation listener and close() settle: the
        // listener may run during subscribe(), before the wait has suspended, or after the wait has ended, when the
        // fiber may already be waiting for something else.
        $waiter = new Internal\AcceptWaiter();
        $this->waiters[] = $waiter;
        $this->pump();

        $subscription = null;

        try {
            if ($cancellable) {
                $subscription = $cancellation->subscribe(static function (CancelledException $e) use ($waiter): void {
                    $waiter->settle($e);
                });
            }

            return $waiter->wait();
        } finally {
            if (null !== $subscription) {
                $cancellation->unsubscribe($subscription);
            }

            // A wait that took an outcome has already left the queue; one that ended otherwise leaves it now, and if
            // it was the last waiting call, nothing is accepted for nobody.
            if (Internal\AcceptWaiter::forget($this->waiters, $waiter) && [] === $this->waiters) {
                $this->cancelAccept();
            }
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
        return $this->closed || $this->listener->isClosed();
    }

    /**
     * Close the listener, every pending handshake and the inner listener.
     *
     * The listener stops first: every waiting {@see accept()} call throws {@see Network\Exception\AlreadyStoppedException},
     * as do later calls, the accept in flight on the inner listener is cancelled, and every pending handshake is
     * cancelled, which closes its connection. It then closes every connection whose handshake completed for nobody and
     * the inner listener, all of which it owns, attempting each close even after one fails, and throws the first
     * failure once it has attempted them all. The connections that accept() returned stay open. A failure that no
     * call can receive anymore, one left in the outcome queue or one a handshake ends with after this, is dropped when
     * it is an expected accept failure, a handshake failure, a network or IO failure or a cancellation, and reaches
     * the event loop otherwise, since it is a defect. Closing a closed listener does nothing.
     *
     * @throws IO\Exception\RuntimeException If closing a completed connection or the inner listener failed.
     * @throws Network\Exception\RuntimeException If closing the inner listener failed with a network error.
     */
    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $waiters = $this->waiters;
        $this->waiters = [];
        $ready = $this->ready;
        $this->ready = [];

        // Settle every waiting call before closing anything, so that no failing close can leave one waiting.
        $stopped = new Network\Exception\AlreadyStoppedException('TLS listener has been closed.');
        foreach ($waiters as $waiter) {
            $waiter->settle($stopped);
        }

        $this->cancelAccept();
        $this->closeCancellation->cancel();

        $failure = null;
        foreach ($ready as $outcome) {
            if ($outcome instanceof Throwable) {
                self::reportUnreceivedFailure($outcome);

                continue;
            }

            try {
                $outcome->close();
            } catch (IO\Exception\RuntimeException|Network\Exception\RuntimeException $e) {
                $failure ??= $e;
            }
        }

        try {
            $this->listener->close();
        } catch (IO\Exception\RuntimeException|Network\Exception\RuntimeException $e) {
            $failure ??= $e;
        }

        if (null !== $failure) {
            throw $failure;
        }
    }

    /**
     * Take the next connection from the inner listener, if a call is waiting for an outcome, no accept is in flight,
     * the inner listener still runs, and fewer handshakes than the limit are pending.
     *
     * The accept runs in a fiber of its own, and starts the handshake of the connection it returns; it is cancelled
     * once no call waits anymore, or when the listener closes. An accept that fails otherwise delivers its failure to
     * the oldest waiting call, as a handshake failure is. The inner listener reporting that it has been stopped ends
     * the accepting for good: the pending handshakes still run, and the waiting calls left once they have ended are
     * told that the listener has stopped.
     */
    private function pump(): void
    {
        if (
            $this->closed
            || null !== $this->stopped
            || $this->accepting
            || [] === $this->waiters
            || $this->pending >= $this->maxPendingHandshakes
        ) {
            return;
        }

        $this->accepting = true;
        $acceptCancellation = $this->acceptCancellation;

        Scheduler::defer(function () use ($acceptCancellation): void {
            $connection = null;
            $failure = null;

            try {
                $connection = $this->listener->accept($acceptCancellation);
            } catch (Network\Exception\AlreadyStoppedException $e) {
                $this->stopped ??= $e;
            } catch (CancelledException $e) {
                // The cancellation that ended an accept nobody waits for anymore is of no interest to anyone.
                if (!$acceptCancellation->isCancelled()) {
                    $failure = $e;
                }
            } catch (Throwable $e) {
                $failure = $e;
            }

            if ($this->acceptCancellation === $acceptCancellation) {
                $this->accepting = false;
            }

            if ($this->closed) {
                if (null !== $connection) {
                    self::closeLateConnection($connection);
                } elseif (null !== $failure) {
                    self::reportUnreceivedFailure($failure);
                }

                return;
            }

            if (null !== $connection) {
                $this->startHandshake($connection);
            } elseif (null !== $failure) {
                $this->deliver($failure);
            }

            $this->settleStoppedWaiters();
            $this->pump();
        });
    }

    /**
     * Start the handshake of an accepted connection in a fiber of its own, with its own deadline.
     *
     * The handshake ends with the TLS connection, or with a failure, which closes the plain connection: a failed
     * handshake with its {@see Exception\HandshakeFailedException}, an exceeded deadline with one that carries the
     * {@see CancelledException} that ended it, and a cancellation by {@see close()} with nothing to deliver. The
     * outcome goes to the oldest waiting call, or waits for the next one, and the next connection is taken from the
     * inner listener if the limit allowed no more while this handshake was pending.
     */
    private function startHandshake(Network\StreamInterface $connection): void
    {
        $this->pending++;
        $deadline = new TimeoutCancellationToken($this->handshakeTimeout);
        $cancellation = new LinkedCancellationToken($this->closeCancellation, $deadline);

        Scheduler::defer(function () use ($connection, $deadline, $cancellation): void {
            try {
                $outcome = $this->acceptor->accept($connection, $cancellation);
            } catch (Throwable $failure) {
                self::closeLateConnection($connection);

                $outcome = $failure;
                if ($failure instanceof CancelledException) {
                    $source = $failure->getToken();
                    if ($source === $deadline) {
                        $outcome = new Exception\HandshakeFailedException(
                            'TLS handshake timed out.',
                            previous: $failure,
                        );
                    } elseif ($this->closeCancellation->isCancelled()) {
                        $outcome = null;
                    }
                }
            }

            // @mago-expect analysis:invalid-property-assignment-value - every pending handshake was counted when started.
            $this->pending--;

            if ($this->closed) {
                if ($outcome instanceof StreamInterface) {
                    self::closeLateConnection($outcome);
                } elseif (null !== $outcome) {
                    self::reportUnreceivedFailure($outcome);
                }

                return;
            }

            if (null !== $outcome) {
                $this->deliver($outcome);
            }

            $this->settleStoppedWaiters();
            $this->pump();
        });
    }

    /**
     * Hand an outcome to the oldest waiting call that has not ended yet, or keep it for the next call.
     */
    private function deliver(StreamInterface|Throwable $outcome): void
    {
        if (!Internal\AcceptWaiter::handOver($this->waiters, $outcome)) {
            $this->ready[] = $outcome;
        }
    }

    /**
     * Once the inner listener has stopped and the last pending handshake has ended, tell every call still waiting
     * that nothing more can come.
     */
    private function settleStoppedWaiters(): void
    {
        if (null === $this->stopped || 0 !== $this->pending) {
            return;
        }

        $waiters = $this->waiters;
        $this->waiters = [];
        foreach ($waiters as $waiter) {
            $waiter->settle($this->stopped);
        }
    }

    /**
     * Cancel the accept in flight on the inner listener, if any; the next one gets a fresh token.
     */
    private function cancelAccept(): void
    {
        if (!$this->accepting) {
            return;
        }

        $acceptCancellation = $this->acceptCancellation;
        $this->acceptCancellation = new SignalCancellationToken();
        $this->accepting = false;

        $acceptCancellation->cancel();
    }

    /**
     * Close a connection that nobody called for: one whose handshake failed, one returned or completed after the
     * listener was closed.
     *
     * Nobody can be told that closing it failed: an expected close failure is suppressed, as a destructor would, while
     * any other failure still reaches the event loop.
     */
    private static function closeLateConnection(Network\StreamInterface $connection): void
    {
        try {
            $connection->close();
        } catch (IO\Exception\RuntimeException|Network\Exception\RuntimeException) {
            // @mago-expect lint:no-empty-catch-clause - Nobody can be told of an expected close failure here (E11).
        }
    }

    /**
     * Report a failure that no accept() call can receive anymore: one that a handshake or the inner accept ended with
     * after the listener was closed, or one that the outcome queue held when it closed.
     *
     * A failure of an operational category that accept() throws (a handshake failure, IO, Network, or a cancellation)
     * is dropped, as the connection such a handshake completes is closed: nobody is left to be told of it. Anything
     * else, an `Error` included, is a defect, and must not be lost: it is thrown from an event loop callback, so that
     * it reaches the event loop's error handler, or whoever runs the event loop.
     */
    private static function reportUnreceivedFailure(Throwable $failure): void
    {
        if (
            $failure instanceof Exception\ExceptionInterface
            || $failure instanceof IO\Exception\RuntimeException
            || $failure instanceof Network\Exception\RuntimeException
            || $failure instanceof CancelledException
        ) {
            return;
        }

        Scheduler::defer(static function () use ($failure): never {
            throw $failure;
        });
    }
}
