<?php

declare(strict_types=1);

namespace Psl\Async\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl;
use Psl\Async;
use Psl\DateTime;

use function max;

final class KeyedSemaphoreTest extends TestCase
{
    public function testItCallsTheOperation(): void
    {
        $ks = new Async\KeyedSemaphore(1, static function (string $key, int $input): int {
            static::assertSame('one', $key);

            return $input * 2;
        });

        static::assertSame(4, $ks->waitFor('one', 2));
    }

    public function testSequenceOperationWaitsForPendingOperationsWhenLimitIsNotReached(): void
    {
        $spy = new Psl\Ref([]);

        /**
         * @var Async\KeyedSemaphore<string, array{time: ?DateTime\Duration, value: string}, void>
         */
        $ks = new Async\KeyedSemaphore(1, static function (string $key, array $data) use ($spy): void {
            static::assertSame('operation', $key);

            if (null !== $data['time']) {
                Async\sleep($data['time']);
            }

            $spy->value[] = $data['value'];
        });

        Async\run(static fn(): null => $ks->waitFor('operation', [
            'time' => DateTime\Duration::milliseconds(3),
            'value' => 'a',
        ]))->ignore();
        Async\run(static fn(): null => $ks->waitFor('operation', [
            'time' => DateTime\Duration::milliseconds(4),
            'value' => 'b',
        ]))->ignore();
        Async\run(static fn(): null => $ks->waitFor('operation', [
            'time' => DateTime\Duration::milliseconds(5),
            'value' => 'c',
        ]))->ignore();
        $last = Async\run(static fn(): null => $ks->waitFor('operation', [
            'time' => null,
            'value' => 'd',
        ]));
        $last->await();

        static::assertSame(['a', 'b', 'c', 'd'], $spy->value);
    }

    public function testOperationWaitsForPendingOperationsWhenLimitIsNotReached(): void
    {
        $spy = new Psl\Ref([]);

        /**
         * @var Async\KeyedSemaphore<string, array{time: ?DateTime\Duration, value: string}, void>
         */
        $ks = new Async\KeyedSemaphore(2, static function (string $_, array $data) use ($spy): void {
            if (null !== $data['time']) {
                Async\sleep($data['time']);
            }

            $spy->value[] = $data['value'];
        });

        Async\run(static fn(): null => $ks->waitFor('key', [
            'time' => DateTime\Duration::milliseconds(3),
            'value' => 'a',
        ]))->ignore();
        Async\run(static fn(): null => $ks->waitFor('key', [
            'time' => DateTime\Duration::milliseconds(4),
            'value' => 'b',
        ]))->ignore();
        $beforeLast = Async\run(static fn(): null => $ks->waitFor('key', [
            'time' => DateTime\Duration::milliseconds(5),
            'value' => 'c',
        ]));
        Async\run(static fn(): null => $ks->waitFor('key', [
            'time' => null,
            'value' => 'd',
        ]))->ignore();

        $beforeLast->await();

        static::assertSame(['a', 'b', 'd', 'c'], $spy->value);
    }

    public function testOperationIsStartedIfLimitIsNotReached(): void
    {
        $spy = new Psl\Ref([]);

        /**
         * @var Async\KeyedSemaphore<string, string, void>
         */
        $ks = new Async\KeyedSemaphore(1, static function (string $_, string $input) use ($spy): void {
            $spy->value[] = $input;

            Async\sleep(DateTime\Duration::milliseconds(2));
        });

        $awaitable = Async\run(static fn(): null => $ks->waitFor('x', 'hello'));

        Async\later();

        static::assertSame(['hello'], $spy->value);

        $awaitable->await();
    }

    public function testOperationIsNotStartedIfLimitIsReached(): void
    {
        $spy = new Psl\Ref([]);

        /**
         * @var Async\KeyedSemaphore<string, string, void>
         */
        $semaphore = new Async\KeyedSemaphore(1, static function (string $_, string $input) use ($spy): void {
            $spy->value[] = $input;

            Async\sleep(DateTime\Duration::milliseconds(2));
        });

        Async\run(static fn(): null => $semaphore->waitFor('x', 'hello'))->ignore();
        $awaitable = Async\run(static fn(): null => $semaphore->waitFor('x', 'world'));

        Async\sleep(DateTime\Duration::milliseconds(1));

        static::assertNotContains('world', $spy->value);

        $awaitable->await();
    }

    public function testCancelingTheSemaphoreAllowsForFutureOperations(): void
    {
        /**
         * @var Async\KeyedSemaphore<string, string, string>
         */
        $semaphore = new Async\KeyedSemaphore(1, static fn(string $_, string $input): string => $input);

        $semaphore->cancelAll(new Async\Exception\TimeoutException('The semaphore is destroyed.'));

        static::assertSame('hello', $semaphore->waitFor('x', 'hello'));
    }

    public function testCancelPendingOperationsButNotTheOngoingOne(): void
    {
        /**
         * @var Async\KeyedSemaphore<string, string, string>
         */
        $ks = new Async\KeyedSemaphore(1, static function (string $_, string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(40));

            return $input;
        });

        $one = Async\run(static fn(): string => $ks->waitFor('foo', 'one'));
        $two = Async\run(static fn(): string => $ks->waitFor('foo', 'two'));

        Async\sleep(DateTime\Duration::milliseconds(10));

        $ks->cancel('foo', new Async\Exception\TimeoutException('The semaphore is destroyed.'));

        static::assertSame('one', $one->await());

        $this->expectException(Async\Exception\TimeoutException::class);
        $this->expectExceptionMessage('The semaphore is destroyed.');

        $two->await();
    }

    public function testCancelAllPendingOperations(): void
    {
        /**
         * @var Async\KeyedSemaphore<string, string, string>
         */
        $ks = new Async\KeyedSemaphore(1, static function (string $_, string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(40));

            return $input;
        });

        $ingoing = [
            Async\run(static fn(): string => $ks->waitFor('foo', 'ingoing')),
            Async\run(static fn(): string => $ks->waitFor('bar', 'ingoing')),
            Async\run(static fn(): string => $ks->waitFor('baz', 'ingoing')),
        ];

        $pending = [
            Async\run(static fn(): string => $ks->waitFor('foo', 'pending')),
            Async\run(static fn(): string => $ks->waitFor('bar', 'pending')),
            Async\run(static fn(): string => $ks->waitFor('baz', 'pending')),
        ];

        Async\sleep(DateTime\Duration::milliseconds(10));

        $ks->cancelAll(new Async\Exception\TimeoutException('The semaphore is destroyed.'));

        foreach ($ingoing as $awaitable) {
            static::assertSame('ingoing', $awaitable->await());
        }

        foreach ($pending as $awaitable) {
            try {
                static::assertSame('ingoing', $awaitable->await());
            } catch (Async\Exception\TimeoutException $e) {
                static::assertSame('The semaphore is destroyed.', $e->getMessage());
            }
        }
    }

    public function testSemaphoreStatus(): void
    {
        /**
         * @var Async\KeyedSemaphore<string, string, string>
         */
        $ks = new Async\KeyedSemaphore(1, static function (string $_, string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(40));

            return $input;
        });

        $key = 'foo';

        $one = Async\run(static fn(): string => $ks->waitFor($key, 'one'));
        $two = Async\run(static fn(): string => $ks->waitFor($key, 'two'));
        static::assertSame(0, $ks->getOngoingOperations($key));
        static::assertSame(0, $ks->getPendingOperations($key));
        static::assertFalse($ks->hasOngoingOperations($key));
        static::assertFalse($ks->hasPendingOperations($key));
        static::assertSame(0, $ks->getTotalOngoingOperations());
        static::assertSame(0, $ks->getTotalPendingOperations());
        static::assertFalse($ks->hasAnyOngoingOperations());
        static::assertFalse($ks->hasAnyPendingOperations());
        Async\later();
        static::assertSame(1, $ks->getOngoingOperations($key));
        static::assertSame(1, $ks->getPendingOperations($key));
        static::assertTrue($ks->hasPendingOperations($key));
        static::assertTrue($ks->hasOngoingOperations($key));
        static::assertSame(1, $ks->getTotalOngoingOperations());
        static::assertSame(1, $ks->getTotalPendingOperations());
        static::assertTrue($ks->hasAnyPendingOperations());
        static::assertTrue($ks->hasAnyOngoingOperations());
        $one->await();
        static::assertSame(1, $ks->getOngoingOperations($key));
        static::assertSame(0, $ks->getPendingOperations($key));
        static::assertTrue($ks->hasOngoingOperations($key));
        static::assertFalse($ks->hasPendingOperations($key));
        static::assertSame(1, $ks->getTotalOngoingOperations());
        static::assertSame(0, $ks->getTotalPendingOperations());
        static::assertTrue($ks->hasAnyOngoingOperations());
        static::assertFalse($ks->hasAnyPendingOperations());
        $two->await();
        static::assertSame(0, $ks->getOngoingOperations($key));
        static::assertSame(0, $ks->getPendingOperations($key));
        static::assertFalse($ks->hasOngoingOperations($key));
        static::assertFalse($ks->hasPendingOperations($key));
        static::assertSame(0, $ks->getTotalOngoingOperations());
        static::assertSame(0, $ks->getTotalPendingOperations());
        static::assertFalse($ks->hasAnyOngoingOperations());
        static::assertFalse($ks->hasAnyPendingOperations());
    }

    public function testWaitForRoom(): void
    {
        /**
         * @var Async\KeyedSemaphore<string, string, string>
         */
        $ks = new Async\KeyedSemaphore(1, static function (string $_, string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(40));
            return $input;
        });

        $one = Async\run(static fn(): string => $ks->waitFor('foo', 'one'));
        Async\later();
        static::assertFalse($one->isComplete());
        $ks->waitForPending('foo');
        static::assertTrue($one->isComplete());
        static::assertSame('one', $one->await());
    }

    public function testConcurrencyLimitOnDifferentKeys(): void
    {
        /**
         * @var Async\KeyedSemaphore<string, string, string>
         */
        $ks = new Async\KeyedSemaphore(1, static function (string $_, string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(40));
            return $input;
        });
        static::assertSame(1, $ks->getConcurrencyLimit());

        static::assertFalse($ks->hasOngoingOperations('foo'));
        static::assertFalse($ks->hasOngoingOperations('bar'));
        static::assertFalse($ks->hasPendingOperations('foo'));
        static::assertFalse($ks->hasPendingOperations('bar'));

        $fooOne = Async\run(static fn(): string => $ks->waitFor('foo', 'one'));
        $barOne = Async\run(static fn(): string => $ks->waitFor('bar', 'one'));

        Async\later();

        static::assertTrue($ks->hasOngoingOperations('foo'));
        static::assertTrue($ks->hasOngoingOperations('bar'));
        static::assertFalse($ks->hasPendingOperations('foo'));
        static::assertFalse($ks->hasPendingOperations('bar'));

        $fooTwo = Async\run(static fn(): string => $ks->waitFor('foo', 'two'));
        $barTwo = Async\run(static fn(): string => $ks->waitFor('bar', 'two'));

        Async\later();

        static::assertTrue($ks->hasOngoingOperations('foo'));
        static::assertTrue($ks->hasOngoingOperations('bar'));
        static::assertTrue($ks->hasPendingOperations('foo'));
        static::assertTrue($ks->hasPendingOperations('bar'));

        static::assertSame('one', $fooOne->await());
        static::assertSame('one', $barOne->await());

        static::assertTrue($ks->hasOngoingOperations('foo'));
        static::assertTrue($ks->hasOngoingOperations('bar'));
        static::assertFalse($ks->hasPendingOperations('foo'));
        static::assertFalse($ks->hasPendingOperations('bar'));

        static::assertSame('two', $fooTwo->await());
        static::assertSame('two', $barTwo->await());

        static::assertFalse($ks->hasOngoingOperations('foo'));
        static::assertFalse($ks->hasOngoingOperations('bar'));
        static::assertFalse($ks->hasPendingOperations('foo'));
        static::assertFalse($ks->hasPendingOperations('bar'));
    }

    public function testWaitForPendingReturnsImmediatelyWhenNotAtLimit(): void
    {
        $ks = new Async\KeyedSemaphore(1, static fn(string $key, string $input): string => $input);

        $ks->waitForPending('key');

        static::assertFalse($ks->hasOngoingOperations('key'));
    }

    public function testWaitForCancelledWhileWaitingForSlot(): void
    {
        $ks = new Async\KeyedSemaphore(1, static function (string $key, string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(100));

            return $input;
        });

        Async\run(static fn(): string => $ks->waitFor('key', 'first'))->ignore();

        $token = new Async\TimeoutCancellationToken(DateTime\Duration::milliseconds(10));

        $this->expectException(Async\Exception\CancelledException::class);

        Async\run(static fn(): string => $ks->waitFor('key', 'second', $token))->await();
    }

    public function testWaitForPendingCancelledWhileWaiting(): void
    {
        $ks = new Async\KeyedSemaphore(1, static function (string $key, string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(100));

            return $input;
        });

        Async\run(static fn(): string => $ks->waitFor('key', 'first'))->ignore();

        $token = new Async\TimeoutCancellationToken(DateTime\Duration::milliseconds(10));

        $this->expectException(Async\Exception\CancelledException::class);

        Async\run(static fn(): null => $ks->waitForPending('key', $token))->await();
    }

    public function testWaitForWithAlreadyCancelledToken(): void
    {
        $ks = new Async\KeyedSemaphore(1, static function (string $key, string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(100));

            return $input;
        });

        Async\run(static fn(): string => $ks->waitFor('key', 'first'))->ignore();

        $token = new Async\SignalCancellationToken();
        $token->cancel();

        $this->expectException(Async\Exception\CancelledException::class);

        Async\run(static fn(): string => $ks->waitFor('key', 'second', $token))->await();
    }

    public function testCancelledWaitForDoesNotAffectOtherOperations(): void
    {
        $ks = new Async\KeyedSemaphore(1, static function (string $key, string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(30));

            return $input;
        });

        $first = Async\run(static fn(): string => $ks->waitFor('key', 'first'));

        $token = new Async\TimeoutCancellationToken(DateTime\Duration::milliseconds(10));
        $second = Async\run(static fn(): string => $ks->waitFor('key', 'second', $token));

        try {
            $second->await();
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        static::assertSame('first', $first->await());
        static::assertFalse($ks->hasPendingOperations('key'));
    }

    public function testCancelledWaitForOnOneKeyDoesNotAffectOtherKey(): void
    {
        $ks = new Async\KeyedSemaphore(1, static function (string $key, string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(30));

            return $input;
        });

        Async\run(static fn(): string => $ks->waitFor('a', 'first-a'))->ignore();

        $token = new Async\TimeoutCancellationToken(DateTime\Duration::milliseconds(10));
        $cancelled = Async\run(static fn(): string => $ks->waitFor('a', 'second-a', $token));

        $other = Async\run(static fn(): string => $ks->waitFor('b', 'first-b'));

        try {
            $cancelled->await();
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        static::assertSame('first-b', $other->await());
    }

    public function testReentrantCallFromSameFiberDoesNotDeadlock(): void
    {
        $innerResult = null;

        /** @var Async\KeyedSemaphore<string, string, string> */
        $ks = new Async\KeyedSemaphore(1, static function (string $key, string $input) use (
            &$ks,
            &$innerResult,
        ): string {
            if ($input === 'outer') {
                $innerResult = $ks->waitFor($key, 'inner');
            }

            return $input . '-done';
        });

        $result = $ks->waitFor('x', 'outer');

        static::assertSame('outer-done', $result);
        static::assertSame('inner-done', $innerResult);
    }

    public function testReentrantCallFromMainFiberDoesNotDeadlock(): void
    {
        $innerResult = null;

        /** @var Async\KeyedSemaphore<string, int, int> */
        $ks = new Async\KeyedSemaphore(1, static function (string $key, int $input) use (&$ks, &$innerResult): int {
            if ($input === 1) {
                $innerResult = $ks->waitFor($key, 2);
            }

            return $input * 10;
        });

        $result = $ks->waitFor('k', 1);

        static::assertSame(10, $result);
        static::assertSame(20, $innerResult);
    }

    public function testReentrantCallWithHigherConcurrencyDoesNotDeadlock(): void
    {
        $callCount = 0;

        /** @var Async\KeyedSemaphore<string, string, string> */
        $ks = new Async\KeyedSemaphore(2, static function (string $key, string $input) use (&$ks, &$callCount): string {
            $callCount++;
            if ($input === 'outer') {
                return $ks->waitFor($key, 'inner');
            }

            return $input . '-done';
        });

        // Fill both slots, then one of them re-enters.
        $a = Async\run(static fn(): string => $ks->waitFor('x', 'outer'));
        $b = Async\run(static fn(): string => $ks->waitFor('x', 'filler'));

        Async\later();

        $resultA = $a->await();
        $resultB = $b->await();

        static::assertSame('inner-done', $resultA);
        static::assertSame('filler-done', $resultB);
        static::assertSame(3, $callCount); // outer, filler, inner (from outer's re-entry)
    }

    public function testSlotIsHandedOverToThePendingOperation(): void
    {
        $spy = new Psl\Ref(['current' => 0, 'max' => 0, 'order' => []]);

        /**
         * @var Async\KeyedSemaphore<string, string, void>
         */
        $ks = new Async\KeyedSemaphore(1, static function (string $_key, string $input) use ($spy): void {
            $spy->value['current']++;
            $spy->value['max'] = max($spy->value['max'], $spy->value['current']);
            $spy->value['order'][] = $input;

            Async\sleep(DateTime\Duration::milliseconds(1));

            $spy->value['current']--;
        });

        $first = Async\run(static function () use ($ks): void {
            $ks->waitFor('k', 'a1');
            // The slot released by "a1" belongs to "b", which was already waiting for it.
            $ks->waitFor('k', 'a2');
        });

        $second = Async\run(static fn(): null => $ks->waitFor('k', 'b'));

        $first->await();
        $second->await();

        static::assertSame(1, $spy->value['max']);
        static::assertSame(['a1', 'b', 'a2'], $spy->value['order']);
        static::assertSame(0, $ks->getOngoingOperations('k'));
        static::assertFalse($ks->hasOngoingOperations('k'));
        static::assertFalse($ks->hasPendingOperations('k'));
    }

    public function testConcurrencyLimitIsNeverExceeded(): void
    {
        $spy = new Psl\Ref(['current' => 0, 'max' => 0, 'count' => 0]);

        /**
         * @var Async\KeyedSemaphore<string, null, void>
         */
        $ks = new Async\KeyedSemaphore(2, static function () use ($spy): void {
            $spy->value['current']++;
            $spy->value['count']++;
            $spy->value['max'] = max($spy->value['max'], $spy->value['current']);

            Async\later();

            $spy->value['current']--;
        });

        $awaitables = [];
        for ($i = 0; $i < 4; $i++) {
            $awaitables[] = Async\run(static function () use ($ks): void {
                for ($j = 0; $j < 5; $j++) {
                    $ks->waitFor('k', null);
                }
            });
        }

        Async\all($awaitables);

        static::assertSame(2, $spy->value['max']);
        static::assertSame(20, $spy->value['count']);
        static::assertFalse($ks->hasOngoingOperations('k'));
    }

    public function testReentrantCallDoesNotLetOtherOperationsExceedTheLimit(): void
    {
        $spy = new Psl\Ref([]);

        /** @var Async\KeyedSemaphore<string, string, string> */
        $ks = new Async\KeyedSemaphore(1, static function (string $key, string $input) use (&$ks, $spy): string {
            if ($input === 'outer') {
                return $ks->waitFor($key, 'inner');
            }

            $spy->value[] = 'start:' . $input;
            Async\sleep(DateTime\Duration::milliseconds(1));
            $spy->value[] = 'end:' . $input;

            return $input;
        });

        $a = Async\run(static fn(): string => $ks->waitFor('k', 'outer'));
        $b = Async\run(static fn(): string => $ks->waitFor('k', 'other'));

        static::assertSame('inner', $a->await());
        static::assertSame('other', $b->await());
        static::assertSame(['start:inner', 'end:inner', 'start:other', 'end:other'], $spy->value);
        static::assertFalse($ks->hasOngoingOperations('k'));
    }

    public function testWaitForPendingWaitsWhileReentrantCallIsOngoing(): void
    {
        $spy = new Psl\Ref([]);

        /** @var Async\KeyedSemaphore<string, string, string> */
        $ks = new Async\KeyedSemaphore(1, static function (string $key, string $input) use (&$ks, $spy): string {
            if ($input === 'outer') {
                return $ks->waitFor($key, 'inner');
            }

            Async\sleep(DateTime\Duration::milliseconds(1));
            $spy->value[] = 'done:' . $input;

            return $input;
        });

        $a = Async\run(static fn(): string => $ks->waitFor('k', 'outer'));
        Async\later();

        $ks->waitForPending('k');
        $spy->value[] = 'waited';

        $a->await();

        static::assertSame(['done:inner', 'waited'], $spy->value);
    }

    public function testCompletedReentrantCallHandsItsSlotOverToThePendingOperation(): void
    {
        $spy = new Psl\Ref([]);
        $gates = [
            'reenter' => new Async\Deferred(),
            'inner' => new Async\Deferred(),
            'outer' => new Async\Deferred(),
            'other' => new Async\Deferred(),
            'pending' => new Async\Deferred(),
        ];

        /** @var Async\KeyedSemaphore<string, string, string> */
        $ks = new Async\KeyedSemaphore(2, static function (string $key, string $input) use (
            &$ks,
            $spy,
            $gates,
        ): string {
            $spy->value[] = 'start:' . $input;
            if ('outer' === $input) {
                $gates['reenter']->getAwaitable()->await();
                $ks->waitFor($key, 'inner');
            }

            $gates[$input]->getAwaitable()->await();
            $spy->value[] = 'end:' . $input;

            return $input;
        });

        $outer = Async\run(static fn(): string => $ks->waitFor('k', 'outer'));
        $other = Async\run(static fn(): string => $ks->waitFor('k', 'other'));
        Async\later();

        // "outer" re-enters while "other" holds the second slot, taking the count above the limit.
        $gates['reenter']->complete(null);
        Async\later();
        static::assertSame(3, $ks->getOngoingOperations('k'));

        // "other" completes: "outer" and "inner" are left, so "pending" has to wait.
        $gates['other']->complete(null);
        $other->await();
        static::assertSame(2, $ks->getOngoingOperations('k'));

        $pending = Async\run(static fn(): string => $ks->waitFor('k', 'pending'));
        Async\later();
        static::assertTrue($ks->hasPendingOperations('k'));

        // "inner" completes while "outer" keeps running: a slot is free, "pending" must start.
        $gates['inner']->complete(null);
        Async\later();
        Async\later();

        static::assertContains('start:pending', $spy->value);
        static::assertNotContains('end:outer', $spy->value);
        static::assertFalse($ks->hasPendingOperations('k'));
        static::assertSame(2, $ks->getOngoingOperations('k'));

        $gates['outer']->complete(null);
        $gates['pending']->complete(null);

        static::assertSame('outer', $outer->await());
        static::assertSame('pending', $pending->await());
        static::assertFalse($ks->hasOngoingOperations('k'));
    }

    public function testCompletedOperationDoesNotHandItsSlotOverWhileReentrantCallsKeepTheCountAtTheLimit(): void
    {
        $spy = new Psl\Ref([]);
        $gates = [
            'reenter' => new Async\Deferred(),
            'inner' => new Async\Deferred(),
            'outer' => new Async\Deferred(),
            'other' => new Async\Deferred(),
            'pending' => new Async\Deferred(),
        ];

        /** @var Async\KeyedSemaphore<string, string, string> */
        $ks = new Async\KeyedSemaphore(2, static function (string $key, string $input) use (
            &$ks,
            $spy,
            $gates,
        ): string {
            $spy->value[] = 'start:' . $input;
            if ('outer' === $input) {
                $gates['reenter']->getAwaitable()->await();
                $ks->waitFor($key, 'inner');
            }

            $gates[$input]->getAwaitable()->await();
            $spy->value[] = 'end:' . $input;

            return $input;
        });

        $outer = Async\run(static fn(): string => $ks->waitFor('k', 'outer'));
        $other = Async\run(static fn(): string => $ks->waitFor('k', 'other'));
        Async\later();

        // "outer" re-enters while "other" holds the second slot, taking the count above the limit.
        $gates['reenter']->complete(null);
        Async\later();

        $pending = Async\run(static fn(): string => $ks->waitFor('k', 'pending'));
        Async\later();
        static::assertTrue($ks->hasPendingOperations('k'));

        // "other" completes: "outer" and "inner" are left, so "pending" still has to wait.
        $gates['other']->complete(null);
        $other->await();
        Async\later();

        static::assertNotContains('start:pending', $spy->value);
        static::assertTrue($ks->hasPendingOperations('k'));
        static::assertSame(2, $ks->getOngoingOperations('k'));

        // "inner" completes: "pending" takes its slot.
        $gates['inner']->complete(null);
        Async\later();
        Async\later();

        static::assertContains('start:pending', $spy->value);
        static::assertSame(2, $ks->getOngoingOperations('k'));

        $gates['outer']->complete(null);
        $gates['pending']->complete(null);

        static::assertSame('outer', $outer->await());
        static::assertSame('pending', $pending->await());
        static::assertFalse($ks->hasOngoingOperations('k'));
    }

    public function testCompletedReentrantCallResumesWaitForPendingWhenNothingIsPending(): void
    {
        $gates = [
            'reenter' => new Async\Deferred(),
            'inner' => new Async\Deferred(),
            'outer' => new Async\Deferred(),
            'other' => new Async\Deferred(),
        ];

        /** @var Async\KeyedSemaphore<string, string, string> */
        $ks = new Async\KeyedSemaphore(2, static function (string $key, string $input) use (&$ks, $gates): string {
            if ('outer' === $input) {
                $gates['reenter']->getAwaitable()->await();
                $ks->waitFor($key, 'inner');
            }

            $gates[$input]->getAwaitable()->await();

            return $input;
        });

        $outer = Async\run(static fn(): string => $ks->waitFor('k', 'outer'));
        $other = Async\run(static fn(): string => $ks->waitFor('k', 'other'));
        Async\later();

        // "outer" re-enters while "other" holds the second slot, taking the count above the limit.
        $gates['reenter']->complete(null);
        Async\later();
        static::assertSame(3, $ks->getOngoingOperations('k'));

        // "other" completes: "outer" and "inner" keep the count at the limit.
        $gates['other']->complete(null);
        $other->await();
        static::assertSame(2, $ks->getOngoingOperations('k'));

        $waiting = Async\run(static fn() => $ks->waitForPending('k'));
        Async\later();
        static::assertFalse($waiting->isComplete());

        // "inner" completes while "outer" keeps running: nothing is pending anymore.
        $gates['inner']->complete(null);
        Async\later();
        Async\later();

        static::assertTrue($waiting->isComplete());
        static::assertSame(1, $ks->getOngoingOperations('k'));

        $gates['outer']->complete(null);

        static::assertSame('outer', $outer->await());
        static::assertFalse($ks->hasOngoingOperations('k'));
    }
}
