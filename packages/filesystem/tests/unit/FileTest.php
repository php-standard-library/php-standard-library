<?php

declare(strict_types=1);

namespace Psl\Filesystem\Tests\Unit;

use Psl\Env;
use Psl\Filesystem;
use Psl\Str;

use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function strlen;
use function time;
use function umask;

use const STREAM_META_ACCESS;

final class FileTest extends AbstractFilesystemTestCase
{
    protected string $function = 'file';

    public function testTemporaryFile(): void
    {
        $file = Filesystem\create_temporary_file($this->directory);

        static::assertTrue(Filesystem\is_file($file));
        static::assertSame($this->directory, Filesystem\get_directory($file));
    }

    public function testTemporaryFileIsPrivateWithAnOpenUmask(): void
    {
        $previous = umask(0);

        try {
            $file = Filesystem\create_temporary_file($this->directory);
            static::assertSame(0o600, Filesystem\get_permissions($file) & 0o777);
            static::assertSame(0, umask());
        } finally {
            umask($previous);
        }
    }

    public function testTemporaryFilenameFitsTheMacOsUnixSocketExamples(): void
    {
        $prefix = 'psl-unix-timeout-sock-';
        $file = Filesystem\create_temporary_file($this->directory, $prefix);

        // A canonical macOS temporary directory takes 56 bytes of the path.
        static::assertLessThan(104, 56 + 1 + strlen(Filesystem\get_filename($file)));
    }

    public function testTemporaryFileRetriesAnExclusiveCreationCollision(): void
    {
        $wrapper = new class {
            /** @var resource|null */
            public $context = null;
            /** @var list<string> */
            public static array $modes = [];
            /** @var list<int> */
            public static array $masks = [];
            public static null|string $collision = null;

            /**
             * @return array<string, int>|false
             */
            public function url_stat(string $path, int $flags): array|false
            {
                if ($path === 'psl-temp-collision://directory') {
                    return ['mode' => 0o40_777];
                }

                return $path === self::$collision ? ['mode' => 0o100_666] : false;
            }

            public function stream_open(string $path, string $mode, int $options, null|string &$openedPath): bool
            {
                self::$modes[] = $mode;
                self::$masks[] = umask();
                if (self::$collision === null) {
                    self::$collision = $path;

                    return false;
                }

                return true;
            }

            public function stream_close(): void {}

            public function stream_metadata(string $path, int $option, mixed $value): bool
            {
                if ($option === STREAM_META_ACCESS) {
                    return true;
                }

                self::$collision = $path;

                return true;
            }
        };

        static::assertTrue(stream_wrapper_register('psl-temp-collision', $wrapper::class));

        try {
            $path = Filesystem\create_temporary_file('psl-temp-collision://directory', 'prefix-');

            static::assertNotSame($wrapper::$collision, $path);
            static::assertSame(['x', 'x'], $wrapper::$modes);
            static::assertSame([0o077, 0o077], $wrapper::$masks);
            static::assertMatchesRegularExpression('~/prefix-[a-f0-9]{16}$~', $path);
        } finally {
            stream_wrapper_unregister('psl-temp-collision');
        }
    }

    public function testTemporaryFileWithPrefix(): void
    {
        $file = Filesystem\create_temporary_file($this->directory, 'foo');

        static::assertTrue(Filesystem\is_file($file));
        static::assertSame($this->directory, Filesystem\get_directory($file));
        static::assertStringContainsString('foo', Filesystem\get_filename($file));
    }

    public function testTemporaryFileIsCreateInTempDirectoryByDefault(): void
    {
        $file = Filesystem\create_temporary_file();

        static::assertSame(Env\temp_dir(), Filesystem\get_directory($file));
    }

    public function testTemporaryFileThrowsForNonDirectoryNode(): void
    {
        $this->expectException(Filesystem\Exception\NotDirectoryException::class);
        $this->expectExceptionMessage('Path "' . __FILE__ . '" does not point to a directory.');

        Filesystem\create_temporary_file(__FILE__);
    }

    public function testTemporaryFileThrowsForNonExistingDirectory(): void
    {
        $this->expectException(Filesystem\Exception\NotFoundException::class);
        $this->expectExceptionMessage('Directory "' . __FILE__ . '/foo" is not found.');

        Filesystem\create_temporary_file(__FILE__ . '/foo');
    }

    public function testTemporaryFileThrowsForPrefixWithSeparator(): void
    {
        $prefix = Str\join(['a', 'b'], Filesystem\SEPARATOR);

        $this->expectException(Filesystem\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            '$prefix should not contain a directory separator ( "' . Filesystem\SEPARATOR . '" ).',
        );

        Filesystem\create_temporary_file($this->directory, $prefix);
    }

    public function testCreateFileAndParentDirectory(): void
    {
        $directory = Str\join([$this->directory, 'foo'], Filesystem\SEPARATOR);
        $file = Str\join([$directory, 'write.txt'], Filesystem\SEPARATOR);

        static::assertFalse(Filesystem\is_directory($directory));

        Filesystem\create_file($file);

        static::assertTrue(Filesystem\is_directory($directory));
        static::assertTrue(Filesystem\is_file($file));
    }

    public function testFileModificationAndAccessTime(): void
    {
        $file = Str\join([$this->directory, 'write.txt'], Filesystem\SEPARATOR);

        $modificationTime = time() - 3600;
        $accessTime = time() - 1800;

        Filesystem\create_file($file, $modificationTime, $accessTime);

        static::assertSame($modificationTime, Filesystem\get_modification_time($file));
        static::assertSame($accessTime, Filesystem\get_access_time($file));
    }

    public function testGetModificationTimeOfNonExistingFile(): void
    {
        $filename = Str\join([$this->directory, 'non-existing'], Filesystem\SEPARATOR);

        $this->expectException(Filesystem\Exception\NotFoundException::class);
        $this->expectExceptionMessage('Node "' . $filename . '" is not found.');

        Filesystem\get_modification_time($filename);
    }

    public function testGetAccessTimeOfNonExistingFile(): void
    {
        $filename = Str\join([$this->directory, 'non-existing'], Filesystem\SEPARATOR);

        $this->expectException(Filesystem\Exception\NotFoundException::class);
        $this->expectExceptionMessage('Node "' . $filename . '" is not found.');

        Filesystem\get_access_time($filename);
    }

    public function testGetChangeTimeOfNonExistingFile(): void
    {
        $filename = Str\join([$this->directory, 'non-existing'], Filesystem\SEPARATOR);

        $this->expectException(Filesystem\Exception\NotFoundException::class);
        $this->expectExceptionMessage('Node "' . $filename . '" is not found.');

        Filesystem\get_change_time($filename);
    }

    public function testGetInodeOfNonExistingFile(): void
    {
        $filename = Str\join([$this->directory, 'non-existing'], Filesystem\SEPARATOR);

        $this->expectException(Filesystem\Exception\NotFoundException::class);
        $this->expectExceptionMessage('Node "' . $filename . '" is not found.');

        Filesystem\get_inode($filename);
    }

    public function testFileSizeThrowsForNonExistingFile(): void
    {
        $filename = Str\join([$this->directory, 'non-existing'], Filesystem\SEPARATOR);

        $this->expectException(Filesystem\Exception\NotFoundException::class);
        $this->expectExceptionMessage('File "' . $filename . '" is not found.');

        Filesystem\file_size($filename);
    }

    public function testFileSizeThrowsForNonFile(): void
    {
        $this->expectException(Filesystem\Exception\NotFileException::class);
        $this->expectExceptionMessage('Path "' . $this->directory . '" does not point to a file.');

        Filesystem\file_size($this->directory);
    }

    public function testCopyThrowsForNonExistingFile(): void
    {
        $file = Str\join([$this->directory, 'non-existing'], Filesystem\SEPARATOR);

        $this->expectException(Filesystem\Exception\NotFoundException::class);
        $this->expectExceptionMessage('File "' . $file . '" is not found.');

        Filesystem\copy($file, '/foo/bar');
    }

    public function testCreateSymbolicLinkThrowsForNonExistingFile(): void
    {
        $filename = Str\join([$this->directory, 'non-existing'], Filesystem\SEPARATOR);

        $this->expectException(Filesystem\Exception\NotFoundException::class);
        $this->expectExceptionMessage('Node "' . $filename . '" is not found.');

        Filesystem\create_symbolic_link($filename, '/foo/bar');
    }

    public function testFileSizeThrowsForNonReadableFile(): void
    {
        $filename = Str\join([$this->directory, 'non-readable.txt'], Filesystem\SEPARATOR);
        Filesystem\create_file($filename);
        $permissions = Filesystem\get_permissions($filename) & 0o777;
        Filesystem\change_permissions($filename, 0o111);

        static::assertFalse(Filesystem\is_readable($filename));

        $this->expectException(Filesystem\Exception\NotReadableException::class);
        $this->expectExceptionMessage('File "' . $filename . '" is not readable.');

        try {
            Filesystem\file_size($filename);
        } finally {
            Filesystem\change_permissions($filename, $permissions);
        }
    }

    public function testCopyThrowsForNonReadableFile(): void
    {
        $file = Str\join([$this->directory, 'non-readable.txt'], Filesystem\SEPARATOR);
        Filesystem\create_file($file);
        $permissions = Filesystem\get_permissions($file) & 0o777;
        Filesystem\change_permissions($file, 0o111);

        static::assertFalse(Filesystem\is_readable($file));

        $this->expectException(Filesystem\Exception\NotReadableException::class);
        $this->expectExceptionMessage('File "' . $file . '" is not readable.');

        try {
            Filesystem\copy($file, '/foo/bar');
        } finally {
            Filesystem\change_permissions($file, $permissions);
        }
    }

    public function testFileAccessTime(): void
    {
        $file = Str\join([$this->directory, 'write.txt'], Filesystem\SEPARATOR);

        $accessTime = time() - 1800;

        Filesystem\create_file($file, null, $accessTime);

        static::assertSame($accessTime, Filesystem\get_modification_time($file));
        static::assertSame($accessTime, Filesystem\get_access_time($file));
    }

    public function testFileModificationTime(): void
    {
        $file = Str\join([$this->directory, 'write.txt'], Filesystem\SEPARATOR);

        $modificationTime = time() - 3600;

        Filesystem\create_file($file, $modificationTime);

        static::assertSame($modificationTime, Filesystem\get_modification_time($file));
        static::assertSame($modificationTime, Filesystem\get_access_time($file));
    }

    public function testFileChangeTime(): void
    {
        $file = Str\join([$this->directory, 'write.txt'], Filesystem\SEPARATOR);

        Filesystem\create_file($file);

        static::assertEqualsWithDelta(time(), Filesystem\get_change_time($file), 1.0);
    }

    public function testDeleteFileThrowsWhenFileIsNotFound(): void
    {
        $file = Str\join([$this->directory, 'deleted-one.txt'], Filesystem\SEPARATOR);

        static::assertFalse(Filesystem\is_file($file));

        $this->expectException(Filesystem\Exception\NotFoundException::class);
        $this->expectExceptionMessage('File "' . $file . '" is not found.');

        Filesystem\delete_file($file);
    }

    public function testDeleteFileThrowsWhenFileIsNotAFile(): void
    {
        $directory = Str\join([$this->directory, 'deleted-two'], Filesystem\SEPARATOR);

        Filesystem\create_directory($directory);

        static::assertTrue(Filesystem\exists($directory));
        static::assertTrue(Filesystem\is_directory($directory));
        static::assertFalse(Filesystem\is_file($directory));

        $this->expectException(Filesystem\Exception\NotFileException::class);
        $this->expectExceptionMessage('Path "' . $directory . '" does not point to a file.');

        Filesystem\delete_file($directory);
    }
}
