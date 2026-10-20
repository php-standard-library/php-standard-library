<?php

declare(strict_types=1);

namespace Psl\Str\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Str;

use function file_get_contents;

final class ReplaceEveryCiTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testReplaceEveryCi(string $expected, string $haystack, iterable $replacements): void
    {
        static::assertSame($expected, Str\replace_every_ci($haystack, $replacements));
    }

    public static function provideData(): array
    {
        return [
            [
                'Hello, World!',
                'Hello, you!',
                ['You' => 'World'],
            ],
            [
                'Hello, World!',
                'Hello, You!',
                ['You' => 'World'],
            ],
            [
                'مرحبا بكم',
                'مرحبا سيف',
                ['سيف' => 'بكم'],
            ],
            [
                'اهلا بكم',
                'مرحبا سيف',
                [
                    'سيف' => 'بكم',
                    'مرحبا' => 'اهلا',
                ],
            ],
            [
                'Foo',
                'Foo',
                ['bar' => 'baz'],
            ],
            [
                'x x',
                'ÉCOLE école',
                ['école' => 'x'],
            ],
            [
                'Hello, wôrld! Hello, WÔRLD!',
                'ḫéllö, wôrld! ḪÉLLÖ, WÔRLD!',
                ['Ḫéllö' => 'Hello'],
            ],
            [
                'Σ Σ',
                'ΑΒΓ αβγ',
                ['αβγ' => 'Σ'],
            ],
            [
                'c',
                'a',
                ['A' => 'b', 'B' => 'c'],
            ],
            [
                'a-x-b',
                'a-1-b',
                ['1' => 'x'],
            ],
            // ASCII needles match non-ASCII characters that fold to them (KELVIN SIGN, LATIN SMALL LETTER LONG S),
            // in the haystack, and in the replacements made by earlier needles.
            [
                'celvin Straße',
                "\u{212A}elvin \u{17F}traße",
                ['k' => 'c', 's' => 'S'],
            ],
            [
                'xb',
                'ab',
                ['a' => "\u{212A}", 'K' => 'x'],
            ],
            // A non-ASCII needle matches the ASCII characters it folds to.
            [
                'celvin',
                'kelvin',
                ["\u{212A}" => 'c'],
            ],
        ];
    }

    public function testReplaceEveryCiMatchesReplaceCi(): void
    {
        $haystack = 'Ḫéllö, wôrld! ÉCOLE école Straße STRASSE';
        $needle = 'ÉCOLE';

        static::assertSame(
            Str\replace_ci($haystack, $needle, 'school'),
            Str\replace_every_ci($haystack, [$needle => 'school']),
        );
    }

    public function testReplaceEveryCiWithAsciiEncoding(): void
    {
        static::assertSame('Hello, World!', Str\replace_every_ci(
            'Hello, You!',
            ['you' => 'World'],
            Str\Encoding::Ascii,
        ));
    }

    public function testReplaceEveryCiWithBadUtf8(): void
    {
        $this->expectException(Str\Exception\InvalidArgumentException::class);

        Str\replace_every_ci("\xc1\xbf", ["\xc1\xbf" => 'x']);
    }

    public function testReplaceEveryCiWithBadUtf8HaystackDoesNotReportAPreviousError(): void
    {
        // Leave an unrelated error behind: it must not be reported as the reason of the failure.
        @file_get_contents(__DIR__ . '/this-file-does-not-exist');

        $this->expectException(Str\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed UTF-8 characters, possibly incorrectly encoded');

        Str\replace_every_ci("caf\xE9 latte", ['latte' => 'x']);
    }

    public function testReplaceEveryCiWithNonUtf8Encoding(): void
    {
        static::assertSame('Hello, World!', Str\replace_every_ci(
            'Hello, You!',
            ['you' => 'World'],
            Str\Encoding::Iso88591,
        ));
    }

    public function testReplaceEveryCiWithEmptyNeedleInNonUtf8(): void
    {
        static::assertSame('Hello, World!', Str\replace_every_ci(
            'Hello, You!',
            ['' => 'SKIP', 'you' => 'World'],
            Str\Encoding::Iso88591,
        ));
    }
}
