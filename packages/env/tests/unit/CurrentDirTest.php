<?php

declare(strict_types=1);

namespace Psl\Env\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Env;
use Psl\Exception\InvariantViolationException;

use function getcwd;

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

    public function testChangeCurrentDirectoryThrowsIfUnableTo(): void
    {
        $this->expectException(InvariantViolationException::class);
        $this->expectExceptionMessage('Unable to change directory');

        Env\set_current_dir('/foo/bar/baz/qux');
    }

    public function testChangeCurrentDirectoryThrowsForNulByte(): void
    {
        $cwd = Env\current_dir();

        try {
            Env\set_current_dir(__DIR__ . "\0");

            static::fail('Expected an InvariantViolationException to be thrown.');
        } catch (InvariantViolationException $exception) {
            static::assertSame('Unable to change directory', $exception->getMessage());
        }

        static::assertSame($cwd, Env\current_dir());
    }
}
