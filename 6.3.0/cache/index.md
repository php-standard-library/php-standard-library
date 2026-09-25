# Cache

The `Cache` component provides an async-safe in-memory LRU cache with per-key atomicity. It prevents cache stampedes in concurrent fiber environments - if two fibers request the same key simultaneously, only one computes the value while the other waits.

## Basic Usage

`compute()` is the primary API - get a cached value or compute it if absent. `update()` always recomputes, receiving the old value.

```php
use Psl\Cache;
use Psl\DateTime\Duration;
use Psl\IO;

$store = new Cache\LocalStore(maxSize: 100);

// compute() returns cached value or computes it
$value = $store->compute('greeting', static fn(): string => 'Hello, World!');

IO\write_line('%s', $value);

// Second call returns cached value - computer is not invoked
$value = $store->compute('greeting', static fn(): string => 'This is never called');

IO\write_line('%s', $value); // Still "Hello, World!"

// With TTL - entry expires after 5 minutes
$store->compute(
    'user:42',
    /** @return array{name: string} */
    static fn(): array => ['name' => 'Alice'],
    Duration::minutes(5),
);

// update() always invokes the computer with the old value
$store->compute('counter', static fn(): int => 0);
$store->update('counter', static fn(null|int $old): int => ($old ?? 0) + 1);

/** @var int $counter */
$counter = $store->get('counter');
IO\write_line('Counter: %d', $counter);
```

## Async Safety

Operations on the same key are serialized via `KeyedSequence`. Different keys run in parallel with no blocking.

```php
use Psl\Async;
use Psl\Cache;
use Psl\DateTime\Duration;
use Psl\IO;

$store = new Cache\LocalStore();

// Two fibers requesting the same key concurrently.
// KeyedSequence ensures only one computes - the other waits and gets the cached result.
[$a, $b] = Async\concurrently([
    static fn(): string => $store->compute('expensive', static function (): string {
        Async\sleep(Duration::milliseconds(100));

        return 'computed once';
    }),
    static fn(): string => $store->compute('expensive', static fn(): string => 'this is never called'),
]);

IO\write_line('Fiber A: %s', $a); // "computed once"
IO\write_line('Fiber B: %s', $b); // "computed once" - got cached result

// Different keys run in parallel - no blocking
Async\concurrently([
    static fn(): string => $store->compute('key-1', static fn(): string => 'value-1'),
    static fn(): string => $store->compute('key-2', static fn(): string => 'value-2'),
]);
```

## LRU Eviction

`LocalStore` maintains a bounded cache with configurable maximum size. When full, the least recently used entry is evicted. Accessed entries are promoted to most-recently-used.

## TTL Expiration

Entries with a TTL are proactively cleaned up via an event loop timer, not lazily on access. The timer activates when TTL'd entries exist and disables itself when none remain, adding zero overhead when no entries have expiration.

## NullStore

`NullStore` implements `StoreInterface` but never caches - every `compute()` invokes the computer, every `get()` throws. Useful for testing or disabling caching without changing calling code.

See [src/Psl/Cache/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/cache/src/Psl/Cache/) for the full API.
