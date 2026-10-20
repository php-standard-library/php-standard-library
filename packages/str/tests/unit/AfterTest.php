<?php

declare(strict_types=1);

namespace Psl\Str\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Str;

final class AfterTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testAfter(null|string $expected, string $haystack, string $needle, int $offset): void
    {
        static::assertSame($expected, Str\after($haystack, $needle, $offset));
    }

    public static function provideData(): array
    {
        return [
            [null,         '',               '',      0],
            ['orld!',      'Hello, World!',  'W',     0],
            ['!',          '🤷!',            '🤷',    0],
            [null,         'مرحبا بكم',      '',      0],
            [null,         'مرحبا بكم',      'ß',     0],
            [', wôrld!',   'héllö, wôrld!',  'héllö', 0],
            [', wôrld!',   'ḫéllö, wôrld!',  'ḫéllö', 0],
            [null,         'ḫéllö, wôrld!',  'Ḫéllö', 0],
            [', wôrld!',   'Ḫéllö, wôrld!',  'Ḫéllö', 0],
            [null,         'Ḫéllö, wôrld!',  'ḫéllö', 0],
            ['好',         '你好',           '你',    0],
            ['にちは世界', 'こんにちは世界', 'こん',  0],
            ['สดี',         'สวัสดี',           'วั',     0],
            [', world!',   'Hello, world!',  'o',     0],
            ['rld!',       'Hello, world!',  'o',     7],
        ];
    }

    #[DataProvider('provideEncodingData')]
    public function testAfterWithEncoding(
        null|string $expected,
        string $haystack,
        string $needle,
        Str\Encoding $encoding,
    ): void {
        static::assertSame($expected, Str\after($haystack, $needle, 0, $encoding));
    }

    /**
     * The needle length must be computed with the given encoding: "\xC3\xA9" is one UTF-8
     * character but two ISO-8859-1 characters, "\x00b" is one UTF-16BE character but two UTF-8 characters.
     */
    public static function provideEncodingData(): iterable
    {
        yield 'iso-8859-1' => ['xyz', "\xC3\xA9xyz", "\xC3\xA9", Str\Encoding::Iso88591];
        yield 'utf-16be' => ["\x00c", "\x00a\x00b\x00c", "\x00b", Str\Encoding::Utf16be];
    }
}
