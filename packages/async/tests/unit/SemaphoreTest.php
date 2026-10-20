<?php

declare(strict_types=1);

namespace Psl\Async\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl;
use Psl\Async;
use Psl\DateTime;

use function max;

final class SemaphoreTest extends TestCase
{
    public function testItCallsTheOperation(): void
    {
        $sequence = new Async\Semaphore(1, static fn(int $input): int => $input * 2);

        static::assertSame(4, $sequence->waitFor(2));
    }

    public function testSequenceOperationWaitsForPendingOperationsWhenLimitIsNotReached(): void
    {
        $spy = new Psl\Ref([]);

        /**
         * @var Async\Semaphore<array{time: ?DateTime\Duration, value: string}, void>
         */
        $semaphore = new Async\Semaphore(1, static function (array $data) use ($spy): void {
            if (null !== $data['time']) {
                Async\sleep($data['time']);
            }

            $spy->value[] = $data['value'];
        });

        Async\run(static fn(): null => $semaphore->waitFor([
            'time' => DateTime\Duration::milliseconds(3),
            'value' => 'a',
        ]))->ignore();
        Async\run(static fn(): null => $semaphore->waitFor([
            'time' => DateTime\Duration::milliseconds(4),
            'value' => 'b',
        ]))->ignore();
        Async\run(static fn(): null => $semaphore->waitFor([
            'time' => DateTime\Duration::milliseconds(5),
            'value' => 'c',
        ]))->ignore();
        $last = Async\run(static fn(): null => $semaphore->waitFor([
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
         * @var Async\Semaphore<array{time: ?DateTime\Duration, value: string}, void>
         */
        $semaphore = new Async\Semaphore(2, static function (array $data) use ($spy): void {
            if (null !== $data['time']) {
                Async\sleep($data['time']);
            }

            $spy->value[] = $data['value'];
        });

        Async\run(static fn(): null => $semaphore->waitFor([
            'time' => Datetime\Duration::milliseconds(3),
            'value' => 'a',
        ]))->ignore();
        Async\run(static fn(): null => $semaphore->waitFor([
            'time' => Datetime\Duration::milliseconds(4),
            'value' => 'b',
        ]))->ignore();
        $beforeLast = Async\run(static fn(): null => $semaphore->waitFor([
            'time' => Datetime\Duration::milliseconds(5),
            'value' => 'c',
        ]));
        Async\run(static fn(): null => $semaphore->waitFor([
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
         * @var Async\Semaphore<string, void>
         */
        $semaphore = new Async\Semaphore(1, static function (string $input) use ($spy): void {
            $spy->value[] = $input;

            Async\sleep(Datetime\Duration::milliseconds(2));
        });

        $awaitable = Async\run(static fn(): null => $semaphore->waitFor('hello'));

        Async\sleep(Datetime\Duration::milliseconds(1));

        static::assertSame(['hello'], $spy->value);

        $awaitable->await();
    }

    public function testOperationIsNotStartedIfLimitIsReached(): void
    {
        $spy = new Psl\Ref([]);

        /**
         * @var Async\Semaphore<string, void>
         */
        $semaphore = new Async\Semaphore(1, static function (string $input) use ($spy): void {
            $spy->value[] = $input;

            Async\sleep(Datetime\Duration::milliseconds(2));
        });

        Async\run(static fn(): null => $semaphore->waitFor('hello'))->ignore();
        $awaitable = Async\run(static fn(): null => $semaphore->waitFor('world'));

        Async\sleep(Datetime\Duration::milliseconds(1));

        static::assertNotContains('world', $spy->value);

        $awaitable->await();
    }

    public function testCancelingTheSemaphoreAllowsForFutureOperations(): void
    {
        /**
         * @var Async\Semaphore<string, string>
         */
        $semaphore = new Async\Semaphore(1, static fn(string $input): string => $input);

        $semaphore->cancel(new Async\Exception\TimeoutException('The semaphore is destroyed.'));

        static::assertSame('hello', $semaphore->waitFor('hello'));
    }

    public function testCancelPendingOperationsButNotTheOngoingOne(): void
    {
        /**
         * @var Async\Semaphore<string, string>
         */
        $semaphore = new Async\Semaphore(1, static function (string $input): string {
            Async\sleep(Datetime\Duration::milliseconds(40));

            return $input;
        });

        static::assertSame(1, $semaphore->getConcurrencyLimit());

        $one = Async\run(static fn(): string => $semaphore->waitFor('one'));
        $two = Async\run(static fn(): string => $semaphore->waitFor('two'));

        Async\sleep(Datetime\Duration::milliseconds(10));

        $semaphore->cancel(new Async\Exception\TimeoutException('The semaphore is destroyed.'));

        static::assertSame('one', $one->await());

        $this->expectException(Async\Exception\TimeoutException::class);
        $this->expectExceptionMessage('The semaphore is destroyed.');

        $two->await();
    }

    public function testSemaphoreStatus(): void
    {
        /**
         * @var Async\Semaphore<string, string>
         */
        $semaphore = new Async\Semaphore(1, static function (string $input): string {
            Async\sleep(Datetime\Duration::milliseconds(40));

            return $input;
        });

        $one = Async\run(static fn(): string => $semaphore->waitFor('one'));
        $two = Async\run(static fn(): string => $semaphore->waitFor('two'));
        static::assertSame(0, $semaphore->getOngoingOperations());
        static::assertSame(0, $semaphore->getPendingOperations());
        static::assertFalse($semaphore->hasOngoingOperations());
        static::assertFalse($semaphore->hasPendingOperations());
        Async\later();
        static::assertSame(1, $semaphore->getOngoingOperations());
        static::assertSame(1, $semaphore->getPendingOperations());
        static::assertTrue($semaphore->hasPendingOperations());
        static::assertTrue($semaphore->hasOngoingOperations());
        $one->await();
        static::assertSame(1, $semaphore->getOngoingOperations());
        static::assertSame(0, $semaphore->getPendingOperations());
        static::assertTrue($semaphore->hasOngoingOperations());
        static::assertFalse($semaphore->hasPendingOperations());
        $two->await();
        static::assertSame(0, $semaphore->getOngoingOperations());
        static::assertSame(0, $semaphore->getPendingOperations());
        static::assertFalse($semaphore->hasOngoingOperations());
        static::assertFalse($semaphore->hasPendingOperations());
    }

    public function testWaitForPending(): void
    {
        /**
         * @var Async\Semaphore<string, string>
         */
        $semaphore = new Async\Semaphore(1, static function (string $input): string {
            Async\sleep(Datetime\Duration::milliseconds(40));

            return $input;
        });

        $one = Async\run(static fn(): string => $semaphore->waitFor('one'));
        Async\later();
        static::assertFalse($one->isComplete());
        $semaphore->waitForPending();
        static::assertTrue($one->isComplete());
        static::assertSame('one', $one->await());
    }

    public function testWaitForPendingReturnsImmediatelyWhenNotAtLimit(): void
    {
        $semaphore = new Async\Semaphore(1, static fn(string $input): string => $input);

        $semaphore->waitForPending();

        static::assertFalse($semaphore->hasOngoingOperations());
    }

    public function testWaitForCancelledWhileWaitingForSlot(): void
    {
        $semaphore = new Async\Semaphore(1, static function (string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(100));

            return $input;
        });

        Async\run(static fn(): string => $semaphore->waitFor('first'))->ignore();

        $token = new Async\TimeoutCancellationToken(DateTime\Duration::milliseconds(10));

        $this->expectException(Async\Exception\CancelledException::class);

        Async\run(static fn(): string => $semaphore->waitFor('second', $token))->await();
    }

    public function testWaitForPendingCancelledWhileWaiting(): void
    {
        $semaphore = new Async\Semaphore(1, static function (string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(100));

            return $input;
        });

        Async\run(static fn(): string => $semaphore->waitFor('first'))->ignore();

        $token = new Async\TimeoutCancellationToken(DateTime\Duration::milliseconds(10));

        $this->expectException(Async\Exception\CancelledException::class);

        Async\run(static fn(): null => $semaphore->waitForPending($token))->await();
    }

    public function testWaitForWithAlreadyCancelledToken(): void
    {
        $semaphore = new Async\Semaphore(1, static function (string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(100));

            return $input;
        });

        Async\run(static fn(): string => $semaphore->waitFor('first'))->ignore();

        $token = new Async\SignalCancellationToken();
        $token->cancel();

        $this->expectException(Async\Exception\CancelledException::class);

        Async\run(static fn(): string => $semaphore->waitFor('second', $token))->await();
    }

    public function testCancelledWaitForDoesNotAffectOtherOperations(): void
    {
        $semaphore = new Async\Semaphore(1, static function (string $input): string {
            Async\sleep(DateTime\Duration::milliseconds(30));

            return $input;
        });

        $first = Async\run(static fn(): string => $semaphore->waitFor('first'));

        $token = new Async\TimeoutCancellationToken(DateTime\Duration::milliseconds(10));
        $second = Async\run(static fn(): string => $semaphore->waitFor('second', $token));

        try {
            $second->await();
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        static::assertSame('first', $first->await());
        static::assertFalse($semaphore->hasPendingOperations());
    }

    public function testSlotIsHandedOverToThePendingOperation(): void
    {
        $spy = new Psl\Ref(['current' => 0, 'max' => 0, 'order' => []]);

        /**
         * @var Async\Semaphore<string, void>
         */
        $semaphore = new Async\Semaphore(1, static function (string $input) use ($spy): void {
            $spy->value['current']++;
            $spy->value['max'] = max($spy->value['max'], $spy->value['current']);
            $spy->value['order'][] = $input;

            Async\sleep(DateTime\Duration::milliseconds(1));

            $spy->value['current']--;
        });

        $first = Async\run(static function () use ($semaphore): void {
            $semaphore->waitFor('a1');
            // The slot released by "a1" belongs to "b", which was already waiting for it.
            $semaphore->waitFor('a2');
        });

        $second = Async\run(static fn(): null => $semaphore->waitFor('b'));

        $first->await();
        $second->await();

        static::assertSame(1, $spy->value['max']);
        static::assertSame(['a1', 'b', 'a2'], $spy->value['order']);
        static::assertSame(0, $semaphore->getOngoingOperations());
        static::assertFalse($semaphore->hasPendingOperations());
    }

    public function testConcurrencyLimitIsNeverExceeded(): void
    {
        $spy = new Psl\Ref(['current' => 0, 'max' => 0, 'count' => 0]);

        /**
         * @var Async\Semaphore<null, void>
         */
        $semaphore = new Async\Semaphore(2, static function () use ($spy): void {
            $spy->value['current']++;
            $spy->value['count']++;
            $spy->value['max'] = max($spy->value['max'], $spy->value['current']);

            Async\later();

            $spy->value['current']--;
        });

        $awaitables = [];
        for ($i = 0; $i < 4; $i++) {
            $awaitables[] = Async\run(static function () use ($semaphore): void {
                for ($j = 0; $j < 5; $j++) {
                    $semaphore->waitFor(null);
                }
            });
        }

        Async\all($awaitables);

        static::assertSame(2, $spy->value['max']);
        static::assertSame(20, $spy->value['count']);
        static::assertSame(0, $semaphore->getOngoingOperations());
    }
}
