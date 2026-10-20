<?php

declare(strict_types=1);

namespace Psl\Shell\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime;
use Psl\DateTime\Duration;
use Psl\Env;
use Psl\OS;
use Psl\SecureRandom;
use Psl\Shell;

use function pack;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

final class ExecuteTest extends TestCase
{
    public function testExecute(): void
    {
        static::assertSame('Hello, World!', Shell\execute(PHP_BINARY, [
            '-dopcache.enable=0',
            '-r',
            'echo "Hello, World!";',
        ]));
    }

    public function testFailedExecution(): void
    {
        try {
            Shell\execute('php', ['-r', 'write("Hello, World!");']);
        } catch (Shell\Exception\FailedExecutionException $exception) {
            static::assertSame(255, $exception->getCode());
            static::assertStringContainsString('Call to undefined function write()', $exception->getErrorOutput());
            static::assertStringContainsString('php', $exception->getCommand());
        }
    }

    public function testItThrowsForNULLByte(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Test can only be executed under *nix OS.');
        }

        $this->expectException(Shell\Exception\PossibleAttackException::class);

        Shell\execute('php', ["\0"]);
    }

    public function testItThrowsForNULLByteInCommand(): void
    {
        $this->expectException(Shell\Exception\PossibleAttackException::class);
        $this->expectExceptionMessage('NULL byte detected.');

        Shell\execute("php\0injected");
    }

    public function testEnvironmentIsPassedDownToTheProcess(): void
    {
        static::assertSame('BAR', Shell\execute(PHP_BINARY, ['-dopcache.enable=0', '-r', 'echo getenv("FOO");'], null, [
            'FOO' => 'BAR',
        ]));
    }

    public function testCurrentEnvironmentVariablesArePassedDownToTheProcess(): void
    {
        try {
            Env\set_var('FOO', 'BAR');

            static::assertSame('BAR', Shell\execute(PHP_BINARY, ['-dopcache.enable=0', '-r', 'echo getenv("FOO");']));
        } finally {
            Env\remove_var('FOO');
        }
    }

    public function testWorkingDirectoryIsUsed(): void
    {
        $temp = Env\temp_dir();
        $result = Shell\execute(PHP_BINARY, ['-dopcache.enable=0', '-r', 'echo getcwd();'], $temp);

        static::assertStringEndsWith($temp, $result);
    }

    public function testCurrentDirectoryIsUsedByDefault(): void
    {
        $dir = Env\current_dir();

        static::assertSame($dir, Shell\execute(PHP_BINARY, ['-dopcache.enable=0', '-r', 'echo getcwd();']));
    }

    public function testItThrowsWhenWorkingDirectoryDoesntExist(): void
    {
        $dir = Env\current_dir() . DIRECTORY_SEPARATOR . SecureRandom\string(6);

        $this->expectException(Shell\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Working directory does not exist.');

        Shell\execute(PHP_BINARY, ['-dopcache.enable=0', '-r', 'echo getcwd();'], $dir);
    }

    public function testItThrowsWhenWorkingDirectoryContainsNULLByte(): void
    {
        try {
            Shell\execute(PHP_BINARY, ['-dopcache.enable=0', '-r', 'echo getcwd();'], Env\temp_dir() . "\0");

            static::fail('Expected a RuntimeException to be thrown.');
        } catch (Shell\Exception\RuntimeException $exception) {
            static::assertSame(Shell\Exception\RuntimeException::class, $exception::class);
            static::assertSame('Working directory contains NULL bytes.', $exception->getMessage());
        }
    }

    public function testItThrowsWhenEnvironmentVariableContainsNULLByte(): void
    {
        try {
            Shell\execute(PHP_BINARY, ['-dopcache.enable=0', '-r', 'echo getenv("FOO");'], null, [
                'FOO' => "visible\0hidden",
            ]);

            static::fail('Expected a RuntimeException to be thrown.');
        } catch (Shell\Exception\RuntimeException $exception) {
            static::assertSame(Shell\Exception\RuntimeException::class, $exception::class);
            static::assertSame('Environment variable contains NULL bytes.', $exception->getMessage());
        }
    }

    public function testErrorOutputIsDiscarded(): void
    {
        $result = Shell\execute(PHP_BINARY, [
            '-dopcache.enable=0',
            '-r',
            'fwrite(STDOUT, "hello"); fwrite(STDERR, " world");',
        ]);

        static::assertSame('hello', $result);

        $result = Shell\execute(
            PHP_BINARY,
            ['-dopcache.enable=0', '-r', 'fwrite(STDOUT, "hello"); fwrite(STDERR, " world");'],
            errorOutputBehavior: Shell\ErrorOutputBehavior::default(),
        );

        static::assertSame('hello', $result);
    }

    public function testErrorOutputIsAppended(): void
    {
        $result = Shell\execute(
            PHP_BINARY,
            ['-dopcache.enable=0', '-r', 'fwrite(STDOUT, "hello"); fwrite(STDERR, " world");'],
            errorOutputBehavior: Shell\ErrorOutputBehavior::Append,
        );

        static::assertSame('hello world', $result);
    }

    public function testErrorOutputIsPrepended(): void
    {
        $result = Shell\execute(
            PHP_BINARY,
            ['-dopcache.enable=0', '-r', 'fwrite(STDOUT, "hello"); fwrite(STDERR, " world");'],
            errorOutputBehavior: Shell\ErrorOutputBehavior::Prepend,
        );

        static::assertSame(' worldhello', $result);
    }

    public function testErrorOutputIsReplacingStandardOutput(): void
    {
        $result = Shell\execute(
            PHP_BINARY,
            ['-dopcache.enable=0', '-r', 'fwrite(STDOUT, "hello"); fwrite(STDERR, " world");'],
            errorOutputBehavior: Shell\ErrorOutputBehavior::Replace,
        );

        static::assertSame(' world', $result);
    }

    public function testErrorOutputIsPacked(): void
    {
        $result = Shell\execute(
            PHP_BINARY,
            ['-dopcache.enable=0', '-r', 'fwrite(STDOUT, "hello"); fwrite(STDERR, " world");'],
            errorOutputBehavior: Shell\ErrorOutputBehavior::Packed,
        );

        [$stdout, $stderr] = Shell\unpack($result);

        static::assertSame('hello', $stdout);
        static::assertSame(' world', $stderr);
    }

    public function testStreamUnpackInvalidHeader(): void
    {
        $this->expectException(Shell\Exception\InvalidArgumentException::class);

        $generator = Shell\stream_unpack('abc');
        $generator->current();
    }

    public function testStreamUnpackInvalidType(): void
    {
        $this->expectException(Shell\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('$content contains an invalid header value.');

        $content = pack('C1N1', 3, 1) . 'a';
        $generator = Shell\stream_unpack($content);
        $generator->current();
    }

    public function testStreamUnpackSizeExceedsContent(): void
    {
        $this->expectException(Shell\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('$content contains an invalid header value.');

        $content = pack('C1N1', 1, 100) . 'short';
        $generator = Shell\stream_unpack($content);
        $generator->current();
    }

    public function testTimeoutDoesNotAffectFastCommands(): void
    {
        $result = Shell\execute(
            PHP_BINARY,
            ['-dopcache.enable=0', '-r', 'echo "hello";'],
            cancellation: new Async\TimeoutCancellationToken(Duration::seconds(5)),
        );

        static::assertSame('hello', $result);
    }

    public function testTimeoutException(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped(
                'Timeout relies on IO\\streaming() which behaves differently on Windows with non-blocking pipes.',
            );
        }

        $start = DateTime\Timestamp::monotonic();

        try {
            Shell\execute(
                PHP_BINARY,
                ['-dopcache.enable=0', '-r', 'sleep(2);'],
                cancellation: new Async\TimeoutCancellationToken(Duration::milliseconds(200)),
            );
        } catch (Async\Exception\CancelledException $_) {
            $elapsed = DateTime\Timestamp::monotonic()->since($start);

            static::assertLessThan(
                1.0,
                $elapsed->getTotalSeconds(),
                'Process was not killed after timeout; proc_close() likely blocked.',
            );

            return;
        }

        static::fail('Expected timeout exception');
    }
}
