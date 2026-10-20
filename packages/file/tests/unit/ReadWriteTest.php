<?php

declare(strict_types=1);

namespace Psl\File\Tests\Unit;

use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Env;
use Psl\File;
use Psl\Filesystem;
use Psl\OS;
use Psl\Str;

use function count;
use function get_resources;

use const DIRECTORY_SEPARATOR;

final class ReadWriteTest extends AbstractFileTestCase
{
    protected string $function = 'file';

    public function testWriteFile(): void
    {
        $file = Str\join([$this->directory, 'write.txt'], Filesystem\SEPARATOR);

        static::assertFileDoesNotExist($file);

        File\write($file, 'Hello!');

        static::assertFileExists($file);

        static::assertStringEqualsFile($file, 'Hello!');

        File\write($file, 'Hello', mode: File\WriteMode::Truncate);

        static::assertStringEqualsFile($file, 'Hello');

        File\write($file, ', World!', mode: File\WriteMode::Append);

        static::assertStringEqualsFile($file, 'Hello, World!');

        Filesystem\delete_file($file);
    }

    public function testWriteFileWithTruncateWriteModeCreatesFile(): void
    {
        $file = Str\join([$this->directory, 'write.txt'], Filesystem\SEPARATOR);

        static::assertFileDoesNotExist($file);

        File\write($file, 'Hello!', File\WriteMode::Truncate);

        static::assertFileExists($file);

        static::assertStringEqualsFile($file, 'Hello!');

        Filesystem\delete_file($file);
    }

    public function testWriteFileClearsFileStat(): void
    {
        $file = Filesystem\create_temporary_file();

        File\write($file, 'Hello');

        static::assertSame(5, Filesystem\file_size($file));

        File\write($file, ', World!', mode: File\WriteMode::Append);

        static::assertSame(13, Filesystem\file_size($file));

        Filesystem\delete_file($file);
    }

    public function testWriteFileThrowsForDirectories(): void
    {
        $this->expectException(File\Exception\NotFileException::class);
        $this->expectExceptionMessage('Path "' . $this->directory . '" does not point to a file.');

        File\write($this->directory, 'hello');
    }

    public function testAppendFileThrowsForDirectories(): void
    {
        $this->expectException(File\Exception\NotFileException::class);
        $this->expectExceptionMessage('Path "' . $this->directory . '" does not point to a file.');

        File\write($this->directory, 'hello', mode: File\WriteMode::Append);
    }

    public function testReadFileThrowsForDirectories(): void
    {
        $this->expectException(File\Exception\NotFileException::class);
        $this->expectExceptionMessage('Path "' . $this->directory . '" does not point to a file.');

        File\read($this->directory);
    }

    public function testWriteFileThrowsForNonWritableFiles(): void
    {
        $file = Str\join([$this->directory, 'write.txt'], Filesystem\SEPARATOR);
        Filesystem\create_file($file);
        $permissions = Filesystem\get_permissions($file) & 0o777;
        Filesystem\change_permissions($file, 0o111);

        try {
            $this->expectException(File\Exception\NotWritableException::class);
            $this->expectExceptionMessage('File "' . $file . '" is not writable.');

            File\write($file, 'hello');
        } finally {
            Filesystem\change_permissions($file, $permissions);
        }
    }

    public function testRead(): void
    {
        $file = Str\join([$this->directory, 'write.txt'], Filesystem\SEPARATOR);
        File\write($file, 'PHP Standard Library');
        File\write($file, ' - a modern, consistent, centralized', mode: File\WriteMode::Append);
        File\write($file, ' well-typed set of APIs for PHP programmers.', mode: File\WriteMode::Append);

        $content = File\read($file, 0, 20);

        static::assertSame('PHP Standard Library', $content);

        $content = File\read($file, 84, 16);

        static::assertSame('PHP programmers.', $content);
    }

    public function testReadWithCancellation(): void
    {
        $file = Str\join([$this->directory, 'cancel-read.txt'], Filesystem\SEPARATOR);
        File\write($file, 'hello world');

        $content = File\read($file, cancellation: new Async\NullCancellationToken());

        static::assertSame('hello world', $content);
    }

    public function testReadWithCancelledToken(): void
    {
        $file = Str\join([$this->directory, 'cancel-read2.txt'], Filesystem\SEPARATOR);
        File\write($file, 'hello');

        $token = new Async\SignalCancellationToken();
        $token->cancel();

        try {
            File\read($file, cancellation: $token);
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }
    }

    public function testWriteWithCancellation(): void
    {
        $file = Str\join([$this->directory, 'cancel-write.txt'], Filesystem\SEPARATOR);

        File\write($file, 'hello', cancellation: new Async\NullCancellationToken());

        static::assertStringEqualsFile($file, 'hello');
    }

    public function testWriteWithCancelledToken(): void
    {
        $file = Str\join([$this->directory, 'cancel-write2.txt'], Filesystem\SEPARATOR);

        $token = new Async\SignalCancellationToken();
        $token->cancel();

        try {
            File\write($file, 'hello', cancellation: $token);
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }
    }

    public function testReadClosesTheHandleWhenItFails(): void
    {
        $file = Str\join([$this->directory, 'read-close-on-failure.txt'], Filesystem\SEPARATOR);
        File\write($file, 'hello');

        $holder = File\open_read_write($file);
        $lock = $holder->lock(File\LockType::Exclusive);

        $streams = count(get_resources('stream'));

        try {
            File\read($file, cancellation: new Async\TimeoutCancellationToken(Duration::milliseconds(10)));
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        $leaked = count(get_resources('stream')) - $streams;

        $lock->release();
        $holder->close();

        static::assertSame(0, $leaked, 'File\read() did not close its handle.');
        static::assertSame('hello', File\read($file));
    }

    public function testWriteClosesTheHandleWhenItFails(): void
    {
        $file = Str\join([$this->directory, 'write-close-on-failure.txt'], Filesystem\SEPARATOR);
        File\write($file, 'hello');

        $holder = File\open_read_write($file);
        $lock = $holder->lock(File\LockType::Exclusive);

        $streams = count(get_resources('stream'));

        try {
            File\write($file, 'world', cancellation: new Async\TimeoutCancellationToken(Duration::milliseconds(10)));
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        $leaked = count(get_resources('stream')) - $streams;

        $lock->release();
        $holder->close();

        static::assertSame(0, $leaked, 'File\write() did not close its handle.');
        static::assertSame('hello', File\read($file));
    }

    public function testThrowsWhenDirectoryCreationFails(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Permissions are not reliable on windows.');
        }

        $targetDirectory = Env\temp_dir() . DIRECTORY_SEPARATOR . 'you-shall-not-pass';
        Filesystem\create_directory($targetDirectory, 0o000);

        $targetFile =
            $targetDirectory . DIRECTORY_SEPARATOR . 'fails-on-subdir-creation' . DIRECTORY_SEPARATOR . 'somefile.txt';

        $this->expectException(File\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create the directory for file "' . $targetFile . '".');

        new File\ReadWriteHandle($targetFile, File\WriteMode::MustCreate);
    }
}
