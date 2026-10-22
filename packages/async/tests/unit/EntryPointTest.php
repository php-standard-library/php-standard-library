<?php

declare(strict_types=1);

namespace Psl\Async\Tests\Unit;

use Closure;
use Composer\Autoload\ClassLoader;
use Fiber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl;
use Psl\Async;
use ReflectionClass;
use Throwable;

use function dirname;
use function fclose;
use function feof;
use function fread;
use function microtime;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function sprintf;
use function stream_select;
use function var_export;

use const PHP_BINARY;

/**
 * The two entry points that run the event loop, `Async\main()` and `Scheduler::run()`.
 *
 * Both must be called from {main}. The cases that need a fresh event loop use a new PHP process: there
 * the loop cannot run callbacks that other tests leave behind, `main()` can exit, and the loop may
 * never have run.
 */
final class EntryPointTest extends TestCase
{
    /**
     * The message of the exception `main()` throws inside a fiber.
     */
    private const string MAIN_IN_FIBER = 'Psl\Async\main() must be called from {main}, not inside a fiber.';

    /**
     * The message of the exception `Scheduler::run()` throws inside a fiber.
     */
    private const string RUN_IN_FIBER = 'Psl\Async\Scheduler::run() must be called from {main}, not inside a fiber.';

    /**
     * Script code that defines the callback failure, and a closure that describes what an entry
     * point threw: its class, its previous exception, and whether that is the failure itself.
     */
    private const string FAILURE_PRELUDE = <<<'PHP'
    $failure = new RuntimeException('boom');
    $describe = static function (Throwable $throwable) use ($failure): string {
        $previous = $throwable->getPrevious();

        return $throwable::class . ' <- ' . ($previous === null ? 'nothing' : $previous::class . ': '
            . $previous->getMessage() . ', same instance: ' . var_export($previous === $failure, true));
    };
    PHP;

    /**
     * How long a script may run before the test fails.
     */
    private const int TIMEOUT_SECONDS = 10;

    /**
     * How long each wait for script output lasts, so the timeout is checked between waits.
     */
    private const int POLL_MICROSECONDS = 10_000;

    /**
     * The most script output read at once.
     */
    private const int READ_CHUNK_BYTES = 8192;

    /**
     * `Scheduler::run()` throws inside a fiber, the event loop's own callbacks included, before
     * Revolt's misuse `Error` can be raised.
     *
     * @param Closure(Closure(): void): (null|Throwable) $inside
     */
    #[DataProvider('provideFibers')]
    public function testRunRejectsAFiber(Closure $inside): void
    {
        $thrown = $inside(static function (): void {
            Async\Scheduler::run();
        });

        self::assertMisuseRejected(self::RUN_IN_FIBER, $thrown);
    }

    /**
     * `main()` throws inside a fiber before it calls the closure, so none of the application runs.
     *
     * @param Closure(Closure(): void): (null|Throwable) $inside
     */
    #[DataProvider('provideFibers')]
    public function testMainRejectsAFiberBeforeTheClosureRuns(Closure $inside): void
    {
        $ran = false;
        $thrown = $inside(static function () use (&$ran): void {
            Async\main(static function () use (&$ran): int {
                $ran = true;

                return 0;
            });
        });

        static::assertFalse($ran, 'The closure ran before main() rejected the fiber.');
        self::assertMisuseRejected(self::MAIN_IN_FIBER, $thrown);
    }

    /**
     * The fibers an entry point can be called from: a task, an event loop callback, and a fiber
     * that the caller starts from {main}.
     *
     * @return iterable<string, array{Closure(Closure(): void): (null|Throwable)}>
     */
    public static function provideFibers(): iterable
    {
        yield 'task' => [
            static function (Closure $entry): null|Throwable {
                try {
                    Async\run($entry)->await();
                } catch (Throwable $throwable) {
                    return $throwable;
                }

                return null;
            },
        ];

        yield 'event loop callback' => [
            static function (Closure $entry): null|Throwable {
                /** @var Async\Deferred<null|Throwable> $outcome */
                $outcome = new Async\Deferred();
                Async\Scheduler::defer(static function () use ($entry, $outcome): void {
                    try {
                        $entry();
                        $outcome->complete(null);
                    } catch (Throwable $throwable) {
                        $outcome->complete($throwable);
                    }
                });

                return $outcome->getAwaitable()->await();
            },
        ];

        yield 'fiber started from {main}' => [
            static function (Closure $entry): null|Throwable {
                try {
                    new Fiber($entry)->start();
                } catch (Throwable $throwable) {
                    return $throwable;
                }

                return null;
            },
        ];
    }

    /**
     * Both entry points reject a fiber that the caller starts from {main} before the loop has
     * ever run, while the driver does not report that it is running.
     */
    #[DataProvider('provideEntryCalls')]
    public function testEntryPointRejectsAFiberBeforeTheLoopEverRan(string $call, string $message): void
    {
        $script = <<<'PHP'
        echo 'driver running: ', var_export(Revolt\EventLoop::getDriver()->isRunning(), true), '; ';
        try {
            new Fiber(static function (): void {
                %s
            })->start();
            echo 'returned';
        } catch (Throwable $throwable) {
            echo $throwable::class, ': ', $throwable->getMessage();
        }
        PHP;

        [$output, $status] = self::runScript(sprintf($script, $call));

        static::assertSame('driver running: false; Psl\Async\Exception\LogicException: ' . $message, $output);
        static::assertSame(0, $status);
    }

    /**
     * A call of each entry point, and the message of the exception it throws inside a fiber.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideEntryCalls(): iterable
    {
        yield 'main()' => ['Psl\Async\main(static fn(): int => 0);', self::MAIN_IN_FIBER];
        yield 'Scheduler::run()' => ['Psl\Async\Scheduler::run();', self::RUN_IN_FIBER];
    }

    /**
     * The misuse exception is an open category: code built on async may extend it.
     */
    public function testLogicExceptionIsOpen(): void
    {
        static::assertFalse(new ReflectionClass(Async\Exception\LogicException::class)->isFinal());
    }

    /**
     * `main()` exits with the exit code the closure returns.
     */
    public function testMainExitsWithTheCodeTheClosureReturns(): void
    {
        [$output, $status] = self::runScript('Psl\Async\main(static fn(): int => 3);');

        static::assertSame('', $output);
        static::assertSame(3, $status);
    }

    /**
     * After awaiting the closure's awaitable, `main()` runs the loop while it is suspended to
     * {main}, a state in which the driver reports that it is running, and drains the work left.
     */
    public function testMainAwaitsTheAwaitableThenDrainsTheLoop(): void
    {
        [$output, $status] = self::runScript(<<<'PHP'
        Psl\Async\main(static function (): Psl\Async\Awaitable {
            Psl\Async\run(static function (): void {
                Psl\Async\later();
            })->await();
            echo 'driver running: ', var_export(Revolt\EventLoop::getDriver()->isRunning(), true), '; ';

            // Work that only a loop run after the await can finish.
            $ticks = 0;
            $tick = static function () use (&$tick, &$ticks): void {
                if (++$ticks < 5) {
                    Psl\Async\Scheduler::defer($tick);
                } else {
                    echo 'drained after ', $ticks, ' ticks';
                }
            };
            Psl\Async\Scheduler::defer($tick);

            return Psl\Async\run(static function (): int {
                Psl\Async\later();
                echo 'awaited; ';

                return 4;
            });
        });
        PHP);

        static::assertSame('driver running: true; awaited; drained after 5 ticks', $output);
        static::assertSame(4, $status);
    }

    /**
     * `Scheduler::run()` runs from {main} after an await there, while the loop is suspended to
     * {main}, and returns once no callback is left.
     */
    public function testRunWorksAfterAnAwaitInMain(): void
    {
        [$output, $status] = self::runScript(<<<'PHP'
        Psl\Async\run(static function (): void {
            Psl\Async\later();
        })->await();
        echo 'driver running: ', var_export(Revolt\EventLoop::getDriver()->isRunning(), true), '; ';
        Psl\Async\Scheduler::defer(static function (): void {
            echo 'deferred ran; ';
        });
        Psl\Async\Scheduler::run();
        echo 'returned';
        PHP);

        static::assertSame('driver running: true; deferred ran; returned', $output);
        static::assertSame(0, $status);
    }

    /**
     * A callback failure that Revolt reports from the loop reaches the caller of either entry
     * point as Revolt's own `UncaughtThrowable`, with the failure itself as its previous exception.
     */
    #[DataProvider('provideCallbackFailureScripts')]
    public function testCallbackFailureReachesTheCaller(string $script): void
    {
        [$output, $status] = self::runScript(self::FAILURE_PRELUDE . $script);

        static::assertSame(
            'Revolt\EventLoop\UncaughtThrowable <- RuntimeException: boom, same instance: true',
            $output,
        );
        static::assertSame(0, $status);
    }

    /**
     * Scripts that let a callback throw while an entry point runs the loop, then print what the
     * entry point threw.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideCallbackFailureScripts(): iterable
    {
        yield 'Scheduler::run()' => [
            <<<'PHP'
            Psl\Async\Scheduler::defer(static function () use ($failure): void {
                throw $failure;
            });

            try {
                Psl\Async\Scheduler::run();
                echo 'returned';
            } catch (Throwable $throwable) {
                echo $describe($throwable);
            }
            PHP,
        ];

        yield 'main()' => [
            <<<'PHP'
            try {
                Psl\Async\main(static function () use ($failure): int {
                    Psl\Async\Scheduler::defer(static function () use ($failure): void {
                        throw $failure;
                    });

                    return 0;
                });
            } catch (Throwable $throwable) {
                echo $describe($throwable);
            }
            PHP,
        ];
    }

    /**
     * Asserts that an entry point threw the async misuse exception with the given message, and that
     * the exception is both one of PSL's logic exceptions and an async exception.
     */
    private static function assertMisuseRejected(string $message, null|Throwable $thrown): void
    {
        static::assertInstanceOf(Psl\Exception\LogicException::class, $thrown);
        static::assertInstanceOf(Async\Exception\ExceptionInterface::class, $thrown);
        static::assertInstanceOf(Async\Exception\LogicException::class, $thrown);
        static::assertSame($message, $thrown->getMessage());
    }

    /**
     * Runs the script in a fresh PHP process with the autoloader loaded.
     *
     * @return array{string, int} The output, and the exit status.
     */
    private static function runScript(string $script): array
    {
        $loader = new ReflectionClass(ClassLoader::class)->getFileName();
        static::assertIsString($loader);

        $code = 'require ' . var_export(dirname($loader, 2) . '/autoload.php', true) . ';' . $script;
        $process = proc_open(
            [PHP_BINARY, '-dopcache.enable=0', '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
        );
        static::assertNotFalse($process);
        fclose($pipes[0]);

        // An entry point that no longer returns might block for good; fail instead of hanging.
        $output = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        while (!feof($pipes[1])) {
            if (microtime(true) >= $deadline) {
                fclose($pipes[1]);
                proc_terminate($process);
                proc_close($process);

                static::fail('The script did not finish in time. Output so far: ' . $output);
            }

            $read = [$pipes[1]];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, self::POLL_MICROSECONDS) > 0) {
                $output .= (string) fread($pipes[1], self::READ_CHUNK_BYTES);
            }
        }

        fclose($pipes[1]);

        return [$output, proc_close($process)];
    }
}
