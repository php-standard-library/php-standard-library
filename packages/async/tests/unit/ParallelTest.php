<?php

declare(strict_types=1);

namespace Psl\Async\Tests\Unit;

use Generator;
use PHPUnit\Framework\TestCase;
use Psl;
use Psl\Async;
use Psl\DateTime;
use RuntimeException as Exception;

final class ParallelTest extends TestCase
{
    public function testParallel(): void
    {
        $spy = new Psl\Ref('');

        Async\concurrently([
            static function () use ($spy): void {
                Async\sleep(DateTime\Duration::milliseconds(30));

                $spy->value .= '1';
            },
            static function () use ($spy): void {
                Async\sleep(DateTime\Duration::milliseconds(10));

                $spy->value .= '2';
            },
            static function () use ($spy): void {
                Async\sleep(DateTime\Duration::milliseconds(10));

                $spy->value .= '3';
            },
        ]);

        static::assertSame('231', $spy->value);
    }

    public function testParallelThrowsForTheFirstAndDoesNotCallTheRest(): void
    {
        Async\Scheduler::run();

        $spy = new Psl\Ref('');

        try {
            Async\concurrently([
                static function (): void {
                    Async\sleep(DateTime\Duration::milliseconds(3));

                    throw new Exception('foo');
                },
                static function () use ($spy): void {
                    Async\sleep(DateTime\Duration::milliseconds(4));

                    $spy->value = 'thrown';

                    throw new Exception('bar');
                },
            ]);
        } catch (Exception $exception) {
            static::assertSame('foo', $exception->getMessage());
        }

        // wait for all callbacks in the event loop to finish.
        // including the second callback given to concurrently.
        // this ensures that even after it finishes, the exception will be ignored.
        Async\Scheduler::run();

        // the second callback was finished, but it didn't throw the exception ( which is exactly what we want )
        static::assertSame('thrown', $spy->value);
    }

    public function testParallelRunsEveryTaskOfAnIterableRepeatingKeys(): void
    {
        $spy = new Psl\Ref('');

        $tasks = (static function () use ($spy): Generator {
            yield 'a' => static function () use ($spy): string {
                Async\sleep(DateTime\Duration::milliseconds(2));

                $spy->value = 'a1 completed';

                return 'a1';
            };

            yield 'b' => static fn(): string => 'b';
            yield 'a' => static fn(): string => 'a2';
        })();

        static::assertSame(['a' => 'a2', 'b' => 'b'], Async\concurrently($tasks));
        static::assertSame('a1 completed', $spy->value);
    }

    public function testParallelThrowsTheErrorOfATaskWhoseKeyIsRepeated(): void
    {
        $tasks = (static function (): Generator {
            yield 'a' => static fn(): never => throw new Exception('a1');
            yield 'a' => static fn(): string => 'a2';
        })();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('a1');

        Async\concurrently($tasks);
    }
}
