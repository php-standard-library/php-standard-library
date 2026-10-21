<?php

declare(strict_types=1);

namespace Psl\Async\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl;
use Psl\Async;
use Psl\DateTime;

use function max;

final class KeyedSequenceTest extends TestCase
{
    public function testItCallsTheOperation(): void
    {
        $ks = new Async\KeyedSequence(static function (string $key, int $input): int {
            static::assertSame('one', $key);

            return $input * 2;
        });

        static::assertSame(4, $ks->waitFor('one', 2));
    }

    public function testSequenceOperationWaitsForPendingOperationsWhenLimitIsNotReached(): void
    {
        $spy = new Psl\Ref([]);

        /**
         * @var Async\KeyedSequence<string, array{time: ?float, value: string}, void>
         */
        $ks = new Async\KeyedSequence(static function (string $key, array $data) use ($spy): void {
            static::assertSame('operation', $key);

            if (null !== $data['time']) {
                Async\sleep($data['time']);
            }

            $spy->value[] = $data['value'];
        });

        Async\run(static fn(): null => $ks->waitFor('operation', [
            'time' => DateTime\Duration::milliseconds(3),
            'value' => 'a',
        ]));
        Async\run(static fn(): null => $ks->waitFor('operation', [
            'time' => DateTime\Duration::milliseconds(4),
            'value' => 'b',
        ]));
        Async\run(static fn(): null => $ks->waitFor('operation', [
            'time' => DateTime\Duration::milliseconds(5),
            'value' => 'c',
        ]));
        $last = Async\run(static fn(): null => $ks->waitFor('operation', ['time' => null, 'value' => 'd']));
        $last->await();

        static::assertSame(['a', 'b', 'c', 'd'], $spy->value);
    }

    public function testOperationIsStartedIfLimitIsNotReached(): void
    {
        $spy = new Psl\Ref([]);

        /**
         * @var Async\KeyedSequence<string, string, void>
         */
        $ks = new Async\KeyedSequence(static function (string $key, string $input) use ($spy): void {
            static::assertSame('x', $key);

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
         * @var Async\KeyedSequence<string, string, void>
         */
        $ks = new Async\KeyedSequence(static function (string $_, string $input) use ($spy): void {
            $spy->value[] = $input;

            Async\sleep(DateTime\Duration::milliseconds(2));
        });

        Async\run(static fn(): null => $ks->waitFor('x', 'hello'));
        $awaitable = Async\run(static fn(): null => $ks->waitFor('x', 'world'));

        Async\sleep(DateTime\Duration::milliseconds(1));

        static::assertNotContains('world', $spy->value);

        $awaitable->await();
    }

    public function testCancelingTheSemaphoreAllowsForFutureOperations(): void
    {
        /**
         * @var Async\KeyedSequence<string, string, string>
         */
        $semaphore = new Async\KeyedSequence(static fn(string $_, string $input): string => $input);

        $semaphore->cancelAll(new Async\Exception\TimeoutException('The semaphore is destroyed.'));

        static::assertSame('hello', $semaphore->waitFor('x', 'hello'));
    }

    public function testCancelPendingOperationsButNotTheOngoingOne(): void
    {
        /**
         * @var Async\KeyedSequence<string, string, string>
         */
        $ks = new Async\KeyedSequence(static function (string $_, string $input): string {
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
         * @var Async\KeyedSequence<string, string, string>
         */
        $ks = new Async\KeyedSequence(static function (string $_, string $input): string {
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
         * @var Async\KeyedSequence<string, string, string>
         */
        $ks = new Async\KeyedSequence(static function (string $_, string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(40));

            return $input;
        });

        $key = 'foo';

        $one = Async\run(static fn(): string => $ks->waitFor($key, 'one'));
        $two = Async\run(static fn(): string => $ks->waitFor($key, 'two'));
        static::assertSame(0, $ks->getPendingOperations($key));
        static::assertFalse($ks->hasOngoingOperations($key));
        static::assertFalse($ks->hasPendingOperations($key));
        static::assertSame(0, $ks->getTotalOngoingOperations());
        static::assertSame(0, $ks->getTotalPendingOperations());
        static::assertFalse($ks->hasAnyOngoingOperations());
        static::assertFalse($ks->hasAnyPendingOperations());
        Async\later();
        static::assertSame(1, $ks->getPendingOperations($key));
        static::assertTrue($ks->hasPendingOperations($key));
        static::assertTrue($ks->hasOngoingOperations($key));
        static::assertSame(1, $ks->getTotalOngoingOperations());
        static::assertSame(1, $ks->getTotalPendingOperations());
        static::assertTrue($ks->hasAnyPendingOperations());
        static::assertTrue($ks->hasAnyOngoingOperations());
        $one->await();
        static::assertSame(0, $ks->getPendingOperations($key));
        static::assertTrue($ks->hasOngoingOperations($key));
        static::assertFalse($ks->hasPendingOperations($key));
        static::assertSame(1, $ks->getTotalOngoingOperations());
        static::assertSame(0, $ks->getTotalPendingOperations());
        static::assertTrue($ks->hasAnyOngoingOperations());
        static::assertFalse($ks->hasAnyPendingOperations());
        $two->await();
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
         * @var Async\KeyedSequence<string, string, string>
         */
        $ks = new Async\KeyedSequence(static function (string $_, string $input): string {
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
         * @var Async\KeyedSequence<string, string, string>
         */
        $ks = new Async\KeyedSequence(static function (string $_, string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(40));

            return $input;
        });

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

    public function testWaitForPendingReturnsImmediatelyWhenNotIngoing(): void
    {
        $ks = new Async\KeyedSequence(static fn(string $key, string $input): string => $input);

        $ks->waitForPending('key');

        static::assertFalse($ks->hasOngoingOperations('key'));
    }

    public function testWaitForCancelledWhileWaiting(): void
    {
        $ks = new Async\KeyedSequence(static function (string $key, string $input): string {
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
        $ks = new Async\KeyedSequence(static function (string $key, string $input): string {
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
        $ks = new Async\KeyedSequence(static function (string $key, string $input): string {
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
        $ks = new Async\KeyedSequence(static function (string $key, string $input): string {
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

    public function testReentrantCallFromSameFiberDoesNotDeadlock(): void
    {
        $innerResult = null;

        /** @var Async\KeyedSequence<string, string, string> */
        $ks = new Async\KeyedSequence(static function (string $key, string $input) use (&$ks, &$innerResult): string {
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

        /** @var Async\KeyedSequence<string, int, int> */
        $ks = new Async\KeyedSequence(static function (string $key, int $input) use (&$ks, &$innerResult): int {
            if ($input === 1) {
                $innerResult = $ks->waitFor($key, 2);
            }

            return $input * 10;
        });

        // Running from {main}, not inside a fiber.
        $result = $ks->waitFor('k', 1);

        static::assertSame(10, $result);
        static::assertSame(20, $innerResult);
    }

    public function testReentrantCallWithDifferentKeyDoesNotDeadlock(): void
    {
        /** @var Async\KeyedSequence<string, string, string> */
        $ks = new Async\KeyedSequence(static function (string $key, string $input) use (&$ks): string {
            if ($input === 'outer') {
                return $ks->waitFor('y', 'inner');
            }

            return $input . '-done';
        });

        $result = $ks->waitFor('x', 'outer');

        static::assertSame('inner-done', $result);
    }

    public function testCancelledWaitForOnOneKeyDoesNotAffectOtherKey(): void
    {
        $ks = new Async\KeyedSequence(static function (string $key, string $input): string {
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

    public function testKeyIsHandedOverToThePendingOperation(): void
    {
        $spy = new Psl\Ref(['current' => 0, 'max' => 0, 'order' => []]);

        /**
         * @var Async\KeyedSequence<string, string, void>
         */
        $ks = new Async\KeyedSequence(static function (string $_key, string $input) use ($spy): void {
            $spy->value['current']++;
            $spy->value['max'] = max($spy->value['max'], $spy->value['current']);
            $spy->value['order'][] = $input;

            Async\later();

            $spy->value['current']--;
        });

        $first = Async\run(static function () use ($ks): void {
            $ks->waitFor('k', 'a1');
            // The key released by "a1" belongs to "b", which was already waiting for it.
            $ks->waitFor('k', 'a2');
        });

        $second = Async\run(static fn(): null => $ks->waitFor('k', 'b'));

        $first->await();
        $second->await();

        static::assertSame(1, $spy->value['max']);
        static::assertSame(['a1', 'b', 'a2'], $spy->value['order']);
        static::assertFalse($ks->hasOngoingOperations('k'));
        static::assertFalse($ks->hasPendingOperations('k'));
    }

    public function testKeyIsHandedOverToThePendingOperationFromMain(): void
    {
        $spy = new Psl\Ref(['current' => 0, 'max' => 0, 'order' => []]);

        /**
         * @var Async\KeyedSequence<string, string, void>
         */
        $ks = new Async\KeyedSequence(static function (string $_key, string $input) use ($spy): void {
            $spy->value['current']++;
            $spy->value['max'] = max($spy->value['max'], $spy->value['current']);
            $spy->value['order'][] = $input;

            Async\later();

            $spy->value['current']--;
        });

        // "b" starts waiting for the key while {main} runs "a1".
        $second = Async\run(static fn(): null => $ks->waitFor('k', 'b'));
        $ks->waitFor('k', 'a1');

        // The key belongs to "b" now, although "b" has not resumed yet.
        static::assertTrue($ks->hasOngoingOperations('k'));
        static::assertSame(1, $ks->getTotalOngoingOperations());
        static::assertFalse($ks->hasPendingOperations('k'));

        $ks->waitFor('k', 'a2');
        $second->await();

        static::assertSame(1, $spy->value['max']);
        static::assertSame(['a1', 'b', 'a2'], $spy->value['order']);
        static::assertFalse($ks->hasOngoingOperations('k'));
    }

    public function testOperationThatWasHandedTheKeyCanReenter(): void
    {
        $gate = new Async\Deferred();
        $spy = new Psl\Ref([]);

        /** @var Async\KeyedSequence<string, string, string> */
        $ks = new Async\KeyedSequence(static function (string $key, string $input) use (&$ks, $gate, $spy): string {
            if ('first' === $input) {
                $gate->getAwaitable()->await();
            } elseif ('second' === $input) {
                $spy->value[] = $ks->waitFor($key, 'inner');
            }

            return $input;
        });

        $first = Async\run(static fn(): string => $ks->waitFor('k', 'first'));
        $second = Async\run(static fn(): string => $ks->waitFor('k', 'second'));
        Async\later();
        static::assertTrue($ks->hasPendingOperations('k'));

        $gate->complete(null);

        static::assertSame('first', $first->await());
        static::assertSame('second', $second->await());
        static::assertSame(['inner'], $spy->value);
        static::assertFalse($ks->hasOngoingOperations('k'));
    }

    public function testCancellingAnOperationTheKeyWasHandedOverToLetsItRun(): void
    {
        $gate = new Async\Deferred();
        $token = new Async\SignalCancellationToken();

        /** @var Async\KeyedSequence<string, string, string> */
        $ks = new Async\KeyedSequence(static function (string $_key, string $input) use ($gate): string {
            if ('first' === $input) {
                $gate->getAwaitable()->await();
            }

            return $input;
        });

        $first = Async\run(static function () use ($ks, $token): string {
            $result = $ks->waitFor('k', 'first');
            // The key belongs to "second" now, which has not resumed yet: cancelling it is too late.
            $token->cancel();

            return $result;
        });
        $second = Async\run(static fn(): string => $ks->waitFor('k', 'second', $token));
        Async\later();
        static::assertTrue($ks->hasPendingOperations('k'));

        $gate->complete(null);

        static::assertSame('first', $first->await());
        static::assertSame('second', $second->await());
        static::assertFalse($ks->hasOngoingOperations('k'));
        static::assertFalse($ks->hasPendingOperations('k'));
    }
}
