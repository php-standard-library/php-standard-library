<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Psl;
use Psl\Async;
use Psl\DateTime;
use Psl\IO;
use ReflectionClass;

use function dirname;
use function fclose;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function var_export;

use const PHP_BINARY;
use const PHP_OS_FAMILY;

final class PipeTest extends TestCase
{
    public function testReadWrite(): void
    {
        [$read, $write] = IO\pipe();

        static::assertSame('', $read->tryRead());
        $write->writeAll('hello');
        static::assertSame('hello', $read->read());

        static::assertSame('', $read->tryRead());
        $write->writeAll('hello');
        static::assertSame('hello', $read->read());

        $write->writeAll('hello, world!');
        $write->close();

        static::assertSame('hello, world!', $read->readAll());

        $read->close();
    }

    public function testReadWriteInParallel(): void
    {
        [$read, $write] = IO\pipe();

        $spy = new Psl\Ref('');

        $readAwaitable = Async\run(static function () use ($read, $spy): string {
            $spy->value .= '[read:sleep]';
            Async\sleep(DateTime\Duration::milliseconds(3));
            $spy->value .= '[read:start]';
            $content = $read->readAll(1000);
            $spy->value .= '[read:complete]';
            $read->close();
            $spy->value .= '[read:close]';
            return $content;
        });

        Async\run(static function () use ($write, $spy): void {
            $spy->value .= '[write:sleep]';
            Async\sleep(DateTime\Duration::milliseconds(5));
            $spy->value .= '[write:start]';
            $write->writeAll('hello');
            $spy->value .= '[write:complete]';
            $write->close();
            $spy->value .= '[write:close]';
        })->await();

        $readResult = $readAwaitable->await();

        static::assertSame('hello', $readResult);
        static::assertSame(
            '[read:sleep][write:sleep][read:start][write:start][write:complete][write:close][read:complete][read:close]',
            $spy->value,
        );
    }

    public function testReadFixedSize(): void
    {
        [$read, $write] = IO\pipe();

        $write->writeAll('');
        $write->writeAll('hello!');
        $write->close();

        static::assertSame('he', $read->readFixedSize(2));
    }

    public function testReadFixedSizeFromClosedPipe(): void
    {
        [$read, $write] = IO\pipe();

        $write->writeAll('');
        $write->writeAll('hello!');
        $write->close();

        $this->expectException(IO\Exception\RuntimeException::class);
        $this->expectExceptionMessage('8 bytes were requested, but only able to read 6 bytes');

        $read->readFixedSize(8);
    }

    public function testReadAllTimedOut(): void
    {
        [$read, $_write] = IO\pipe();

        $this->expectException(Async\Exception\CancelledException::class);

        $read->readAll(cancellation: new Async\TimeoutCancellationToken(DateTime\Duration::milliseconds(1)));
    }

    public function testReadOnAlreadyClosedPipe(): void
    {
        [$read, $_write] = IO\pipe();

        Async\Scheduler::defer($read->close(...));
        $b = Async\run($read->readAll(...));

        $this->expectException(IO\Exception\AlreadyClosedException::class);
        $this->expectExceptionMessage('Handle has already been closed.');

        // > $b starts
        // > while waiting for $read to become readable, switch to defer
        // > defer calls close()
        // > close() throws in read suspension
        // > $b fails with the already closed exception.

        $b->await();
    }

    public function testWriteOnAlreadyClosedPipe(): void
    {
        [$_read, $write] = IO\pipe();

        Async\Scheduler::defer($write->close(...));

        $b = Async\run(static fn(): null => $write->writeAll('hello'));

        $this->expectException(IO\Exception\AlreadyClosedException::class);
        $this->expectExceptionMessage('Handle has already been closed.');

        // > $b starts
        // > while waiting for $write to become writable, switch to defer
        // > defer calls close()
        // > close() throws in read suspension
        // > $b fails with the already closed exception.

        $b->await();
    }

    public function testIsClosedOnPipeHandles(): void
    {
        [$read, $write] = IO\pipe();

        static::assertFalse($read->isClosed());
        static::assertFalse($write->isClosed());

        $read->close();

        static::assertTrue($read->isClosed());
        static::assertFalse($write->isClosed());

        $write->close();

        static::assertTrue($write->isClosed());
    }

    public function testThrowsWhenTheProcessHasNoFileDescriptorLeft(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('This test lowers the file descriptor limit with ulimit.');
        }

        $loader = new ReflectionClass(ClassLoader::class)->getFileName();
        static::assertIsString($loader);

        // Load the exception class first: once the descriptors are used up, autoloading cannot open its file.
        $script = 'require ' . var_export(dirname($loader, 2) . '/autoload.php', true) . ';' . <<<'PHP'
        class_exists(Psl\IO\Exception\RuntimeException::class);
        $held = [];
        while (false !== ($handle = @fopen(PHP_BINARY, 'rb'))) {
            $held[] = $handle;
        }

        try {
            Psl\IO\pipe();
            echo 'no exception';
        } catch (Throwable $exception) {
            echo $exception::class, ': ', $exception->getMessage();
        }
        PHP;

        $process = proc_open(
            ['sh', '-c', 'ulimit -n 64 && exec "$0" -dopcache.enable=0 -r "$1"', PHP_BINARY, $script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        static::assertNotFalse($process);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        static::assertSame(0, proc_close($process), (string) $errors);
        static::assertIsString($output);
        static::assertStringStartsWith('Psl\IO\Exception\RuntimeException: ', $output);
        static::assertStringContainsString('Too many open files', $output);
    }
}
