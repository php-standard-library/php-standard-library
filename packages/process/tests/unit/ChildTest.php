<?php

declare(strict_types=1);

namespace Psl\Process\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime;
use Psl\DateTime\Duration;
use Psl\Env;
use Psl\Filesystem;
use Psl\IO;
use Psl\OS;
use Psl\Process\Command;
use Psl\Process\Exception;
use Psl\Process\Signal;
use Psl\Process\Stdio;

use function strlen;

use const PHP_BINARY;

final class ChildTest extends TestCase
{
    /**
     * Create a PHP command with JIT warnings suppressed.
     *
     * When pcov is loaded alongside opcache, PHP emits a JIT incompatibility
     * warning to stdout which corrupts output assertions.
     */
    private static function phpCommand(): Command
    {
        return Command::create(PHP_BINARY)->withArgument('-dopcache.enable=0');
    }

    public function testSpawnAndWait(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('exit(0);')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        $status = $child->wait();

        static::assertTrue($status->isSuccessful());
        static::assertSame(0, $status->getCode());
    }

    public function testSpawnAndWaitWithOutput(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "out"; fwrite(STDERR, "err");')
            ->spawn()
            ->waitWithOutput();

        static::assertSame('out', $output->stdout);
        static::assertSame('err', $output->stderr);
        static::assertTrue($output->status->isSuccessful());
    }

    public function testCommandOutput(): void
    {
        $output = self::phpCommand()->withArgument('-r')->withArgument('echo "hello world";')->output();

        static::assertSame('hello world', $output->stdout);
        static::assertSame('', $output->stderr);
        static::assertTrue($output->status->isSuccessful());
    }

    public function testCommandStatus(): void
    {
        $status = self::phpCommand()->withArgument('-r')->withArgument('exit(0);')->status();

        static::assertTrue($status->isSuccessful());
    }

    public function testCommandStatusWithNonZeroExit(): void
    {
        $status = self::phpCommand()->withArgument('-r')->withArgument('exit(42);')->status();

        static::assertFalse($status->isSuccessful());
        static::assertSame(42, $status->getCode());
    }

    public function testGetProcessId(): void
    {
        $child = self::phpCommand()->withArgument('-r')->withArgument('usleep(100000);')->spawn();

        $pid = $child->getProcessId();
        static::assertGreaterThan(0, $pid);

        $child->wait();
    }

    public function testIsRunning(): void
    {
        $child = self::phpCommand()->withArgument('-r')->withArgument('usleep(500000);')->spawn();

        static::assertTrue($child->isRunning());

        $child->kill();
        $child->wait();

        static::assertFalse($child->isRunning());
    }

    public function testKill(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Signal tests are not reliable on Windows.');
        }

        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('sleep(60);')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        static::assertTrue($child->isRunning());

        $child->kill();
        $status = $child->wait();

        static::assertFalse($child->isRunning());
        static::assertFalse($status->isSuccessful());
    }

    public function testSignal(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Signal tests are not reliable on Windows.');
        }

        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('sleep(60);')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        $child->signal(Signal::Terminate);
        $status = $child->wait();

        static::assertFalse($status->isSuccessful());
    }

    public function testWaitReportsTerminationSignal(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Signal tests are not reliable on Windows.');
        }

        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('sleep(60);')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        $child->signal(Signal::Terminate);
        $status = $child->wait();

        static::assertTrue($status->hasBeenSignaled());
        static::assertSame(Signal::Terminate, $status->getTerminationSignal());
    }

    public function testWaitWithOutputReportsTerminationSignal(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Signal tests are not reliable on Windows.');
        }

        $child = self::phpCommand()->withArgument('-r')->withArgument('sleep(60);')->spawn();

        $child->kill();
        $output = $child->waitWithOutput();

        static::assertTrue($output->status->hasBeenSignaled());
        static::assertSame(Signal::Kill, $output->status->getTerminationSignal());
    }

    public function testTryWaitReportsTerminationSignal(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Signal tests are not reliable on Windows.');
        }

        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('sleep(60);')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        $child->kill();
        while (null === ($status = $child->tryWait())) {
            Async\sleep(Duration::milliseconds(5));
        }

        static::assertTrue($status->hasBeenSignaled());
        static::assertSame(Signal::Kill, $status->getTerminationSignal());
        static::assertGreaterThan(0, $child->getProcessId());
    }

    public function testSignalOnExitedProcessIsNoop(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('exit(0);')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        $child->wait();

        $child->signal(Signal::Terminate);

        static::assertFalse($child->isRunning());
    }

    public function testWaitTimeout(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('sleep(60);')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        $this->expectException(Async\Exception\CancelledException::class);

        try {
            $child->wait(new Async\TimeoutCancellationToken(Duration::milliseconds(100)));
        } finally {
            // Ensure cleanup.
            if ($child->isRunning()) {
                $child->kill();
                $child->wait();
            }
        }
    }

    public function testWaitWithOutputTimeout(): void
    {
        $child = self::phpCommand()->withArgument('-r')->withArgument('sleep(60);')->spawn();

        $this->expectException(Async\Exception\CancelledException::class);

        $child->waitWithOutput(new Async\TimeoutCancellationToken(Duration::milliseconds(100)));
    }

    public function testCommandOutputTimeout(): void
    {
        $this->expectException(Async\Exception\CancelledException::class);

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('sleep(60);')
            ->output(new Async\TimeoutCancellationToken(Duration::milliseconds(100)));
    }

    public function testCommandStatusTimeout(): void
    {
        $this->expectException(Async\Exception\CancelledException::class);

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('sleep(60);')
            ->status(new Async\TimeoutCancellationToken(Duration::milliseconds(100)));
    }

    public function testTryWaitWhileRunning(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('usleep(500000);')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        $result = $child->tryWait();
        static::assertNull($result);

        $child->kill();
        $child->wait();
    }

    public function testTryWaitAfterExit(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('exit(0);')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        // Wait a bit for the process to exit.
        $child->wait();

        $result = $child->tryWait();
        static::assertNotNull($result);
        static::assertTrue($result->isSuccessful());
    }

    public function testStdinUnavailable(): void
    {
        $child = self::phpCommand()->withArgument('-r')->withArgument('exit(0);')->spawn();

        $this->expectException(Exception\StreamUnavailableException::class);
        $this->expectExceptionMessage('Stdin is not available');

        $child->getStdin();
    }

    public function testStdoutUnavailableWhenNull(): void
    {
        $child = self::phpCommand()->withArgument('-r')->withArgument('exit(0);')->withStdout(Stdio::null())->spawn();

        $this->expectException(Exception\StreamUnavailableException::class);

        $child->getStdout();
    }

    public function testStderrUnavailableWhenNull(): void
    {
        $child = self::phpCommand()->withArgument('-r')->withArgument('exit(0);')->withStderr(Stdio::null())->spawn();

        $this->expectException(Exception\StreamUnavailableException::class);

        $child->getStderr();
    }

    public function testEnvironmentVariables(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo getenv("PSL_TEST_VAR");')
            ->withEnvironmentVariable('PSL_TEST_VAR', 'hello_from_psl')
            ->output();

        static::assertSame('hello_from_psl', $output->stdout);
    }

    public function testWorkingDirectory(): void
    {
        $tempDir = Env\temp_dir();

        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo getcwd();')
            ->withWorkingDirectory($tempDir)
            ->output();

        static::assertSame(Filesystem\canonicalize($tempDir), Filesystem\canonicalize($output->stdout));
    }

    public function testWaitCalledTwiceReturnsSameStatus(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('exit(0);')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        $status1 = $child->wait();
        $status2 = $child->wait();

        static::assertSame($status1, $status2);
    }

    public function testWaitWithOutputCalledAfterWait(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('exit(0);')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        $status = $child->wait();
        $output = $child->waitWithOutput();

        static::assertSame($status->getCode(), $output->status->getCode());
        static::assertSame('', $output->stdout);
        static::assertSame('', $output->stderr);
    }

    public function testStdinPiped(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo fgets(STDIN);')
            ->withStdin(Stdio::piped())
            ->spawn();

        $child->getStdin()->writeAll("hello from stdin\n");
        $child->getStdin()->close();

        $output = $child->getStdout()->readAll();
        $child->wait();

        static::assertSame("hello from stdin\n", $output);
    }

    public function testFromStreamHandle(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped(
                'IO\pipe() uses TCP sockets on Windows which are not inheritable by child processes.',
            );
        }

        [$read, $write] = IO\pipe();
        $write->writeAll("piped input\n");
        $write->close();

        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo fgets(STDIN);')
            ->withStdin(Stdio::fromStreamHandle($read))
            ->spawn();

        $output = $child->getStdout()->readAll();
        $child->wait();

        $read->close();

        static::assertSame("piped input\n", $output);
    }

    public function testLargeOutput(): void
    {
        $output = self::phpCommand()->withArgument('-r')->withArgument('echo str_repeat("x", 100000);')->output();

        static::assertSame(100_000, strlen($output->stdout));
        static::assertTrue($output->status->isSuccessful());
    }

    public function testShellCommand(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Shell command test uses Unix syntax.');
        }

        $output = Command::shell('echo hello && echo world')->output();

        static::assertStringContainsString('hello', $output->stdout);
        static::assertStringContainsString('world', $output->stdout);
        static::assertTrue($output->status->isSuccessful());
    }

    public function testStdoutAndStderrSeparation(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('fwrite(STDOUT, "stdout"); fwrite(STDERR, "stderr");')
            ->output();

        static::assertSame('stdout', $output->stdout);
        static::assertSame('stderr', $output->stderr);
    }

    public function testWaitTimeoutKillsProcess(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('sleep(60);')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        try {
            $child->wait(new Async\TimeoutCancellationToken(Duration::milliseconds(200)));
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::assertFalse($child->isRunning());
        }
    }

    public function testWaitWithOutputTimeoutKillsProcess(): void
    {
        $child = self::phpCommand()->withArgument('-r')->withArgument('sleep(60);')->spawn();

        try {
            $child->waitWithOutput(new Async\TimeoutCancellationToken(Duration::milliseconds(200)));
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::assertFalse($child->isRunning());
        }
    }

    public function testTimeoutWhileChildWaitsForStdin(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('fread(STDIN, 1); sleep(60);')
            ->withStdin(Stdio::piped())
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->spawn();

        try {
            $child->wait(new Async\TimeoutCancellationToken(Duration::milliseconds(200)));
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::assertFalse($child->isRunning());
        }
    }

    public function testTimeoutDoesNotAffectFastProcess(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "fast";')
            ->output(new Async\TimeoutCancellationToken(Duration::seconds(2)));

        static::assertSame('fast', $output->stdout);
        static::assertTrue($output->status->isSuccessful());
    }

    public function testTimeoutDoesNotAffectFastProcessStatus(): void
    {
        $status = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('exit(0);')
            ->status(new Async\TimeoutCancellationToken(Duration::seconds(2)));

        static::assertTrue($status->isSuccessful());
    }

    public function testPartialStdoutCapturedBeforeTimeout(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Timing-sensitive test unreliable on Windows CI.');
        }

        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "1"; flush(); usleep(50000); echo "2"; flush(); sleep(2);')
            ->spawn();

        $stdout = '';
        try {
            foreach (IO\streaming([
                1 => $child->getStdout(),
            ], new Async\TimeoutCancellationToken(Duration::milliseconds(500))) as $chunk) {
                if ('' === $chunk) {
                    continue;
                }

                $stdout .= $chunk;
            }
        } catch (Async\Exception\CancelledException) {
            // @mago-expect lint:no-empty-catch-clause - Expected
        }

        static::assertSame('12', $stdout);

        $child->kill();
        $child->wait();
    }

    public function testPartialStdoutAndStderrCapturedBeforeTimeout(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Timing-sensitive test unreliable on Windows CI.');
        }

        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('fwrite(STDOUT, "out"); fwrite(STDERR, "err"); sleep(2);')
            ->spawn();

        $stdout = '';
        $stderr = '';
        try {
            foreach (IO\streaming([
                1 => $child->getStdout(),
                2 => $child->getStderr(),
            ], new Async\TimeoutCancellationToken(Duration::milliseconds(500))) as $type => $chunk) {
                if ('' === $chunk) {
                    continue;
                }

                if (1 === $type) {
                    $stdout .= $chunk;

                    continue;
                }

                $stderr .= $chunk;
            }
        } catch (Async\Exception\CancelledException) {
            // @mago-expect lint:no-empty-catch-clause - Expected
        }

        static::assertSame('out', $stdout);
        static::assertSame('err', $stderr);

        $child->kill();
        $child->wait();
    }

    public function testLargeOutputCapturedBeforeTimeout(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Timing-sensitive test unreliable on Windows CI.');
        }

        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo str_repeat("x", 10000); sleep(2);')
            ->spawn();

        $stdout = '';
        try {
            foreach (IO\streaming([
                1 => $child->getStdout(),
            ], new Async\TimeoutCancellationToken(Duration::milliseconds(500))) as $chunk) {
                if ('' === $chunk) {
                    continue;
                }

                $stdout .= $chunk;
            }
        } catch (Async\Exception\CancelledException) {
            // @mago-expect lint:no-empty-catch-clause - Expected
        }

        static::assertSame(10_000, strlen($stdout));

        $child->kill();
        $child->wait();
    }

    public function testOutputTimeoutOnSlowProducer(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped(
                'IO\streaming() timeout is unreliable on Windows when data is actively flowing through pipes.',
            );
        }

        $this->expectException(Async\Exception\CancelledException::class);

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('for ($i = 0; $i < 100; $i++) { echo $i; usleep(100000); }')
            ->output(new Async\TimeoutCancellationToken(Duration::milliseconds(200)));
    }

    public function testStatusTimeoutWhileChildOutputsAndSleeps(): void
    {
        $this->expectException(Async\Exception\CancelledException::class);

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo str_repeat("x", 10000); sleep(2);')
            ->status(new Async\TimeoutCancellationToken(Duration::milliseconds(200)));
    }

    public function testMultipleChunksBeforeTimeout(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Timing-sensitive test unreliable on Windows CI.');
        }

        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('for ($i = 1; $i <= 5; $i++) { echo $i; } flush(); sleep(2);')
            ->spawn();

        $stdout = '';
        try {
            foreach (IO\streaming([
                1 => $child->getStdout(),
            ], new Async\TimeoutCancellationToken(Duration::milliseconds(500))) as $chunk) {
                if ('' === $chunk) {
                    continue;
                }

                $stdout .= $chunk;
            }
        } catch (Async\Exception\CancelledException) {
            // @mago-expect lint:no-empty-catch-clause - Expected
        }

        static::assertSame('12345', $stdout);

        $child->kill();
        $child->wait();
    }

    public function testWaitWithOutputTimeoutAfterPartialOutput(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped(
                'IO\streaming() timeout is unreliable on Windows when data is actively flowing through pipes.',
            );
        }

        $child = self::phpCommand()->withArgument('-r')->withArgument('echo "partial"; sleep(2);')->spawn();

        try {
            $child->waitWithOutput(new Async\TimeoutCancellationToken(Duration::milliseconds(200)));
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::assertFalse($child->isRunning());
        }
    }

    public function testConcurrentOutput(): void
    {
        $run = static function (): void {
            self::phpCommand()->withArgument('-r')->withArgument('usleep(100000); echo "done";')->output();
        };

        $start = DateTime\Timestamp::monotonic();
        Async\concurrently([$run, $run]);
        $elapsed = DateTime\Timestamp::monotonic()->since($start);

        static::assertLessThan(0.5, $elapsed->getTotalSeconds());
    }

    public function testConcurrentStatus(): void
    {
        $run = static function (): void {
            self::phpCommand()->withArgument('-r')->withArgument('usleep(100000);')->status();
        };

        $start = DateTime\Timestamp::monotonic();
        Async\concurrently([$run, $run]);
        $elapsed = DateTime\Timestamp::monotonic()->since($start);

        static::assertLessThan(0.5, $elapsed->getTotalSeconds());
    }

    public function testConcurrentWait(): void
    {
        $run = static function (): void {
            $child = self::phpCommand()
                ->withArgument('-r')
                ->withArgument('usleep(100000);')
                ->withStdout(Stdio::null())
                ->withStderr(Stdio::null())
                ->spawn();

            $child->wait();
        };

        $start = DateTime\Timestamp::monotonic();
        Async\concurrently([$run, $run]);
        $elapsed = DateTime\Timestamp::monotonic()->since($start);

        static::assertLessThan(0.5, $elapsed->getTotalSeconds());
    }
}
