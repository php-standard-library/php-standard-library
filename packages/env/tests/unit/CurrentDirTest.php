<?php

declare(strict_types=1);

namespace Psl\Env\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Env;

use function getcwd;
use function getmypid;
use function mkdir;
use function rmdir;
use function uniqid;

use const PHP_OS_FAMILY;

final class CurrentDirTest extends TestCase
{
    public function testCurrentDir(): void
    {
        $cwd = Env\current_dir();
        static::assertSame(getcwd(), $cwd);

        Env\set_current_dir(__DIR__);

        static::assertSame(__DIR__, Env\current_dir());

        Env\set_current_dir($cwd);
    }

    public function testCurrentDirThrowsIfTheDirectoryWasDeleted(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('Windows does not allow deleting the current working directory.');
        }

        $cwd = Env\current_dir();
        $directory = Env\temp_dir() . '/psl-env-' . getmypid() . '-' . uniqid();
        static::assertTrue(mkdir($directory));

        try {
            Env\set_current_dir($directory);
            static::assertTrue(rmdir($directory));

            $this->expectException(Env\Exception\RuntimeException::class);
            $this->expectExceptionMessage('Unable to retrieve current working directory.');

            Env\current_dir();
        } finally {
            Env\set_current_dir($cwd);
        }
    }

    public function testChangeCurrentDirectoryThrowsIfUnableTo(): void
    {
        $cwd = Env\current_dir();

        try {
            Env\set_current_dir('/foo/bar/baz/qux');

            static::fail('Expected a RuntimeException to be thrown.');
        } catch (Env\Exception\RuntimeException $exception) {
            static::assertStringStartsWith(
                'Unable to change directory to "/foo/bar/baz/qux": chdir(): ',
                $exception->getMessage(),
            );
        }

        static::assertSame($cwd, Env\current_dir());
    }

    public function testChangeCurrentDirectoryThrowsForNulByte(): void
    {
        $cwd = Env\current_dir();

        try {
            Env\set_current_dir(__DIR__ . "\0");

            static::fail('Expected a RuntimeException to be thrown.');
        } catch (Env\Exception\RuntimeException $exception) {
            static::assertSame(
                'Unable to change directory: the path contains the NUL character.',
                $exception->getMessage(),
            );
        }

        static::assertSame($cwd, Env\current_dir());
    }
}
