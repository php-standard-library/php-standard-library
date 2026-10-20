<?php

declare(strict_types=1);

namespace Psl\Str\Tests\Unit\Byte;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Str\Byte;

final class TrimRightTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testTrimRight(string $expected, string $string, null|string $chars = null): void
    {
        static::assertSame($expected, Byte\trim_right($string, $chars));
    }

    public static function provideData(): array
    {
        return [
            ["    Hello     World\t!!!",     "    Hello     World\t!!!\n",              null],
            ["    Hello     World\t!!!\n",   "    Hello     World\t!!!\n",              ' '],
            ["    Hello     World\t!!!",     "    Hello     World\t!!!\n",              "\n"],
            ["    Hello     World\t",        "    Hello     World\t!!!\n",              "\n!"],
            ['    Hello     World',          "    Hello     World\t!!!\n",              "\n!\t"],
            ["    Hello     World\t",        "    Hello     World\t!!!\n",              " \n!"],
            ["    Hello     World\t!!!  \n", "    Hello     World\t!!!  \n",            ' '],
            ["\x0CHello",                    "\x0CHello\x0C",                           null],
            [" \t\n\r\0\x0B\x0CHello",       " \t\n\r\0\x0B\x0CHello \t\n\r\0\x0B\x0C", null],
            ["\x0CHello\x0C",                "\x0CHello\x0C",                           ' '],
        ];
    }
}
