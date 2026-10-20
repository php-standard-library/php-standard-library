<?php

declare(strict_types=1);

namespace Psl\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Cache\Exception\InvalidArgumentException;
use Psl\Cache\Exception\UnavailableItemException;
use Psl\Cache\LocalStore;
use Psl\DateTime\Duration;
use Revolt\EventLoop;
use WeakReference;

use function array_diff;
use function array_values;
use function gc_collect_cycles;

use const PHP_INT_MIN;

final class LocalStoreTest extends TestCase
{
    public function testComputeStoresAndReturns(): void
    {
        $store = new LocalStore();

        $value = $store->compute('key', static fn(): string => 'hello');

        static::assertSame('hello', $value);
    }

    public function testComputeReturnsCachedOnSecondCall(): void
    {
        $store = new LocalStore();
        $calls = 0;

        $computer = static function () use (&$calls): string {
            $calls++;
            return 'value';
        };

        $store->compute('key', $computer);
        $store->compute('key', $computer);

        static::assertSame(1, $calls);
    }

    public function testGetReturnsComputedValue(): void
    {
        $store = new LocalStore();
        $store->compute('key', static fn(): string => 'cached');

        static::assertSame('cached', $store->get('key'));
    }

    public function testGetThrowsForMissingKey(): void
    {
        $store = new LocalStore();

        $this->expectException(UnavailableItemException::class);
        $store->get('missing');
    }

    public function testDeleteRemovesEntry(): void
    {
        $store = new LocalStore();
        $store->compute('key', static fn(): string => 'value');

        $store->delete('key');

        $this->expectException(UnavailableItemException::class);
        $store->get('key');
    }

    public function testDeleteNonExistentKeyDoesNothing(): void
    {
        $store = new LocalStore();
        $store->delete('nonexistent');

        static::addToAssertionCount(1);
    }

    public function testUpdateAlwaysInvokesComputer(): void
    {
        $store = new LocalStore();
        $store->compute('counter', static fn(): int => 0);

        $result = $store->update('counter', static fn(null|int $old): int => ($old ?? 0) + 1);
        static::assertSame(1, $result);

        $result = $store->update('counter', static fn(null|int $old): int => ($old ?? 0) + 1);
        static::assertSame(2, $result);

        static::assertSame(2, $store->get('counter'));
    }

    public function testUpdateOnMissingKeyCreatesEntry(): void
    {
        $store = new LocalStore();

        $result = $store->update('new', static fn(null|int $old): int => ($old ?? 0) + 10);

        static::assertSame(10, $result);
        static::assertSame(10, $store->get('new'));
    }

    public function testLruEviction(): void
    {
        $store = new LocalStore(maxSize: 3);

        $store->compute('a', static fn(): string => 'A');
        $store->compute('b', static fn(): string => 'B');
        $store->compute('c', static fn(): string => 'C');

        $store->compute('d', static fn(): string => 'D');

        $this->expectException(UnavailableItemException::class);
        $store->get('a');
    }

    public function testLruEvictionRespectsAccessOrder(): void
    {
        $store = new LocalStore(maxSize: 3);

        $store->compute('a', static fn(): string => 'A');
        $store->compute('b', static fn(): string => 'B');
        $store->compute('c', static fn(): string => 'C');

        $store->get('a');

        $store->compute('d', static fn(): string => 'D');

        static::assertSame('A', $store->get('a'));
        static::assertSame('D', $store->get('d'));

        $this->expectException(UnavailableItemException::class);
        $store->get('b');
    }

    public function testTtlExpiration(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(50));

        $store->compute('key', static fn(): string => 'value', Duration::milliseconds(100));

        static::assertSame('value', $store->get('key'));

        Async\sleep(Duration::milliseconds(200));

        $this->expectException(UnavailableItemException::class);
        $store->get('key');
    }

    public function testTtlExpirationRecomputesOnNextAccess(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(50));
        $calls = 0;

        $store->compute(
            'key',
            static function () use (&$calls): string {
                $calls++;
                return 'v' . $calls;
            },
            Duration::milliseconds(100),
        );

        static::assertSame('v1', $store->get('key'));

        Async\sleep(Duration::milliseconds(200));

        $value = $store->compute(
            'key',
            static function () use (&$calls): string {
                $calls++;
                return 'v' . $calls;
            },
            Duration::milliseconds(100),
        );

        static::assertSame('v2', $value);
        static::assertSame(2, $calls);
    }

    public function testNoTtlNeverExpires(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(50));

        $store->compute('permanent', static fn(): string => 'forever');

        Async\sleep(Duration::milliseconds(150));

        static::assertSame('forever', $store->get('permanent'));
    }

    public function testMaxSizeOne(): void
    {
        $store = new LocalStore(maxSize: 1);

        $store->compute('a', static fn(): string => 'A');
        $store->compute('b', static fn(): string => 'B');

        static::assertSame('B', $store->get('b'));

        $this->expectException(UnavailableItemException::class);
        $store->get('a');
    }

    /**
     * A non-positive max size used to make the eviction loop spin forever on the first store.
     */
    #[DataProvider('provideNonPositiveMaxSizes')]
    public function testNonPositiveMaxSizeThrows(int $maxSize): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected $maxSize to be a positive integer, ' . $maxSize . ' given.');

        new LocalStore(maxSize: $maxSize);
    }

    public static function provideNonPositiveMaxSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'min' => [PHP_INT_MIN];
    }

    public function testComputeWithDifferentKeys(): void
    {
        $store = new LocalStore();

        $store->compute('x', static fn(): int => 1);
        $store->compute('y', static fn(): int => 2);
        $store->compute('z', static fn(): int => 3);

        static::assertSame(1, $store->get('x'));
        static::assertSame(2, $store->get('y'));
        static::assertSame(3, $store->get('z'));
    }

    public function testUpdateWithTtl(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(50));

        $store->update('key', static fn(null|string $old): string => 'value', Duration::milliseconds(100));

        static::assertSame('value', $store->get('key'));

        Async\sleep(Duration::milliseconds(200));

        $this->expectException(UnavailableItemException::class);
        $store->get('key');
    }

    public function testConcurrentComputeOnSameKeyDeduplicates(): void
    {
        $store = new LocalStore();
        $calls = 0;

        $computer = static function () use (&$calls): string {
            $calls++;
            Async\sleep(Duration::milliseconds(50));
            return 'result';
        };

        [$a, $b] = Async\concurrently([
            static fn() => $store->compute('key', $computer),
            static fn() => $store->compute('key', $computer),
        ]);

        static::assertSame('result', $a);
        static::assertSame('result', $b);
        static::assertSame(1, $calls);
    }

    public function testConcurrentComputeOnDifferentKeysRunsInParallel(): void
    {
        $store = new LocalStore();
        $calls = 0;

        $computer = static function () use (&$calls): string {
            $calls++;
            return 'result';
        };

        Async\concurrently([
            static fn() => $store->compute('a', $computer),
            static fn() => $store->compute('b', $computer),
        ]);

        static::assertSame(2, $calls);
    }

    public function testOverwriteExistingKeyUpdatesLruPosition(): void
    {
        $store = new LocalStore(maxSize: 3);

        $store->compute('a', static fn(): string => 'A');
        $store->compute('b', static fn(): string => 'B');
        $store->compute('c', static fn(): string => 'C');

        $store->update('a', static fn(null|string $old): string => 'A2');

        $store->compute('d', static fn(): string => 'D');

        static::assertSame('A2', $store->get('a'));

        $this->expectException(UnavailableItemException::class);
        $store->get('b');
    }

    public function testDefaultMaxSizeIsExactly1000(): void
    {
        $store = new LocalStore();

        for ($i = 1; $i <= 1000; $i++) {
            $store->compute('key' . $i, static fn() => $i);
        }

        static::assertSame(1, $store->get('key1'));
        static::assertSame(1000, $store->get('key1000'));

        $store->compute('key1001', static fn() => 1001);

        static::assertSame(1001, $store->get('key1001'));

        $this->expectException(UnavailableItemException::class);
        $store->get('key2');
    }

    public function testLruOrderIsTrackedOnCacheHit(): void
    {
        $store = new LocalStore(maxSize: 3);

        $store->compute('a', static fn(): string => 'A');
        $store->compute('b', static fn(): string => 'B');
        $store->compute('c', static fn(): string => 'C');

        $store->compute('a', static fn(): string => 'should not be called');

        $store->compute('d', static fn(): string => 'D');

        static::assertSame('A', $store->get('a'));
        static::assertSame('C', $store->get('c'));
        static::assertSame('D', $store->get('d'));

        $this->expectException(UnavailableItemException::class);
        $store->get('b');
    }

    public function testHasTtlEntriesActivatesCleanupTimer(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(50));

        $store->compute('permanent', static fn(): string => 'stays');
        $store->compute('expires', static fn(): string => 'goes', Duration::milliseconds(80));

        static::assertSame('goes', $store->get('expires'));

        Async\sleep(Duration::milliseconds(200));

        static::assertSame('stays', $store->get('permanent'));

        $this->expectException(UnavailableItemException::class);
        $store->get('expires');
    }

    public function testCleanupTimerIsEnabledAfterTtlEntry(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(50));

        $store->compute('short', static fn(): string => 'a', Duration::milliseconds(60));
        $store->compute('long', static fn(): string => 'b', Duration::milliseconds(300));

        Async\sleep(Duration::milliseconds(150));

        static::assertSame('b', $store->get('long'));

        $this->expectException(UnavailableItemException::class);
        $store->get('short');
    }

    public function testCleanupTimerDisablesWhenNoTtlEntriesRemain(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(50));

        $store->compute('temp', static fn(): string => 'val', Duration::milliseconds(60));

        Async\sleep(Duration::milliseconds(200));

        $store->compute('permanent', static fn(): string => 'stays');

        Async\sleep(Duration::milliseconds(150));
        static::assertSame('stays', $store->get('permanent'));

        $store->compute('temp2', static fn(): string => 'val2', Duration::milliseconds(60));
        static::assertSame('val2', $store->get('temp2'));

        Async\sleep(Duration::milliseconds(200));

        $this->expectException(UnavailableItemException::class);
        $store->get('temp2');
    }

    public function testSweepRemovesExpiredEntries(): void
    {
        $store = new LocalStore(maxSize: 5, cleanupInterval: Duration::milliseconds(50));

        $store->compute('a', static fn(): string => 'A', Duration::milliseconds(60));
        $store->compute('b', static fn(): string => 'B', Duration::milliseconds(60));
        $store->compute('c', static fn(): string => 'C', Duration::milliseconds(60));

        Async\sleep(Duration::milliseconds(200));

        $store->compute('d', static fn(): string => 'D');
        $store->compute('e', static fn(): string => 'E');
        $store->compute('f', static fn(): string => 'F');
        $store->compute('g', static fn(): string => 'G');
        $store->compute('h', static fn(): string => 'H');

        static::assertSame('D', $store->get('d'));
        static::assertSame('H', $store->get('h'));
    }

    private function createStoreWithShortAndLongTtlEntries(): LocalStore
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(50));

        $store->compute('short', static fn(): string => 'A', Duration::milliseconds(60));
        $store->compute('long', static fn(): string => 'B', Duration::milliseconds(300));

        Async\sleep(Duration::milliseconds(150));

        return $store;
    }

    public function testTimerStaysActiveWhileTtlEntriesRemainShortExpires(): void
    {
        $store = $this->createStoreWithShortAndLongTtlEntries();

        $this->expectException(UnavailableItemException::class);
        $store->get('short');
    }

    public function testTimerStaysActiveWhileTtlEntriesRemainLongSurvivesThenExpires(): void
    {
        $store = $this->createStoreWithShortAndLongTtlEntries();

        static::assertSame('B', $store->get('long'));

        Async\sleep(Duration::milliseconds(250));

        $this->expectException(UnavailableItemException::class);
        $store->get('long');
    }

    public function testCustomCleanupIntervalIsRespected(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(40));

        $store->compute('key', static fn(): string => 'val', Duration::milliseconds(50));

        Async\sleep(Duration::milliseconds(150));

        $this->expectException(UnavailableItemException::class);
        $store->get('key');
    }

    public function testDefaultCleanupIntervalIsThreeSeconds(): void
    {
        $store = new LocalStore();

        $store->compute('key', static fn(): string => 'val', Duration::milliseconds(50));

        Async\sleep(Duration::milliseconds(200));

        $this->expectException(UnavailableItemException::class);
        $store->get('key');
    }

    public function testGetErrorMessageContainsKey(): void
    {
        $store = new LocalStore();

        $this->expectException(UnavailableItemException::class);
        $this->expectExceptionMessage('No cache entry for key "my-special-key".');
        $store->get('my-special-key');
    }

    public function testDeleteWaitsForPendingCompute(): void
    {
        $store = new LocalStore();
        $computed = false;

        Async\concurrently([
            'compute' => static function () use ($store, &$computed): string {
                return $store->compute('key', static function () use (&$computed): string {
                    Async\sleep(Duration::milliseconds(50));
                    $computed = true;
                    return 'value';
                });
            },
            'delete' => static function () use ($store): void {
                Async\sleep(Duration::milliseconds(10));
                $store->delete('key');
            },
        ]);

        static::assertTrue($computed);

        $this->expectException(UnavailableItemException::class);
        $store->get('key');
    }

    public function testSizeTrackingAfterDelete(): void
    {
        $store = new LocalStore(maxSize: 3);

        $store->compute('a', static fn(): string => 'A');
        $store->compute('b', static fn(): string => 'B');
        $store->compute('c', static fn(): string => 'C');

        $store->delete('b');

        $store->compute('d', static fn(): string => 'D');

        static::assertSame('A', $store->get('a'));
        static::assertSame('C', $store->get('c'));
        static::assertSame('D', $store->get('d'));
    }

    private function createStoreWithMixedTtlEntries(): LocalStore
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(50));

        $store->compute('expire1', static fn(): string => 'A', Duration::milliseconds(60));
        $store->compute('expire2', static fn(): string => 'B', Duration::milliseconds(60));
        $store->compute('keep', static fn(): string => 'C', Duration::milliseconds(500));
        $store->compute('permanent', static fn(): string => 'D');

        Async\sleep(Duration::milliseconds(200));

        return $store;
    }

    public function testSweepKeepsUnexpiredEntries(): void
    {
        $store = $this->createStoreWithMixedTtlEntries();

        static::assertSame('C', $store->get('keep'));
        static::assertSame('D', $store->get('permanent'));
    }

    public function testSweepRemovesFirstExpiredEntry(): void
    {
        $store = $this->createStoreWithMixedTtlEntries();

        $this->expectException(UnavailableItemException::class);
        $store->get('expire1');
    }

    public function testSweepRemovesSecondExpiredEntry(): void
    {
        $store = $this->createStoreWithMixedTtlEntries();

        $this->expectException(UnavailableItemException::class);
        $store->get('expire2');
    }

    public function testSizeIsCorrectAfterSweepRemovesEntries(): void
    {
        $store = new LocalStore(maxSize: 4, cleanupInterval: Duration::milliseconds(50));

        $store->compute('a', static fn(): string => 'A', Duration::milliseconds(60));
        $store->compute('b', static fn(): string => 'B', Duration::milliseconds(60));
        $store->compute('c', static fn(): string => 'C', Duration::milliseconds(60));
        $store->compute('d', static fn(): string => 'D', Duration::milliseconds(60));

        Async\sleep(Duration::milliseconds(200));

        $store->compute('e', static fn(): string => 'E');
        $store->compute('f', static fn(): string => 'F');
        $store->compute('g', static fn(): string => 'G');
        $store->compute('h', static fn(): string => 'H');

        static::assertSame('E', $store->get('e'));
        static::assertSame('F', $store->get('f'));
        static::assertSame('G', $store->get('g'));
        static::assertSame('H', $store->get('h'));
    }

    public function testDeleteTtlEntryDecrementsSize(): void
    {
        $store = new LocalStore(maxSize: 2);

        $store->compute('a', static fn(): string => 'A', Duration::milliseconds(500));
        $store->compute('b', static fn(): string => 'B');

        $store->delete('a');

        $store->compute('c', static fn(): string => 'C');

        static::assertSame('B', $store->get('b'));
        static::assertSame('C', $store->get('c'));
    }

    public function testExpiredEntryRemovalDecrementsSize(): void
    {
        $store = new LocalStore(maxSize: 3, cleanupInterval: Duration::seconds(60));

        $store->compute('a', static fn(): string => 'A', Duration::milliseconds(50));
        $store->compute('b', static fn(): string => 'B');
        $store->compute('c', static fn(): string => 'C');

        Async\sleep(Duration::milliseconds(100));

        $store->compute('a', static fn(): string => 'A2');

        $store->compute('d', static fn(): string => 'D');

        static::assertSame('A2', $store->get('a'));
        static::assertSame('C', $store->get('c'));
        static::assertSame('D', $store->get('d'));
    }

    private function createStoreWithPermanentBetweenExpiring(): LocalStore
    {
        $store = new LocalStore(maxSize: 10, cleanupInterval: Duration::milliseconds(50));

        $store->compute('expire1', static fn(): string => 'A', Duration::milliseconds(60));
        $store->compute('permanent', static fn(): string => 'B');
        $store->compute('expire2', static fn(): string => 'C', Duration::milliseconds(60));

        Async\sleep(Duration::milliseconds(200));

        return $store;
    }

    public function testSweepContinuesPastPermanentEntriesPermanentSurvives(): void
    {
        $store = $this->createStoreWithPermanentBetweenExpiring();

        static::assertSame('B', $store->get('permanent'));
    }

    public function testSweepContinuesPastPermanentEntriesExpire1Removed(): void
    {
        $store = $this->createStoreWithPermanentBetweenExpiring();

        $this->expectException(UnavailableItemException::class);
        $store->get('expire1');
    }

    public function testSweepContinuesPastPermanentEntriesExpire2Removed(): void
    {
        $store = $this->createStoreWithPermanentBetweenExpiring();

        $this->expectException(UnavailableItemException::class);
        $store->get('expire2');
    }

    public function testCustomCleanupIntervalOverridesDefault(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::seconds(1));

        $store->compute('key', static fn(): string => 'val', Duration::milliseconds(50));

        Async\sleep(Duration::milliseconds(1200));

        $this->expectException(UnavailableItemException::class);
        $store->get('key');
    }

    public function testSweepDisablesTimerWhenAllTtlEntriesExpire(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(50));

        $store->compute('a', static fn(): string => 'A', Duration::milliseconds(60));

        Async\sleep(Duration::milliseconds(200));

        $store->compute('b', static fn(): string => 'B', Duration::milliseconds(60));
        static::assertSame('B', $store->get('b'));

        Async\sleep(Duration::milliseconds(200));

        $this->expectException(UnavailableItemException::class);
        $store->get('b');
    }

    public function testTtlEntryEnablesCleanupCallbackAndSweeps(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(50));

        $store->compute('no-ttl', static fn(): string => 'permanent');

        $store->compute('with-ttl', static fn(): string => 'temporary', Duration::milliseconds(80));

        Async\sleep(Duration::milliseconds(200));

        static::assertSame('permanent', $store->get('no-ttl'));

        $this->expectException(UnavailableItemException::class);
        $store->get('with-ttl');
    }

    public function testNullCleanupIntervalDefaultsToThreeSeconds(): void
    {
        $store = new LocalStore(cleanupInterval: null);

        $store->compute('key', static fn(): string => 'val', Duration::milliseconds(80));

        Async\sleep(Duration::milliseconds(200));

        $this->expectException(UnavailableItemException::class);
        $store->get('key');
    }

    private function createStoreAfterFirstTtlExpires(): LocalStore
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(40));

        $store->compute('first', static fn(): string => 'A', Duration::milliseconds(60));

        Async\sleep(Duration::milliseconds(200));

        return $store;
    }

    public function testHasTtlEntriesFlagEnablesSweepFirstEntryExpires(): void
    {
        $store = $this->createStoreAfterFirstTtlExpires();

        $this->expectException(UnavailableItemException::class);
        $store->get('first');
    }

    public function testHasTtlEntriesFlagEnablesSweepForNewTtlEntryAfterAllExpired(): void
    {
        $store = $this->createStoreAfterFirstTtlExpires();

        $store->compute('second', static fn(): string => 'B', Duration::milliseconds(60));
        static::assertSame('B', $store->get('second'));

        Async\sleep(Duration::milliseconds(200));

        $this->expectException(UnavailableItemException::class);
        $store->get('second');
    }

    public function testTtlEntryImmediatelyEnablesTimerForSweep(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(30));

        $store->compute('no-ttl-1', static fn(): string => 'permanent1');
        $store->compute('no-ttl-2', static fn(): string => 'permanent2');

        $store->compute('ttl-entry', static fn(): string => 'temporary', Duration::milliseconds(50));

        static::assertSame('temporary', $store->get('ttl-entry'));

        Async\sleep(Duration::milliseconds(150));

        static::assertSame('permanent1', $store->get('no-ttl-1'));
        static::assertSame('permanent2', $store->get('no-ttl-2'));

        $this->expectException(UnavailableItemException::class);
        $store->get('ttl-entry');
    }

    public function testMultipleTtlEntriesSweptWhenExpiredKeyA(): void
    {
        $store = $this->createStoreWithThreeExpiredTtlEntries();

        $this->expectException(UnavailableItemException::class);
        $store->get('a');
    }

    public function testMultipleTtlEntriesSweptWhenExpiredKeyB(): void
    {
        $store = $this->createStoreWithThreeExpiredTtlEntries();

        $this->expectException(UnavailableItemException::class);
        $store->get('b');
    }

    public function testMultipleTtlEntriesSweptWhenExpiredKeyC(): void
    {
        $store = $this->createStoreWithThreeExpiredTtlEntries();

        $this->expectException(UnavailableItemException::class);
        $store->get('c');
    }

    private function createStoreWithThreeExpiredTtlEntries(): LocalStore
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(30));

        $store->compute('a', static fn(): string => 'A', Duration::milliseconds(50));
        $store->compute('b', static fn(): string => 'B', Duration::milliseconds(50));
        $store->compute('c', static fn(): string => 'C', Duration::milliseconds(50));

        Async\sleep(Duration::milliseconds(150));

        return $store;
    }

    public function testCleanupTimerStartsDisabledAndOnlyActivatesOnTtlEntry(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(30));

        $store->compute('permanent', static fn(): string => 'value');

        Async\sleep(Duration::milliseconds(100));

        static::assertSame('value', $store->get('permanent'));

        $store->compute('temp', static fn(): string => 'gone', Duration::milliseconds(50));

        Async\sleep(Duration::milliseconds(150));

        static::assertSame('value', $store->get('permanent'));

        $this->expectException(UnavailableItemException::class);
        $store->get('temp');
    }

    public function testCustomCleanupIntervalOverridesDefaultCoalesceThreeSeconds(): void
    {
        $customInterval = Duration::milliseconds(25);
        $store = new LocalStore(cleanupInterval: $customInterval);

        $store->compute('key', static fn(): string => 'val', Duration::milliseconds(40));

        Async\sleep(Duration::milliseconds(120));

        $this->expectException(UnavailableItemException::class);
        $store->get('key');
    }

    public function testDefaultCleanupIntervalCoalesceAppliesWhenNullPassed(): void
    {
        $store = new LocalStore(cleanupInterval: null);

        $store->compute('a', static fn(): string => 'A', Duration::milliseconds(40));

        Async\sleep(Duration::milliseconds(100));

        $this->expectException(UnavailableItemException::class);
        $store->get('a');
    }

    public function testExplicitCleanupIntervalIsNotOverriddenByDefault(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::seconds(10));

        $store->compute('key', static fn(): string => 'val', Duration::milliseconds(40));

        Async\sleep(Duration::milliseconds(100));

        $this->expectException(UnavailableItemException::class);
        $store->get('key');
    }

    public function testCleanupCallbackStartsDisabledBeforeTtlEntries(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(30));

        for ($i = 0; $i < 5; $i++) {
            $store->compute('key' . $i, static fn() => $i);
        }

        Async\sleep(Duration::milliseconds(100));

        for ($i = 0; $i < 5; $i++) {
            static::assertSame($i, $store->get('key' . $i));
        }
    }

    private function createStoreWithPermanentAndExpiredTtl(): LocalStore
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(30));

        $store->compute('p1', static fn(): string => 'P1');
        $store->compute('p2', static fn(): string => 'P2');

        $store->compute('ttl1', static fn(): string => 'T1', Duration::milliseconds(50));

        Async\sleep(Duration::milliseconds(150));

        return $store;
    }

    public function testTtlEntryAfterPermanentEntriesPermanentsSurvive(): void
    {
        $store = $this->createStoreWithPermanentAndExpiredTtl();

        static::assertSame('P1', $store->get('p1'));
        static::assertSame('P2', $store->get('p2'));
    }

    public function testTtlEntryAfterPermanentEntriesFirstTtlExpires(): void
    {
        $store = $this->createStoreWithPermanentAndExpiredTtl();

        $this->expectException(UnavailableItemException::class);
        $store->get('ttl1');
    }

    public function testTtlEntryAfterPermanentEntriesSecondTtlExpires(): void
    {
        $store = $this->createStoreWithPermanentAndExpiredTtl();

        $store->compute('ttl2', static fn(): string => 'T2', Duration::milliseconds(50));
        static::assertSame('T2', $store->get('ttl2'));

        Async\sleep(Duration::milliseconds(150));

        $this->expectException(UnavailableItemException::class);
        $store->get('ttl2');
    }

    private function createStoreAfterTempExpires(): LocalStore
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(30));

        $store->compute('temp1', static fn(): string => 'A', Duration::milliseconds(50));

        Async\sleep(Duration::milliseconds(150));

        return $store;
    }

    public function testSweepResetsHasTtlFlagFirstTempExpires(): void
    {
        $store = $this->createStoreAfterTempExpires();

        $this->expectException(UnavailableItemException::class);
        $store->get('temp1');
    }

    public function testSweepResetsHasTtlFlagAndReenablesOnNextTtlEntry(): void
    {
        $store = $this->createStoreAfterTempExpires();

        $store->compute('temp2', static fn(): string => 'B', Duration::milliseconds(50));
        static::assertSame('B', $store->get('temp2'));

        Async\sleep(Duration::milliseconds(150));

        $this->expectException(UnavailableItemException::class);
        $store->get('temp2');
    }

    public function testUpdateWithTtlEnablesSweep(): void
    {
        $store = new LocalStore(cleanupInterval: Duration::milliseconds(30));

        $store->update('key', static fn(null|string $old): string => 'val', Duration::milliseconds(50));

        static::assertSame('val', $store->get('key'));

        Async\sleep(Duration::milliseconds(150));

        $this->expectException(UnavailableItemException::class);
        $store->get('key');
    }

    public function testStoreIsGarbageCollectedAndCancelsItsCleanupTimer(): void
    {
        $before = EventLoop::getIdentifiers();

        $store = new LocalStore(cleanupInterval: Duration::milliseconds(10));
        // A TTL entry enables the cleanup timer.
        $store->compute('key', static fn(): string => 'value', Duration::seconds(10));

        $created = array_values(array_diff(EventLoop::getIdentifiers(), $before));
        static::assertCount(1, $created);

        $reference = WeakReference::create($store);
        unset($store);
        gc_collect_cycles();

        static::assertNull($reference->get());
        static::assertNotContains($created[0], EventLoop::getIdentifiers());
    }

    public function testCleanupTimerDoesNotKeepTheEventLoopAlive(): void
    {
        $before = EventLoop::getIdentifiers();

        $store = new LocalStore(cleanupInterval: Duration::milliseconds(10));
        $store->compute('key', static fn(): string => 'value', Duration::hours(1));

        $created = array_values(array_diff(EventLoop::getIdentifiers(), $before));
        static::assertCount(1, $created);
        static::assertTrue(EventLoop::isEnabled($created[0]));
        static::assertFalse(EventLoop::isReferenced($created[0]));
        static::assertSame('value', $store->get('key'));
    }
}
