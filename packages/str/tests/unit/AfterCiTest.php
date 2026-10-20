<?php

declare(strict_types=1);

namespace Psl\Str\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Str;

final class AfterCiTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testAfter(
        null|string $expected,
        string $haystack,
        string $needle,
        int $offset,
        Str\Encoding $encoding,
    ): void {
        static::assertSame($expected, Str\after_ci($haystack, $needle, $offset, $encoding));
    }

    public static function provideData(): array
    {
        return [
            [null, '', '', 0, Str\Encoding::Utf8],
            ['orld!', 'Hello, World!', 'W', 0, Str\Encoding::Utf8],
            ['!', '🤷!', '🤷', 0, Str\Encoding::Utf8],
            [null, 'مرحبا بكم', '', 0, Str\Encoding::Utf8],
            [null, 'مرحبا بكم', 'ß', 0, Str\Encoding::Utf8],
            [', wôrld!', 'héllö, wôrld!', 'héllö', 0, Str\Encoding::Utf8],
            [', wôrld!', 'ḫéllö, wôrld!', 'ḫéllö', 0, Str\Encoding::Utf8],
            [', wôrld!', 'ḫéllö, wôrld!', 'Ḫéllö', 0, Str\Encoding::Utf8],
            [', wôrld!', 'Ḫéllö, wôrld!', 'Ḫéllö', 0, Str\Encoding::Utf8],
            [', wôrld!', 'Ḫéllö, wôrld!', 'ḫéllö', 0, Str\Encoding::Utf8],
            ['好', '你好', '你', 0, Str\Encoding::Utf8],
            ['にちは世界', 'こんにちは世界', 'こん', 0, Str\Encoding::Utf8],
            ['สดี', 'สวัสดี', 'วั', 0, Str\Encoding::Utf8],
            [', world!', 'Hello, world!', 'o', 0, Str\Encoding::Utf8],
            ['rld!', 'Hello, world!', 'o', 7, Str\Encoding::Utf8],
            ['rld!', 'Hello, world!', 'o', 7, Str\Encoding::Ascii],
            // The needle length must be computed with the given encoding: "\xE3\xA9" is two ISO-8859-1 characters,
            // but a truncated UTF-8 sequence that counts as one; "\x00B" is one UTF-16BE character but two UTF-8 ones.
            ['xyz', "\xC3\xA9xyz", "\xE3\xA9", 0, Str\Encoding::Iso88591],
            ["\x00c", "\x00a\x00b\x00c", "\x00B", 0, Str\Encoding::Utf16be],
        ];
    }
}
