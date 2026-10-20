# Cache

The `Cache` component provides an async-safe in-memory LRU cache with per-key atomicity. It prevents cache stampedes in concurrent fiber environments - if two fibers request the same key simultaneously, only one computes the value while the other waits.

## Basic Usage

`compute()` is the primary API - get a cached value or compute it if absent. `update()` always recomputes, receiving the old value.

@example('other/cache-basic.php')

## Async Safety

Operations on the same key are serialized via `KeyedSequence`. Different keys run in parallel with no blocking.

@example('other/cache-async.php')

## LRU Eviction

`LocalStore` maintains a bounded cache with configurable maximum size. When full, the least recently used entry is evicted. Accessed entries are promoted to most-recently-used.

## TTL Expiration

A `null` TTL means no expiration. A zero TTL expires immediately: `compute()` or `update()` returns its result, but later reads cannot reuse it.

An expired entry is never returned: it is removed when it is accessed, and an event loop timer also sweeps expired entries proactively so they do not linger in memory. The timer runs every 3 seconds by default (configurable with the `$cleanupInterval` constructor argument), activates when TTL'd entries exist and disables itself when none remain, adding zero overhead when no entries have expiration. It never keeps the event loop running on its own.

`LocalStore` may keep expired entries until the next access or cleanup sweep. Those entries use cache slots and may evict older entries until removed.

## NullStore

`NullStore` implements `StoreInterface` but never caches - every `compute()` invokes the computer, every `get()` throws. Useful for testing or disabling caching without changing calling code.

See `src/Psl/Cache/` for the full API.
