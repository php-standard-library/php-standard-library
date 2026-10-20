<?php

declare(strict_types=1);

namespace Psl\Cache;

use Closure;
use Psl\DateTime\Duration;

/**
 * An async-safe cache store with per-key atomicity.
 *
 * All operations on the same key are serialized - if two fibers call
 * {@see compute()} with the same key concurrently, the second waits for
 * the first to finish. This prevents cache stampedes.
 *
 * This guarantee is only provided within the current process. Concurrent
 * access from separate processes is not atomic.
 *
 * @api
 */
interface StoreInterface
{
    /**
     * Get a value associated with the given key.
     *
     * @param non-empty-string $key
     *
     * @throws Exception\InvalidArgumentException If the key is invalid.
     * @throws Exception\UnavailableItemException If the key does not exist or has expired.
     *
     * @return mixed The cached value.
     */
    public function get(string $key): mixed;

    /**
     * Get a value, computing it if absent.
     *
     * If the key exists and hasn't expired, the cached value is returned.
     * Otherwise, $computer is called, its result is stored with the optional
     * TTL, and returned.
     *
     * Per-key atomicity ensures only one fiber computes the value for a given
     * key at a time - other fibers requesting the same key wait for the result.
     *
     * @template T
     *
     * @param non-empty-string $key
     * @param (Closure(): T) $computer
     * @param null|Duration $ttl Time to live. Null means no expiration; zero expires immediately.
     *
     * @throws Exception\InvalidArgumentException If the key is invalid.
     *
     * @return T
     */
    public function compute(string $key, Closure $computer, null|Duration $ttl = null): mixed;

    /**
     * Update a value, always invoking the computer.
     *
     * Unlike {@see compute()}, the $computer is always called. It receives
     * the current value (or null if absent) and returns the new value.
     *
     * @template T
     *
     * @param non-empty-string $key
     * @param (Closure(null|T): T) $computer
     * @param null|Duration $ttl Time to live. Null means no expiration; zero expires immediately.
     *
     * @throws Exception\InvalidArgumentException If the key is invalid.
     *
     * @return T
     */
    public function update(string $key, Closure $computer, null|Duration $ttl = null): mixed;

    /**
     * Delete an item from the cache.
     *
     * Does nothing if the key does not exist.
     *
     * @param non-empty-string $key
     *
     * @throws Exception\InvalidArgumentException If the key is invalid.
     */
    public function delete(string $key): void;
}
