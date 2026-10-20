<?php

declare(strict_types=1);

namespace Psl\Filesystem\Tests\Unit;

use Closure;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Filesystem;
use Psl\Str;
use Psl\Type;

/**
 * A path containing a NUL byte can never exist on the filesystem.
 *
 * PHP 8.6 throws a `ValueError` from the filesystem builtins for such a path, and
 * most of the write builtins already did on earlier versions; these tests make sure
 * PSL never leaks it.
 *
 * Unlike {@see AbstractFilesystemTestCase}, these tests do not depend on Unix
 * permissions, so they run on every OS.
 */
final class NulBytePathTest extends TestCase
{
    /**
     * @var non-empty-string
     */
    private string $directory;

    /**
     * @var non-empty-string
     */
    private string $file;

    #[Override]
    protected function setUp(): void
    {
        $cacheDirectory = Str\join([__DIR__, '..', '.cache'], Filesystem\SEPARATOR);
        Filesystem\create_directory($cacheDirectory);

        $cacheDirectory = Type\non_empty_string()->assert(Filesystem\canonicalize($cacheDirectory));

        $this->directory = Str\join([$cacheDirectory, 'nul-byte'], Filesystem\SEPARATOR);
        Filesystem\create_directory($this->directory);

        $this->file = Str\join([$this->directory, 'file.txt'], Filesystem\SEPARATOR);
        Filesystem\create_file($this->file);

        static::assertSame([$this->file], Filesystem\read_directory($this->directory));
    }

    #[Override]
    protected function tearDown(): void
    {
        if (!isset($this->directory)) {
            return;
        }

        Filesystem\delete_directory($this->directory, true);

        static::assertFalse(Filesystem\exists($this->directory));
    }

    /**
     * @param (Closure(string): bool) $predicate
     */
    #[DataProvider('providePredicates')]
    public function testPredicatesReturnFalseForPathContainingNulByte(Closure $predicate): void
    {
        static::assertFalse($predicate($this->file . "\0"));
        static::assertFalse($predicate($this->file . "\0suffix"));
        static::assertFalse($predicate($this->directory . "\0"));
        static::assertFalse($predicate("\0"));
    }

    /**
     * @return iterable<string, array{Closure(string): bool}>
     */
    public static function providePredicates(): iterable
    {
        yield 'exists' => [Filesystem\exists(...)];
        yield 'is_file' => [Filesystem\is_file(...)];
        yield 'is_directory' => [Filesystem\is_directory(...)];
        yield 'is_symbolic_link' => [Filesystem\is_symbolic_link(...)];
        yield 'is_readable' => [Filesystem\is_readable(...)];
        yield 'is_writable' => [Filesystem\is_writable(...)];
        yield 'is_executable' => [Filesystem\is_executable(...)];
    }

    public function testCanonicalizeReturnsNullForPathContainingNulByte(): void
    {
        static::assertNull(Filesystem\canonicalize($this->file . "\0"));
        static::assertNull(Filesystem\canonicalize($this->directory . "\0"));
        static::assertNull(Filesystem\canonicalize("\0"));
    }

    /**
     * @param (Closure(string, string): mixed) $operation
     */
    #[DataProvider('provideOperationsOnExistingNodes')]
    public function testOperationsOnExistingNodesThrowNotFoundForPathContainingNulByte(Closure $operation): void
    {
        $this->expectException(Filesystem\Exception\NotFoundException::class);

        $operation($this->file, $this->directory);
    }

    /**
     * @return iterable<string, array{Closure(string, string): mixed}>
     */
    public static function provideOperationsOnExistingNodes(): iterable
    {
        yield 'change_group' => [static fn(string $file): mixed => Filesystem\change_group($file . "\0", 0)];
        yield 'change_owner' => [static fn(string $file): mixed => Filesystem\change_owner($file . "\0", 0)];
        yield 'change_permissions' => [
            static fn(string $file): mixed => Filesystem\change_permissions($file . "\0", 0o644),
        ];
        yield 'copy source' => [
            static fn(string $file, string $directory): mixed => Filesystem\copy(
                $file . "\0",
                $directory . '/copy.txt',
            ),
        ];
        // the source is validated even when an existing destination makes the copy a no-op.
        yield 'copy source onto an existing destination' => [
            static fn(string $file): mixed => Filesystem\copy($file . "\0", $file),
        ];
        // the source is checked before the destination.
        yield 'copy source and destination' => [
            static fn(string $file, string $directory): mixed => Filesystem\copy(
                $file . "\0",
                $directory . "/copy\0.txt",
            ),
        ];
        yield 'create_hard_link source' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_hard_link(
                $file . "\0",
                $directory . '/hard-link.txt',
            ),
        ];
        yield 'create_hard_link source and destination' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_hard_link(
                $file . "\0",
                $directory . "/hard\0link.txt",
            ),
        ];
        yield 'create_symbolic_link source' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_symbolic_link(
                $file . "\0",
                $directory . '/symbolic-link.txt',
            ),
        ];
        yield 'create_symbolic_link source and destination' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_symbolic_link(
                $file . "\0",
                $directory . "/symbolic\0link.txt",
            ),
        ];
        yield 'create_temporary_file directory' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_temporary_file($directory . "\0"),
        ];
        // the directory is checked before the prefix.
        yield 'create_temporary_file directory and prefix' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_temporary_file(
                $directory . "\0",
                "foo\0bar",
            ),
        ];
        yield 'delete_directory' => [
            static fn(string $file, string $directory): mixed => Filesystem\delete_directory($directory . "\0"),
        ];
        yield 'delete_directory recursive' => [
            static fn(string $file, string $directory): mixed => Filesystem\delete_directory($directory . "\0", true),
        ];
        yield 'delete_file' => [static fn(string $file): mixed => Filesystem\delete_file($file . "\0")];
        yield 'file_size' => [static fn(string $file): mixed => Filesystem\file_size($file . "\0")];
        yield 'get_access_time' => [static fn(string $file): mixed => Filesystem\get_access_time($file . "\0")];
        yield 'get_change_time' => [static fn(string $file): mixed => Filesystem\get_change_time($file . "\0")];
        yield 'get_group' => [static fn(string $file): mixed => Filesystem\get_group($file . "\0")];
        yield 'get_inode' => [static fn(string $file): mixed => Filesystem\get_inode($file . "\0")];
        yield 'get_modification_time' => [
            static fn(string $file): mixed => Filesystem\get_modification_time($file . "\0"),
        ];
        yield 'get_owner' => [static fn(string $file): mixed => Filesystem\get_owner($file . "\0")];
        yield 'get_permissions' => [static fn(string $file): mixed => Filesystem\get_permissions($file . "\0")];
        yield 'read_directory' => [
            static fn(string $file, string $directory): mixed => Filesystem\read_directory($directory . "\0"),
        ];
        yield 'read_symbolic_link' => [static fn(string $file): mixed => Filesystem\read_symbolic_link($file . "\0")];
    }

    /**
     * @param (Closure(string, string): mixed) $operation
     */
    #[DataProvider('provideOperationsCreatingNodes')]
    public function testOperationsCreatingNodesThrowInvalidArgumentForPathContainingNulByte(
        Closure $operation,
        string $message,
    ): void {
        try {
            $operation($this->file, $this->directory);

            static::fail('Expected an InvalidArgumentException to be thrown.');
        } catch (Filesystem\Exception\InvalidArgumentException $exception) {
            static::assertSame(Filesystem\Exception\InvalidArgumentException::class, $exception::class);
            static::assertSame($message, $exception->getMessage());
        }

        // Nothing must be created: neither the missing parent directories, nor a
        // node at the path truncated at the NUL byte.
        static::assertSame([$this->file], Filesystem\read_directory($this->directory));
    }

    /**
     * @return iterable<string, array{Closure(string, string): mixed, string}>
     */
    public static function provideOperationsCreatingNodes(): iterable
    {
        yield 'copy destination' => [
            static fn(string $file, string $directory): mixed => Filesystem\copy($file, $directory . "/copy\0.txt"),
            '$destination should not contain a NUL byte.',
        ];
        yield 'copy destination with overwrite' => [
            static fn(string $file, string $directory): mixed => Filesystem\copy(
                $file,
                $directory . "/copy\0.txt",
                true,
            ),
            '$destination should not contain a NUL byte.',
        ];
        yield 'create_directory' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_directory($directory . "/foo\0bar"),
            '$directory should not contain a NUL byte.',
        ];
        yield 'create_directory nested' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_directory($directory
            . "/foo\0bar/baz"),
            '$directory should not contain a NUL byte.',
        ];
        yield 'create_directory with missing parent' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_directory($directory
            . "/missing/foo\0bar"),
            '$directory should not contain a NUL byte.',
        ];
        yield 'create_directory_for_file' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_directory_for_file($directory
            . "/foo\0bar/baz.txt"),
            '$filename should not contain a NUL byte.',
        ];
        yield 'create_directory_for_file in basename' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_directory_for_file($directory
            . "/foo\0bar.txt"),
            '$filename should not contain a NUL byte.',
        ];
        yield 'create_directory_for_file in basename with missing parent' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_directory_for_file($directory
            . "/missing/foo\0bar.txt"),
            '$filename should not contain a NUL byte.',
        ];
        yield 'create_file' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_file($directory . "/foo\0bar.txt"),
            '$filename should not contain a NUL byte.',
        ];
        yield 'create_file in directory' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_file($directory
            . "/foo\0bar/baz.txt"),
            '$filename should not contain a NUL byte.',
        ];
        yield 'create_file with missing parent' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_file($directory
            . "/missing/foo\0bar.txt"),
            '$filename should not contain a NUL byte.',
        ];
        yield 'create_file with time' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_file(
                $directory . "/foo\0bar.txt",
                1_000_000,
                1_000_000,
            ),
            '$filename should not contain a NUL byte.',
        ];
        yield 'create_hard_link destination' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_hard_link(
                $file,
                $directory . "/hard\0link.txt",
            ),
            '$destination should not contain a NUL byte.',
        ];
        yield 'create_hard_link destination with missing parent' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_hard_link(
                $file,
                $directory . "/missing/hard\0link.txt",
            ),
            '$destination should not contain a NUL byte.',
        ];
        yield 'create_symbolic_link destination' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_symbolic_link(
                $file,
                $directory . "/symbolic\0link.txt",
            ),
            '$destination should not contain a NUL byte.',
        ];
        yield 'create_symbolic_link destination with missing parent' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_symbolic_link(
                $file,
                $directory . "/missing/symbolic\0link.txt",
            ),
            '$destination should not contain a NUL byte.',
        ];
        yield 'create_temporary_file prefix' => [
            static fn(string $file, string $directory): mixed => Filesystem\create_temporary_file(
                $directory,
                "foo\0bar",
            ),
            '$prefix should not contain a NUL byte.',
        ];
    }
}
