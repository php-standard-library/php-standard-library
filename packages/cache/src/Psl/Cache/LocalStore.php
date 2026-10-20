<?php

declare(strict_types=1);

namespace Psl\Cache;

use Closure;
use Override;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\DateTime\Timestamp;
use Revolt\EventLoop;
use WeakReference;

use function array_key_first;
use function sprintf;

/**
 * In-memory LRU cache store with async-safe per-key atomicity.
 *
 * Uses {@see Async\KeyedSequence} to serialize operations per key,
 * preventing cache stampedes in concurrent fiber environments.
 *
 * Expired entries are cleaned up proactively via an event loop timer
 * that activates when TTL'd entries exist, rather than lazily on access.
 *
 * @api
 */
final class LocalStore implements StoreInterface
{
    /**
     * Cache entries: key => [value, expiresAt].
     *
     * @var array<non-empty-string, array{mixed, null|Timestamp}>
     */
    private array $entries = [];

    /**
     * LRU order tracking. Most recently used keys are at the end.
     *
     * @var array<non-empty-string, true>
     */
    private array $order = [];

    /**
     * Number of entries currently in the cache.
     */
    private int $size = 0;

    /**
     * Whether any entries have a TTL (determines if the cleanup timer should be active).
     */
    private bool $hasTtlEntries = false;

    /**
     * Event loop callback ID for the periodic TTL cleanup.
     */
    private readonly string $cleanupCallbackId;

    /**
     * @var Async\KeyedSequence<non-empty-string, array{Closure, null|Duration, bool}, mixed>
     */
    private Async\KeyedSequence $sequence;

    /**
     * @param positive-int $maxSize Maximum number of entries. Oldest entries are evicted when full.
     * @param null|Duration $cleanupInterval How often to sweep expired entries, 1 second if null.
     *
     * @throws Exception\InvalidArgumentException If $maxSize is not a positive integer.
     */
    public function __construct(
        private readonly int $maxSize = 1_000,
        null|Duration $cleanupInterval = null,
    ) {
        // @mago-expect analysis:redundant-comparison,impossible-condition,no-value - runtime guard for untyped callers
        if ($maxSize < 1) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Expected $maxSize to be a positive integer, %d given.',
                $maxSize,
            ));
        }

        $this->sequence = new Async\KeyedSequence(
            /**
             * @param non-empty-string $key
             * @param array{Closure, null|Duration, bool} $input
             */
            function (string $key, array $input): mixed {
                [$computer, $ttl, $update] = $input;

                $existing = null;
                $available = false;

                if (isset($this->entries[$key])) {
                    /** @var mixed $value */
                    [$value, $expiresAt] = $this->entries[$key];
                    if ($expiresAt === null || $expiresAt->after(Timestamp::monotonic())) {
                        /** @var mixed $existing */
                        $existing = $value;
                        $available = true;
                    } else {
                        // Expired - remove inline.
                        unset($this->entries[$key], $this->order[$key]);
                        $this->size--;
                    }
                }

                if ($available && !$update) {
                    // Cache hit on compute - touch LRU order inline.
                    unset($this->order[$key]);
                    $this->order[$key] = true;

                    return $existing;
                }

                /** @var mixed $value */
                $value = $update ? $computer($existing) : $computer();

                // Inline set: remove existing, evict LRU if full, store new entry.
                if (isset($this->entries[$key])) {
                    unset($this->entries[$key], $this->order[$key]);
                    $this->size--;
                }

                while ($this->size >= $this->maxSize) {
                    $lruKey = array_key_first($this->order);
                    if ($lruKey !== null) {
                        unset($this->entries[$lruKey], $this->order[$lruKey]);
                        $this->size--;
                    }
                }

                $expiresAt = null;
                if ($ttl !== null) {
                    $expiresAt = Timestamp::monotonic()->plus($ttl);
                    $this->hasTtlEntries = true;
                    EventLoop::enable($this->cleanupCallbackId);
                }

                $this->entries[$key] = [$value, $expiresAt];
                $this->order[$key] = true;
                $this->size++;

                return $value;
            },
        );

        $cleanupInterval ??= Duration::seconds(3);
        // The event loop holds on to the callback, so it must not hold on to the store: otherwise the
        // store could never be garbage collected, and its destructor would never cancel the callback.
        $self = WeakReference::create($this);
        $cleanup = static function (string $callbackId) use ($self): void {
            $store = $self->get();
            if (null === $store) {
                EventLoop::cancel($callbackId);

                return;
            }

            $store->sweep();

            if (!$store->hasTtlEntries) {
                EventLoop::disable($callbackId);
            }
        };

        $this->cleanupCallbackId = EventLoop::repeat($cleanupInterval->getTotalSeconds(), $cleanup);

        // Sweeping expired entries is housekeeping, it must not keep the event loop (and so the program) running.
        EventLoop::unreference($this->cleanupCallbackId);
        EventLoop::disable($this->cleanupCallbackId);
    }

    /**
     * Get a cached value by key.
     *
     * Waits for any pending compute/update on the same key to complete
     * before checking the cache. Returns the cached value if present
     * and not expired, otherwise throws.
     *
     * @param non-empty-string $key
     *
     * @throws Exception\UnavailableItemException If the key does not exist or has expired.
     *
     * @return mixed The cached value.
     */
    #[Override]
    public function get(string $key): mixed
    {
        $computer = static fn(): never => throw new Exception\UnavailableItemException(
            'No cache entry for key "' . $key . '".',
        );

        // @mago-expect analysis:never-return
        return $this->compute($key, $computer);
    }

    /**
     * Get a cached value, computing it if absent or expired.
     *
     * If the key exists and hasn't expired, the cached value is returned
     * without invoking $computer. Otherwise, $computer is called, its result
     * is stored (with optional TTL), and returned.
     *
     * Per-key atomicity via {@see Async\KeyedSequence} ensures only one fiber
     * computes the value for a given key at a time. Other fibers requesting
     * the same key wait for the first computation to complete and receive
     * the cached result. Different keys are computed in parallel.
     *
     * If the cache is full, the least recently used entry is evicted before
     * storing the new value.
     *
     * @template T
     *
     * @param non-empty-string $key
     * @param (Closure(): T) $computer
     * @param null|Duration $ttl Time to live. Null means no expiration.
     *
     * @return T
     */
    #[Override]
    public function compute(string $key, Closure $computer, null|Duration $ttl = null): mixed
    {
        /** @var T */
        return $this->sequence->waitFor($key, [$computer, $ttl, false]);
    }

    /**
     * Recompute and store a value, always invoking the computer.
     *
     * Unlike {@see compute()}, $computer is always called regardless of
     * whether the key exists. It receives the current cached value (or null
     * if absent/expired) and returns the new value to store.
     *
     * Per-key atomicity ensures the read-modify-write is atomic within
     * the current process. If the cache is full, the least recently used
     * entry is evicted before storing.
     *
     * @template T
     *
     * @param non-empty-string $key
     * @param (Closure(null|T): T) $computer Receives the old value or null.
     * @param null|Duration $ttl Time to live. Null means no expiration.
     *
     * @return T
     */
    #[Override]
    public function update(string $key, Closure $computer, null|Duration $ttl = null): mixed
    {
        /** @var T */
        return $this->sequence->waitFor($key, [$computer, $ttl, true]);
    }

    /**
     * Delete a cached entry by key.
     *
     * Waits for any pending compute/update on the same key to complete
     * before removing it. Does nothing if the key does not exist.
     *
     * @param non-empty-string $key
     */
    #[Override]
    public function delete(string $key): void
    {
        $this->sequence->waitForPending($key);

        if (isset($this->entries[$key])) {
            unset($this->entries[$key], $this->order[$key]);
            $this->size--;
        }
    }

    /**
     * Sweep all expired entries.
     */
    private function sweep(): void
    {
        $now = Timestamp::monotonic();
        $hasRemaining = false;

        foreach ($this->entries as $key => [$_, $expiresAt]) {
            if ($expiresAt === null) {
                continue;
            }

            if ($expiresAt->beforeOrAtTheSameTime($now)) {
                unset($this->entries[$key], $this->order[$key]);
                $this->size--;
            } else {
                $hasRemaining = true;
            }
        }

        $this->hasTtlEntries = $hasRemaining;
    }

    public function __destruct()
    {
        EventLoop::cancel($this->cleanupCallbackId);
    }
}
