<?php

declare(strict_types=1);

namespace Psl\Str\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Str;

final class ReplaceEveryTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testReplaceEvery(string $expected, string $haystack, iterable $replacements): void
    {
        static::assertSame($expected, Str\replace_every($haystack, $replacements));
    }

    public static function provideData(): array
    {
        return [
            [
                'Hello, you!',
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
                ['سيف' => 'بكم', 'مرحبا' => 'اهلا'],
            ],
            [
                'Foo',
                'Foo',
                ['bar' => 'baz'],
            ],
            [
                'a-x-b',
                'a-1-b',
                ['1' => 'x'],
            ],
        ];
    }

    public function testReplaceEveryWithNonUtf8Encoding(): void
    {
        static::assertSame('Hello, World!', Str\replace_every(
            'Hello, You!',
            ['You' => 'World'],
            Str\Encoding::Iso88591,
        ));
    }

    public function testReplaceEveryWithNumericKeysInNonUtf8Encoding(): void
    {
        // PHP stores the numeric-string key '1' as the integer 1.
        static::assertSame('a-x-b', Str\replace_every('a-1-b', ['1' => 'x'], Str\Encoding::Iso88591));
    }
}
