<?php

declare(strict_types=1);

namespace Psl\Html\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Html;

final class EncodeTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testEncode(string $expected, string $html, bool $doubleEncoding, Html\Encoding $encoding): void
    {
        static::assertSame($expected, Html\encode($html, $doubleEncoding, $encoding));
    }

    public static function provideData(): iterable
    {
        yield ['hello', 'hello', true, Html\Encoding::Utf8];
        yield ['h&eacute;llo', 'héllo', true, Html\Encoding::Utf8];
        yield ['&lt;hello /&gt;', '<hello />', true, Html\Encoding::Utf8];
        yield ['&lt;p&gt;Hello&lt;/p&gt;', '<p>Hello</p>', true, Html\Encoding::Utf8];
        yield ['&lt;p&gt;&amp;lt; &lt;/p&gt;', '<p>&lt; </p>', true, Html\Encoding::Utf8];

        yield ['hello', 'hello', false, Html\Encoding::Utf8];
        yield ['h&eacute;llo', 'héllo', false, Html\Encoding::Utf8];
        yield ['&lt;hello /&gt;', '<hello />', false, Html\Encoding::Utf8];
        yield ['&lt;p&gt;Hello&lt;/p&gt;', '<p>Hello</p>', false, Html\Encoding::Utf8];
        yield ['&lt;p&gt;&lt; &lt;/p&gt;', '<p>&lt; </p>', false, Html\Encoding::Utf8];
        yield ['&#039;&quot;', '\'"', true, Html\Encoding::Utf8];
        yield ["a\nb\tc", "a\nb\tc", true, Html\Encoding::Utf8];

        // Invalid code unit sequences are substituted instead of discarding the whole string.
        yield ["\u{FFFD}( bad", "\xC3\x28 bad", true, Html\Encoding::Utf8];
        yield ["caf\u{FFFD} &amp; cr&egrave;me", "caf\xE9 & crème", true, Html\Encoding::Utf8];
        yield ["caf\u{FFFD} &lt;b&gt;", "caf\xE9 <b>", false, Html\Encoding::Utf8];
    }

    public function testInvalidInputIsSubstitutedLikeEncodeSpecialCharacters(): void
    {
        $html = "\xC3\x28<b>";

        static::assertSame(Html\encode_special_characters($html), Html\encode($html));
    }

    #[DataProvider('provideRoundTripData')]
    public function testDecodeReversesEncode(string $html): void
    {
        static::assertSame($html, Html\decode(Html\encode($html)));
    }

    public static function provideRoundTripData(): iterable
    {
        yield ['hello'];
        yield ['<p class="x">It\'s &amp; "quoted"</p>'];
        yield ["h\u{e9}llo \u{2122} \u{20ac} \u{a9}\n\t"];
    }
}
