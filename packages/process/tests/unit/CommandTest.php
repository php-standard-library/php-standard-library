<?php

declare(strict_types=1);

namespace Psl\Process\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Env;
use Psl\Filesystem;
use Psl\IO;
use Psl\OS;
use Psl\Process\Command;
use Psl\Process\Exception;
use Psl\Process\Stdio;

use const PHP_BINARY;

final class CommandTest extends TestCase
{
    /**
     * Create a PHP command.
     *
     * When pcov is loaded alongside opcache, PHP emits a JIT incompatibility
     * warning to stdout which corrupts output assertions.
     */
    private static function phpCommand(): Command
    {
        return Command::create(PHP_BINARY)->withArgument('-dopcache.enable=0');
    }

    public function testCreate(): void
    {
        $command = Command::create('git');

        static::assertSame('git', $command->getProgram());
        static::assertSame([], $command->getArguments());
        static::assertSame([], $command->getEnvironmentVariables());
        static::assertNull($command->getWorkingDirectory());
    }

    public function testShell(): void
    {
        $command = Command::shell('echo hello');

        static::assertSame('echo hello', $command->getProgram());
        static::assertSame([], $command->getArguments());
    }

    public function testCreatePassesArgumentsSeparately(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo $argv[1];')
            ->withArgument('hello world & echo injected')
            ->output();

        static::assertSame('hello world & echo injected', $output->stdout);
    }

    public function testShellInterpretsPipesAndOperators(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Shell syntax test uses Unix shell features.');
        }

        // With shell(), the command is interpreted by /bin/sh -c.
        $output = Command::shell('echo hello && echo world')->output();

        static::assertStringContainsString('hello', $output->stdout);
        static::assertStringContainsString('world', $output->stdout);
    }

    public function testWithArgument(): void
    {
        $command = Command::create('git')->withArgument('status');

        static::assertSame(['status'], $command->getArguments());
    }

    public function testWithArgumentIsAdditive(): void
    {
        $command = Command::create('git')->withArgument('log')->withArgument('--oneline');

        static::assertSame(['log', '--oneline'], $command->getArguments());
    }

    public function testWithArgumentAdditiveExecution(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo implode(",", array_slice($argv, 1));')
            ->withArgument('--')
            ->withArgument('first')
            ->withArgument('second')
            ->withArgument('third')
            ->output();

        static::assertSame('first,second,third', $output->stdout);
    }

    public function testWithArguments(): void
    {
        $command = Command::create('git')->withArguments(['log', '--oneline']);

        static::assertSame(['log', '--oneline'], $command->getArguments());
    }

    public function testWithArgumentsIsAdditive(): void
    {
        $command = Command::create('git')->withArgument('log')->withArguments(['--oneline', '-n', '10']);

        static::assertSame(['log', '--oneline', '-n', '10'], $command->getArguments());
    }

    public function testWithEnvironmentVariable(): void
    {
        $command = Command::create('env')->withEnvironmentVariable('FOO', 'bar');

        static::assertSame(['FOO' => 'bar'], $command->getEnvironmentVariables());
    }

    public function testWithEnvironmentVariableExecution(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo getenv("MY_TEST_VAR");')
            ->withEnvironmentVariable('MY_TEST_VAR', 'from_with_env_var')
            ->output();

        static::assertSame('from_with_env_var', $output->stdout);
    }

    public function testWithEnvironmentVariables(): void
    {
        $command = Command::create('env')->withEnvironmentVariables(['FOO' => 'bar', 'BAZ' => 'qux']);

        static::assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $command->getEnvironmentVariables());
    }

    public function testWithEnvironmentVariablesMerges(): void
    {
        $command = Command::create('env')
            ->withEnvironmentVariable('FOO', 'bar')
            ->withEnvironmentVariables(['BAZ' => 'qux']);

        static::assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $command->getEnvironmentVariables());
    }

    public function testWithoutEnvironmentVariable(): void
    {
        $command = Command::create('env')
            ->withEnvironmentVariables(['FOO' => 'bar', 'BAZ' => 'qux'])
            ->withoutEnvironmentVariable('FOO');

        static::assertSame(['BAZ' => 'qux'], $command->getEnvironmentVariables());
    }

    public function testWithClearedEnvironment(): void
    {
        $command = Command::create('env')->withEnvironmentVariables(['FOO' => 'bar'])->withClearedEnvironment();

        static::assertSame([], $command->getEnvironmentVariables());
    }

    public function testWithWorkingDirectory(): void
    {
        $command = Command::create('ls')->withWorkingDirectory('/tmp');

        static::assertSame('/tmp', $command->getWorkingDirectory());
    }

    public function testWorkingDirectoryIsUsed(): void
    {
        $tempDir = Env\temp_dir();

        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo getcwd();')
            ->withWorkingDirectory($tempDir)
            ->output();

        static::assertSame(Filesystem\canonicalize($tempDir), Filesystem\canonicalize($output->stdout));
    }

    public function testImmutability(): void
    {
        $original = Command::create('git');
        $modified = $original->withArgument('status');

        static::assertSame([], $original->getArguments());
        static::assertSame(['status'], $modified->getArguments());
    }

    public function testSpawnInvalidWorkingDirectory(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Working directory does not exist.');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withWorkingDirectory('/nonexistent/path/that/does/not/exist')
            ->spawn();
    }

    public function testOutputInvalidWorkingDirectory(): void
    {
        $this->expectException(Exception\RuntimeException::class);

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withWorkingDirectory('/nonexistent/path/that/does/not/exist')
            ->output();
    }

    public function testStatusInvalidWorkingDirectory(): void
    {
        $this->expectException(Exception\RuntimeException::class);

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withWorkingDirectory('/nonexistent/path/that/does/not/exist')
            ->status();
    }

    public function testEmptyWorkingDirectoryThrows(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Working directory does not exist.');

        self::phpCommand()->withArgument('-r')->withArgument('echo "hello";')->withWorkingDirectory('')->spawn();
    }

    public function testNullByteInCommand(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Command line contains NULL bytes.');

        Command::create("test\0command")->spawn();
    }

    public function testNullByteInArgument(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Command line contains NULL bytes.');

        self::phpCommand()->withArgument('-r')->withArgument("echo\0injected;")->spawn();
    }

    public function testNullByteInWorkingDirectory(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Working directory contains NULL bytes.');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withWorkingDirectory(Env\temp_dir() . "\0")
            ->spawn();
    }

    public function testOutputNullByteInWorkingDirectory(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Working directory contains NULL bytes.');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "hello";')
            ->withWorkingDirectory(Env\temp_dir() . "\0suffix")
            ->output();
    }

    public function testStatusNullByteInWorkingDirectory(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Working directory contains NULL bytes.');

        self::phpCommand()->withArgument('-r')->withArgument('echo "hello";')->withWorkingDirectory("\0")->status();
    }

    public function testStdinPiped(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo fgets(STDIN);')
            ->withStdin(Stdio::piped())
            ->spawn();

        $child->getStdin()->writeAll("hello\n");
        $child->getStdin()->close();

        $output = $child->getStdout()->readAll();
        $child->wait();

        static::assertSame("hello\n", $output);
    }

    public function testStdoutPipedCapturesOutput(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "from_stdout";')
            ->withStdout(Stdio::piped())
            ->withStderr(Stdio::null())
            ->spawn();

        $stdout = $child->getStdout()->readAll();
        $child->wait();

        static::assertSame('from_stdout', $stdout);
    }

    public function testStderrPipedCapturesError(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('fwrite(STDERR, "from_stderr");')
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::piped())
            ->spawn();

        $stderr = $child->getStderr()->readAll();
        $child->wait();

        static::assertSame('from_stderr', $stderr);
    }

    public function testStdoutAndStderrSeparation(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('fwrite(STDOUT, "OUT"); fwrite(STDERR, "ERR");')
            ->output();

        static::assertSame('OUT', $output->stdout);
        static::assertSame('ERR', $output->stderr);
    }

    public function testStdoutNull(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "test";')
            ->withStdout(Stdio::null())
            ->spawn();

        $this->expectException(Exception\StreamUnavailableException::class);

        $child->getStdout();
    }

    public function testStderrNull(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "test";')
            ->withStderr(Stdio::null())
            ->spawn();

        $this->expectException(Exception\StreamUnavailableException::class);

        $child->getStderr();
    }

    public function testDescriptorStdinNullIsDevNull(): void
    {
        // With Stdio::null() for stdin, the child reads EOF immediately.
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('$data = fread(STDIN, 1024); echo strlen($data);')
            ->output();

        static::assertSame('0', $output->stdout);
    }

    public function testHandleStdio(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped(
                'IO\pipe() uses TCP sockets on Windows which are not inheritable by child processes.',
            );
        }

        [$read, $write] = IO\pipe();
        $write->writeAll("handle_input\n");
        $write->close();

        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo trim(fgets(STDIN));')
            ->withStdin(Stdio::fromStreamHandle($read))
            ->spawn();

        $stdout = $child->getStdout()->readAll();
        $child->wait();
        $read->close();

        static::assertSame('handle_input', $stdout);
    }

    public function testHandleStdioClosedThrows(): void
    {
        [$read, $write] = IO\pipe();
        $read->close();
        $write->close();

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('The stream handle is closed.');

        self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "test";')
            ->withStdin(Stdio::fromStreamHandle($read))
            ->spawn();
    }

    public function testOutputForcesStdinNullAndPipedStdoutStderr(): void
    {
        $output = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('fwrite(STDOUT, "out"); fwrite(STDERR, "err");')
            ->withStdin(Stdio::piped())
            ->withStdout(Stdio::null())
            ->withStderr(Stdio::null())
            ->output();

        static::assertSame('out', $output->stdout);
        static::assertSame('err', $output->stderr);
    }

    public function testStatusForcesAllNull(): void
    {
        $status = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('exit(42);')
            ->withStdout(Stdio::piped())
            ->withStderr(Stdio::piped())
            ->status();

        static::assertFalse($status->isSuccessful());
        static::assertSame(42, $status->getCode());
    }

    public function testSpawnUsesConfiguredStdio(): void
    {
        $child = self::phpCommand()
            ->withArgument('-r')
            ->withArgument('echo "spawned";')
            ->withStdout(Stdio::piped())
            ->withStderr(Stdio::null())
            ->spawn();

        $stdout = $child->getStdout()->readAll();
        $child->wait();

        static::assertSame('spawned', $stdout);

        $this->expectException(Exception\StreamUnavailableException::class);

        $child->getStderr();
    }

    public function testTtyStdioOnWindows(): void
    {
        if (!OS\is_windows()) {
            static::markTestSkipped('Test is for Windows TTY behavior.');
        }

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('TTY is not supported on Windows.');

        self::phpCommand()->withArgument('-r')->withArgument('echo "test";')->withStdout(Stdio::tty())->spawn();
    }
}
