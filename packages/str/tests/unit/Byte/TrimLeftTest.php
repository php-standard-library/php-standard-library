<?php

declare(strict_types=1);

namespace Psl\Str\Tests\Unit\Byte;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Str\Byte;

final class TrimLeftTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testTrimLeft(string $expected, string $string, null|string $chars = null): void
    {
        static::assertSame($expected, Byte\trim_left($string, $chars));
    }

    public static function provideData(): array
    {
        return [
            ["Hello     Wôrld\t!!!\n",     "    Hello     Wôrld\t!!!\n",              null],
            ["Hello     Wôrld\t!!!\n",     "    Hello     Wôrld\t!!!\n",              ' '],
            ["    Héllö     World\t!!!\n", "    Héllö     World\t!!!\n",              "\n"],
            ["Héllö     World\t!!!\n",     "    Héllö     World\t!!!\n",              " \n!"],
            ["Héllö     Wôrld\t!!!  \n",   "    Héllö     Wôrld\t!!!  \n",            ' '],
            ["Hello\x0C",                  "\x0CHello\x0C",                           null],
            ["Hello \t\n\r\0\x0B\x0C",     " \t\n\r\0\x0B\x0CHello \t\n\r\0\x0B\x0C", null],
            ["\x0CHello\x0C",              "\x0CHello\x0C",                           ' '],
        ];
    }
}
