<?php

declare(strict_types=1);

namespace Psl\Str\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Str;

use function file_get_contents;

final class ReplaceCiTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testReplaceCi(string $expected, string $haystack, string $needle, string $replacement): void
    {
        static::assertSame($expected, Str\replace_ci($haystack, $needle, $replacement));
    }

    public static function provideData(): array
    {
        return [
            ['Hello, World!', 'Hello, you!', 'You', 'World'],
            ['Hello, World!', 'Hello, You!', 'You', 'World'],
            ['مرحبا بكم',     'مرحبا سيف',   'سيف', 'بكم'],
            ['foo',           'foo',         'bar', 'baz'],
        ];
    }

    #[DataProvider('provideBadUtf8Data')]
    public function testBadUtf8(string $string, string $expectedException, string $expectedExceptionMessage): void
    {
        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedExceptionMessage);

        Str\replace_ci($string, $string, $string);
    }

    public function testBadUtf8HaystackDoesNotReportAPreviousError(): void
    {
        // Leave an unrelated error behind: it must not be reported as the reason of the failure.
        @file_get_contents(__DIR__ . '/this-file-does-not-exist');

        $this->expectException(Str\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed UTF-8 characters, possibly incorrectly encoded');

        Str\replace_ci("caf\xE9 latte", 'latte', 'x');
    }

    public static function provideBadUtf8Data(): iterable
    {
        yield [
            "\xc1\xbf",
            Str\Exception\InvalidArgumentException::class,
            'Compilation failed: UTF-8 error: overlong 2-byte sequence at offset 0',
        ];

        yield [
            "\xe0\x81\xbf",
            Str\Exception\InvalidArgumentException::class,
            'Compilation failed: UTF-8 error: overlong 3-byte sequence at offset 0',
        ];

        yield [
            "\xf0\x80\x81\xbf",
            Str\Exception\InvalidArgumentException::class,
            'Compilation failed: UTF-8 error: overlong 4-byte sequence at offset 0',
        ];
    }
}
