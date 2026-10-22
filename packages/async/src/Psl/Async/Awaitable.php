<?php

declare(strict_types=1);

namespace Psl\Async;

use Closure;
use Generator;
use Override;
use Psl\Async\Exception\CancelledException;
use Psl\Async\Internal\AwaitableIterator;
use Psl\Async\Internal\State;
use Psl\Promise\PromiseInterface;
use Revolt\EventLoop;
use Throwable;

use function is_array;

/**
 * The following class was derived from code of Amphp.
 *
 * https://github.com/amphp/amp/blob/ac89b9e2ee04228e064e424056a08590b0cdc7b3/lib/Future.php
 *
 * Code subject to the MIT license (https://github.com/amphp/amp/blob/ac89b9e2ee04228e064e424056a08590b0cdc7b3/LICENSE).
 *
 * Copyright (c) 2015-2021 Amphp ( https://amphp.org )
 *
 * @template-covariant T
 *
 * @implements PromiseInterface<T>
 *
 * @api
 */
final readonly class Awaitable implements PromiseInterface
{
    private State $state;

    /**
     * @param State<T> $state
     *
     * @internal Use {@see Deferred} to create and resolve an awaitable.
     */
    public function __construct(State $state)
    {
        $this->state = $state;
    }

    /**
     * Iterate over the given `Awaitable`s in completion order.
     *
     * @template Tk
     * @template Tv
     *
     * @param iterable<Tk, Awaitable<Tv>> $awaitables
     *
     * @return Generator<Tk, Awaitable<Tv>, null, void>
     */
    public static function iterate(iterable $awaitables): Generator
    {
        $iterator = new AwaitableIterator();

        if (is_array($awaitables)) {
            foreach ($awaitables as $key => $awaitable) {
                $iterator->enqueue($awaitable->state, $key, $awaitable);
            }

            $iterator->complete();
        } else {
            EventLoop::defer(static function () use ($awaitables, $iterator): void {
                // @codeCoverageIgnoreStart
                try {
                    foreach ($awaitables as $key => $awaitable) {
                        $iterator->enqueue($awaitable->state, $key, $awaitable);
                    }

                    $iterator->complete();
                } catch (Throwable $throwable) {
                    $iterator->error($throwable);
                }
                // @codeCoverageIgnoreEnd
            });
        }

        do {
            $item = $iterator->consume();
            if (!$item) {
                break;
            }

            yield $item[0] => $item[1];
        } while (true);
    }

    /**
     * @template Tv
     *
     * @param Tv $result
     *
     * @return Awaitable<Tv>
     */
    public static function complete(mixed $result): self
    {
        $state = new State();
        $state->complete($result);

        return new self($state);
    }

    /**
     * @return Awaitable<never>
     */
    public static function error(Throwable $throwable): self
    {
        /** @var State<never> $state */
        $state = new State();
        $state->error($throwable);

        return new self($state);
    }

    /**
     * @return bool True if the operation has completed.
     *
     * @psalm-mutation-free
     */
    public function isComplete(): bool
    {
        return $this->state->isComplete();
    }

    /**
     * {@inheritDoc}
     *
     * @template Ts
     *
     * @param Closure(T): Ts $success
     * @param Closure(Throwable): Ts $failure
     *
     * @return Awaitable<Ts>
     */
    #[Override]
    public function then(Closure $success, Closure $failure): Awaitable
    {
        $state = new State();

        $this->state->subscribe(
            /**
             * @param null|Throwable $error
             * @param null|T $value
             */
            static function (null|Throwable $error, mixed $value) use ($state, $success, $failure): void {
                if ($error) {
                    try {
                        $state->complete($failure($error));
                    } catch (Throwable $throwable) {
                        $state->error($throwable);
                    }

                    return;
                }

                try {
                    /**
                     * @var T $value
                     */
                    $state->complete($success($value));
                } catch (Throwable $throwable) {
                    $state->error($throwable);
                }
            },
        );

        return new self($state);
    }

    /**
     * {@inheritDoc}
     *
     * @template Ts
     *
     * @param Closure(T): Ts $success
     *
     * @return Awaitable<Ts>
     */
    #[Override]
    public function map(Closure $success): Awaitable
    {
        return $this->then($success, static fn(Throwable $throwable): never => throw $throwable);
    }

    /**
     * {@inheritDoc}
     *
     * @template Ts
     *
     * @param Closure(Throwable): Ts $failure
     *
     * @return Awaitable<T|Ts>
     */
    #[Override]
    public function catch(Closure $failure): Awaitable
    {
        return $this->then(
            /**
             * @param T $value
             *
             * @return T
             */
            static fn(mixed $value): mixed => $value,
            $failure,
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param Closure(): void $always
     *
     * @return Awaitable<T>
     */
    #[Override]
    public function always(Closure $always): Awaitable
    {
        $state = new State();

        $this->state->subscribe(static function (null|Throwable $error, mixed $value) use ($state, $always): void {
            try {
                $always();

                if ($error) {
                    $state->error($error);
                } else {
                    /**
                     * @var T $value
                     */
                    $state->complete($value);
                }
                // @codeCoverageIgnoreStart
            } catch (Throwable $throwable) {
                $state->error($throwable);
                // @codeCoverageIgnoreEnd
            }
        });

        return new self($state);
    }

    /**
     * Awaits the operation to complete.
     *
     * Throws a `Throwable` if the operation fails.
     *
     * Cancelling the wait does not cancel the operation, which keeps running and can be awaited again; the
     * cancelled wait leaves nothing subscribed to the operation or to the token.
     *
     * @throws Exception\CancelledException If the cancellation token is cancelled before the operation completes.
     *
     * @return T
     */
    public function await(CancellationTokenInterface $cancellation = new NullCancellationToken()): mixed
    {
        if ($this->state->isComplete()) {
            if ($cancellation->cancellable) {
                $cancellation->throwIfCancelled();
            }

            /** @var T */
            return $this->state->getResult();
        }

        $suspension = EventLoop::getSuspension();

        if ($cancellation->cancellable) {
            $cancellation->throwIfCancelled();
        }

        /** @var bool $resolved */
        $resolved = false;

        $cancellationId = null;
        if ($cancellation->cancellable) {
            $cancellationId = $cancellation->subscribe(static function (CancelledException $e) use (
                $suspension,
                &$resolved,
            ): void {
                if (!$resolved) {
                    $resolved = true;
                    $suspension->throw($e);
                }
            });
        }

        $stateId = $this->state->subscribe(
            /**
             * @param null|Throwable $error
             * @param null|T $value
             */
            static function (null|Throwable $error, mixed $value) use ($suspension, &$resolved): void {
                if ($resolved) {
                    return;
                }

                $resolved = true;

                if ($error) {
                    $suspension->throw($error);
                } else {
                    $suspension->resume($value);
                }
            },
        );

        try {
            /** @var T */
            return $suspension->suspend();
        } finally {
            // After a cancellation, the operation is still pending: remove this wait's callback from it, so
            // that neither the awaitable nor the token keeps the waiting fiber alive.
            $this->state->unsubscribe($stateId);

            if (null !== $cancellationId) {
                $cancellation->unsubscribe($cancellationId);
            }
        }
    }

    /**
     * Do not forward unhandled errors to the event loop handler.
     */
    public function ignore(): self
    {
        $this->state->ignore();

        return $this;
    }
}
