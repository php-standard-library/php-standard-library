<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Encoding\Exception;
use Psl\Encoding\Hex;
use Psl\SecureRandom;

use function bin2hex;

final class HexTest extends TestCase
{
    #[DataProvider('provideRandomBytes')]
    public function testRandom(string $random): void
    {
        $enc = Hex\encode($random);
        static::assertSame($random, Hex\decode($enc));
        static::assertSame(bin2hex($random), $enc);
        $enc = Hex\encode($random);
        static::assertSame($random, Hex\decode($enc));
    }

    public function testEmptyString(): void
    {
        static::assertSame('', Hex\encode(''));
        static::assertSame('', Hex\decode(''));
        static::assertSame('', Hex\decode(Hex\encode('')));
    }

    public function testDecodeThrowsForCharactersOutsideTheHexRange(): void
    {
        $this->expectException(Exception\RangeException::class);

        Hex\decode('gf');
    }

    public function testDecodeThrowsForAnOddNumberOfCharacters(): void
    {
        $this->expectException(Exception\RangeException::class);

        Hex\decode('f');
    }

    public static function provideRandomBytes(): iterable
    {
        for ($i = 1; $i < 128; ++$i) {
            yield [SecureRandom\bytes($i)];
        }
    }
}
