<?php

declare(strict_types=1);

namespace Psl\File\Tests\Unit;

use Closure;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\File;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_link;
use function mkdir;
use function realpath;
use function rmdir;
use function scandir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * A path containing a NUL byte can never exist on the filesystem.
 *
 * PHP 8.6 throws a `ValueError` from `file_exists()` and `is_file()` for such a path,
 * and `fopen()` and `mkdir()` already did on earlier versions; these tests make sure
 * PSL never leaks it.
 *
 * Unlike {@see AbstractFileTestCase}, these tests do not depend on Unix permissions,
 * so they run on every OS.
 */
final class NulBytePathTest extends TestCase
{
    private string $directory;
    private string $file;

    #[Override]
    protected function setUp(): void
    {
        $cacheDirectory = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.cache';
        if (!is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0o777, true);
        }

        $cacheDirectory = realpath($cacheDirectory);
        static::assertIsString($cacheDirectory);

        $this->directory = $cacheDirectory . DIRECTORY_SEPARATOR . 'nul-byte';
        if (!is_dir($this->directory)) {
            mkdir($this->directory);
        }

        $this->file = $this->directory . DIRECTORY_SEPARATOR . 'file.txt';
        file_put_contents($this->file, 'hello');

        static::assertSame(['.', '..', 'file.txt'], scandir($this->directory));
    }

    #[Override]
    protected function tearDown(): void
    {
        if (!isset($this->directory)) {
            return;
        }

        self::deleteDirectory($this->directory);

        static::assertDirectoryDoesNotExist($this->directory);
    }

    /**
     * @param (Closure(string): mixed) $operation
     */
    #[DataProvider('provideReadOperations')]
    public function testReadOperationsThrowNotFoundForPathContainingNulByte(Closure $operation): void
    {
        $this->expectException(File\Exception\NotFoundException::class);

        $operation($this->file . "\0");
    }

    /**
     * @return iterable<string, array{Closure(string): mixed}>
     */
    public static function provideReadOperations(): iterable
    {
        yield 'open_read_only' => [static fn(string $file): mixed => File\open_read_only($file)];
        yield 'ReadHandle' => [static fn(string $file): mixed => new File\ReadHandle($file)];
        yield 'read' => [static fn(string $file): mixed => File\read($file)];
    }

    /**
     * @param (Closure(string, File\WriteMode): mixed) $operation
     */
    #[DataProvider('provideWriteOperations')]
    public function testWriteOperationsThrowInvalidArgumentForPathContainingNulByte(
        Closure $operation,
        File\WriteMode $mode,
        string $path,
    ): void {
        try {
            $operation($this->directory . DIRECTORY_SEPARATOR . $path, $mode);

            static::fail('Expected an InvalidArgumentException to be thrown.');
        } catch (File\Exception\InvalidArgumentException $exception) {
            static::assertSame(File\Exception\InvalidArgumentException::class, $exception::class);
            static::assertSame('$file should not contain a NUL byte.', $exception->getMessage());
        }

        // Nothing must be created or modified: neither the missing parent directories,
        // nor a file at the path truncated at the NUL byte.
        static::assertSame(['.', '..', 'file.txt'], scandir($this->directory));
        static::assertSame('hello', file_get_contents($this->file));
    }

    /**
     * @return iterable<string, array{Closure(string, File\WriteMode): mixed, File\WriteMode, string}>
     */
    public static function provideWriteOperations(): iterable
    {
        $operations = [
            'open_write_only' => static fn(string $file, File\WriteMode $mode): mixed => File\open_write_only(
                $file,
                $mode,
            ),
            'WriteHandle' => static fn(string $file, File\WriteMode $mode): mixed => new File\WriteHandle($file, $mode),
            'open_read_write' => static fn(string $file, File\WriteMode $mode): mixed => File\open_read_write(
                $file,
                $mode,
            ),
            'ReadWriteHandle' => static fn(string $file, File\WriteMode $mode): mixed => new File\ReadWriteHandle(
                $file,
                $mode,
            ),
            'write' => static fn(string $file, File\WriteMode $mode): mixed => File\write($file, 'content', $mode),
        ];

        $paths = [
            'existing file' => "file.txt\0",
            'existing file with suffix' => "file.txt\0.bak",
            'new file' => "new\0.txt",
            'new file with missing parent' => "missing/new\0.txt",
            'new file in directory' => "missing\0/new.txt",
        ];

        foreach ($operations as $name => $operation) {
            foreach (File\WriteMode::cases() as $mode) {
                foreach ($paths as $description => $path) {
                    yield $name . ' ' . $mode->name . ' ' . $description => [$operation, $mode, $path];
                }
            }
        }
    }

    /**
     * Recursively delete $directory, including anything a regression may have left behind.
     */
    private static function deleteDirectory(string $directory): void
    {
        $entries = scandir($directory);
        static::assertIsArray($entries);

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
