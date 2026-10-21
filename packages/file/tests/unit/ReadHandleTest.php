<?php

declare(strict_types=1);

namespace Psl\File\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Psl\File;
use Psl\Filesystem;
use Psl\OS;
use ReflectionClass;

use function dirname;
use function fclose;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function var_export;

use const PHP_BINARY;

final class ReadHandleTest extends TestCase
{
    public function testWriting(): void
    {
        $temporaryFile = Filesystem\create_temporary_file();
        $handle = File\open_write_only($temporaryFile);

        $handle->writeAll('hello');
        static::assertSame(2, $handle->write(', '));
        static::assertSame(6, $handle->tryWrite('world!'));

        $handle->close();

        $handle = File\open_read_only($temporaryFile);
        $content = $handle->tryRead();

        static::assertSame('hello, world!', $content);
    }

    public function testGetSizeDoesNotResetEndOfDataSource(): void
    {
        $temporaryFile = Filesystem\create_temporary_file();
        File\write($temporaryFile, 'hello');

        $handle = File\open_read_only($temporaryFile);
        try {
            static::assertSame('hello', $handle->readAll());
            static::assertTrue($handle->reachedEndOfDataSource());

            static::assertSame(5, $handle->getSize());
            static::assertSame(5, $handle->tell());
            static::assertTrue($handle->reachedEndOfDataSource());

            $iterations = 0;
            $handle->seek(0);
            while (!$handle->reachedEndOfDataSource()) {
                $handle->read();
                $handle->getSize();
                static::assertLessThan(10, ++$iterations, 'Read loop did not terminate.');
            }
        } finally {
            $handle->close();
            Filesystem\delete_file($temporaryFile);
        }
    }

    public function testIsClosed(): void
    {
        $handle = File\open_read_only(__FILE__);

        static::assertFalse($handle->isClosed());
        $handle->close();
        static::assertTrue($handle->isClosed());
    }

    public function testNonExisting(): void
    {
        $temporaryFile = Filesystem\create_temporary_file();
        Filesystem\delete_file($temporaryFile);

        $this->expectException(File\Exception\NotFoundException::class);
        File\open_read_only($temporaryFile);
    }

    public function testNotReadable(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('File permissions are not enforced on Windows.');
        }

        $temporaryFile = Filesystem\create_temporary_file();
        Filesystem\change_permissions($temporaryFile, 0o000);

        try {
            $this->expectException(File\Exception\NotReadableException::class);
            File\open_read_only($temporaryFile);
        } finally {
            Filesystem\change_permissions($temporaryFile, 0o644);
            Filesystem\delete_file($temporaryFile);
        }
    }

    public function testNonFile(): void
    {
        $temporaryFile = Filesystem\create_temporary_file();
        Filesystem\delete_file($temporaryFile);

        $this->expectException(File\Exception\NotFileException::class);
        File\open_read_only(dirname($temporaryFile));
    }

    public function testThrowsWhenTheProcessHasNoFileDescriptorLeft(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('This test lowers the file descriptor limit with ulimit.');
        }

        $loader = new ReflectionClass(ClassLoader::class)->getFileName();
        static::assertIsString($loader);

        // Load the classes first: once the descriptors are used up, autoloading cannot open their files.
        // The checks before opening only stat the file, so they pass; opening it is what fails.
        $script = 'require ' . var_export(dirname($loader, 2) . '/autoload.php', true) . ';' . <<<'PHP'
        class_exists(Psl\File\ReadHandle::class);
        class_exists(Psl\IO\Exception\RuntimeException::class);
        $held = [];
        while (false !== ($handle = @fopen(PHP_BINARY, 'rb'))) {
            $held[] = $handle;
        }

        try {
            new Psl\File\ReadHandle(PHP_BINARY);
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
