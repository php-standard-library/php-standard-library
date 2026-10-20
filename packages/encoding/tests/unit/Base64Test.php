<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Encoding\Base64;
use Psl\Encoding\Exception;
use Psl\Regex;
use Psl\SecureRandom;

final class Base64Test extends TestCase
{
    #[DataProvider('provideRandomBytes')]
    public function testEncodeAndDecode(string $random): void
    {
        $encoded = Base64\encode($random, Base64\Variant::default());

        static::assertSame($random, Base64\decode($encoded));
        static::assertSame($random, Base64\decode($encoded, Base64\Variant::default()));
    }

    public function testDecodeThrowsForCharactersOutsideTheBase64Range(): void
    {
        $this->expectException(Exception\RangeException::class);

        Base64\decode('@~==');
    }

    public function testDecodeThrowsForIncorrectPadding(): void
    {
        $this->expectException(Exception\IncorrectPaddingException::class);

        Base64\decode('ab');
    }

    #[DataProvider('provideTrailingLineFeedCases')]
    public function testDecodeThrowsForATrailingLineFeed(string $base64, Base64\Variant $variant, bool $padding): void
    {
        $this->expectException(Exception\RangeException::class);
        $this->expectExceptionMessage('outside the base64 range');

        Base64\decode($base64, $variant, $padding);
    }

    /**
     * @return iterable<string, array{string, Base64\Variant, bool}>
     */
    public static function provideTrailingLineFeedCases(): iterable
    {
        $variants = [
            Base64\Variant::Standard,
            Base64\Variant::UrlSafe,
            Base64\Variant::DotSlash,
            Base64\Variant::DotSlashOrdered,
        ];

        foreach ($variants as $variant) {
            foreach ([true, false] as $padding) {
                $name = $variant->name . ($padding ? ', padded' : ', unpadded');

                yield $name . ', after a complete group' => ["SGVs\n", $variant, $padding];
                yield $name . ', after padding' => ["SGVsbA==\n", $variant, $padding];
            }
        }
    }

    #[DataProvider('provideRandomBytes')]
    public function testEncodeWithoutPaddingThenDecode(string $random): void
    {
        $encoded = Base64\encode($random, Base64\Variant::default(), false);
        static::assertFalse(Regex\matches($encoded, '/={1,3}$/'));
        static::assertSame($random, Base64\decode($encoded, Base64\Variant::default(), false));
    }

    public static function provideRandomBytes(): iterable
    {
        for ($i = 1; $i < 128; ++$i) {
            yield [SecureRandom\bytes($i)];
        }
    }
}
