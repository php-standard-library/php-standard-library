<?php

declare(strict_types=1);

namespace Psl\Async\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Ref;
use RuntimeException;

final class TaskGroupTest extends TestCase
{
    public function testAwaitAllWithNoTasks(): void
    {
        $group = new Async\TaskGroup();

        $group->awaitAll();

        static::addToAssertionCount(1);
    }

    public function testAllTasksRun(): void
    {
        $ref = new Ref('');

        Async\run(static function () use ($ref): void {
            $group = new Async\TaskGroup();

            $group->defer(static function () use ($ref): void {
                $ref->value .= 'a';
            });

            $group->defer(static function () use ($ref): void {
                $ref->value .= 'b';
            });

            $group->awaitAll();
        })->await();

        static::assertSame('ab', $ref->value);
    }

    public function testTasksRunConcurrently(): void
    {
        $result = Async\run(static function (): string {
            $ref = new Ref('');
            $group = new Async\TaskGroup();

            $group->defer(static function () use ($ref): void {
                Async\sleep(Duration::milliseconds(20));
                $ref->value .= 'slow';
            });

            $group->defer(static function () use ($ref): void {
                $ref->value .= 'fast';
            });

            $group->awaitAll();

            return $ref->value;
        })->await();

        static::assertSame('fastslow', $result);
    }

    public function testSingleTaskThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        Async\run(static function (): void {
            $group = new Async\TaskGroup();

            $group->defer(static function (): void {
                throw new RuntimeException('boom');
            });

            $group->awaitAll();
        })->await();
    }

    public function testMultipleTasksThrowCompositeException(): void
    {
        $this->expectException(Async\Exception\CompositeException::class);

        Async\run(static function (): void {
            $group = new Async\TaskGroup();

            $group->defer(static function (): void {
                throw new RuntimeException('first');
            });

            $group->defer(static function (): void {
                throw new RuntimeException('second');
            });

            $group->awaitAll();
        })->await();
    }

    public function testAwaitAllWithCancellation(): void
    {
        $this->expectException(Async\Exception\CancelledException::class);

        Async\run(static function (): void {
            $group = new Async\TaskGroup();
            $token = new Async\TimeoutCancellationToken(Duration::milliseconds(10));

            $group->defer(static function (): void {
                Async\sleep(Duration::seconds(5));
            });

            $group->awaitAll($token);
        })->await();
    }

    public function testAwaitAllWithAlreadyCancelledToken(): void
    {
        $this->expectException(Async\Exception\CancelledException::class);

        Async\run(static function (): void {
            $token = new Async\SignalCancellationToken();
            $token->cancel();

            $group = new Async\TaskGroup();

            $group->defer(static function (): void {
                Async\sleep(Duration::seconds(5));
            });

            $group->awaitAll($token);
        })->await();
    }

    public function testAwaitAllClearsTaskList(): void
    {
        $count = 0;

        Async\run(static function () use (&$count): void {
            $group = new Async\TaskGroup();

            $group->defer(static function () use (&$count): void {
                $count++;
            });

            $group->awaitAll();
            $group->awaitAll();
        })->await();

        static::assertSame(1, $count);
    }

    public function testDeferAfterAwaitAll(): void
    {
        $ref = new Ref('');

        Async\run(static function () use ($ref): void {
            $group = new Async\TaskGroup();

            $group->defer(static function () use ($ref): void {
                $ref->value .= 'first';
            });

            $group->awaitAll();

            $group->defer(static function () use ($ref): void {
                $ref->value .= '-second';
            });

            $group->awaitAll();
        })->await();

        static::assertSame('first-second', $ref->value);
    }

    public function testConcurrentJoinsGetTheSameException(): void
    {
        $group = new Async\TaskGroup();

        $group->defer(static function (): void {
            Async\sleep(Duration::milliseconds(1));

            throw new RuntimeException('boom');
        });

        $first = Async\run(static function () use ($group): void {
            $group->awaitAll();
        });

        $second = Async\run(static function () use ($group): void {
            $group->awaitAll();
        });

        $exceptions = [];
        foreach ([$first, $second] as $join) {
            try {
                $join->await();
            } catch (RuntimeException $exception) {
                $exceptions[] = $exception;
            }
        }

        static::assertCount(2, $exceptions);
        static::assertSame('boom', $exceptions[0]->getMessage());
        static::assertSame($exceptions[0], $exceptions[1]);

        // The outcome was taken by the two joins.
        $group->awaitAll();
    }

    public function testConcurrentJoinsGetTheSameCompositeException(): void
    {
        $group = new Async\TaskGroup();

        $group->defer(static function (): void {
            Async\sleep(Duration::milliseconds(1));

            throw new RuntimeException('first');
        });

        $group->defer(static function (): void {
            Async\sleep(Duration::milliseconds(2));

            throw new RuntimeException('second');
        });

        $first = Async\run(static function () use ($group): void {
            $group->awaitAll();
        });

        $second = Async\run(static function () use ($group): void {
            $group->awaitAll();
        });

        $exceptions = [];
        foreach ([$first, $second] as $join) {
            try {
                $join->await();
            } catch (Async\Exception\CompositeException $exception) {
                $exceptions[] = $exception;
            }
        }

        static::assertCount(2, $exceptions);
        static::assertSame($exceptions[0], $exceptions[1]);

        $reasons = $exceptions[0]->getReasons();

        static::assertCount(2, $reasons);
        static::assertSame('first', $reasons[0]->getMessage());
        static::assertSame('second', $reasons[1]->getMessage());
    }

    public function testExceptionsAccumulateWhileTheGroupIsNotJoined(): void
    {
        $group = new Async\TaskGroup();

        $group->defer(static function (): void {
            throw new RuntimeException('first');
        });

        // The first task fails and the group becomes idle, with nobody joining it.
        Async\later();

        $group->defer(static function (): void {
            throw new RuntimeException('second');
        });

        try {
            $group->awaitAll();

            static::fail('Expected the join to throw.');
        } catch (Async\Exception\CompositeException $exception) {
            $reasons = $exception->getReasons();

            static::assertCount(2, $reasons);
            static::assertSame('first', $reasons[0]->getMessage());
            static::assertSame('second', $reasons[1]->getMessage());
        }
    }

    public function testCancelledJoinLeavesTheOutcomeToTheJoinStillWaiting(): void
    {
        $group = new Async\TaskGroup();

        $group->defer(static function (): void {
            Async\sleep(Duration::milliseconds(1));

            throw new RuntimeException('boom');
        });

        $token = new Async\SignalCancellationToken();
        $cancelled = Async\run(static function () use ($group, $token): void {
            $group->awaitAll($token);
        });

        $waiting = Async\run(static function () use ($group): void {
            $group->awaitAll();
        });

        // Both joins wait for the task.
        Async\later();
        $token->cancel();

        $cancellation = null;
        try {
            $cancelled->await();
        } catch (Async\Exception\CancelledException $exception) {
            $cancellation = $exception;
        }

        static::assertNotNull($cancellation);
        static::assertSame($token, $cancellation->getToken());

        $failure = null;
        try {
            $waiting->await();
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        static::assertNotNull($failure);
        static::assertSame('boom', $failure->getMessage());

        // The outcome was taken by the join that was still waiting.
        $group->awaitAll();
    }

    public function testTaskDeferredBeforeTheJoinsResumeBelongsToTheNextBatch(): void
    {
        $group = new Async\TaskGroup();

        $group->defer(static function () use ($group): void {
            Async\sleep(Duration::milliseconds(1));

            // Runs after this task finishes and resumes the waiting join, but before that join runs.
            Async\Scheduler::queue(static function () use ($group): void {
                $group->defer(static function (): void {
                    throw new RuntimeException('second');
                });
            });

            throw new RuntimeException('first');
        });

        // The joins run in a fiber, which resumes through the microtask queue, after the callback above.
        $messages = Async\run(static function () use ($group): array {
            $messages = [];
            for ($join = 0; $join < 2; $join++) {
                try {
                    $group->awaitAll();

                    $messages[] = 'returned';
                } catch (RuntimeException $exception) {
                    $messages[] = $exception->getMessage();
                }
            }

            return $messages;
        })->await();

        static::assertSame(['first', 'second'], $messages);
    }
}
