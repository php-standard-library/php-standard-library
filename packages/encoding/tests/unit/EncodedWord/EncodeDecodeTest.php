<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit\EncodedWord;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Encoding\EncodedWord;
use Psl\Encoding\Exception;
use Psl\Str\Encoding;

use function count;
use function explode;
use function mb_check_encoding;
use function mb_convert_encoding;
use function str_repeat;
use function strlen;

final class EncodeDecodeTest extends TestCase
{
    public function testEncodeEmptyString(): void
    {
        static::assertSame('', EncodedWord\encode(''));
    }

    public function testDecodeEmptyString(): void
    {
        static::assertSame('', EncodedWord\decode(''));
    }

    public function testEncodePlainAsciiPassesThrough(): void
    {
        static::assertSame('Hello, World!', EncodedWord\encode('Hello, World!'));
    }

    public function testDecodePlainTextPassesThrough(): void
    {
        static::assertSame('Hello, World!', EncodedWord\decode('Hello, World!'));
    }

    public function testEncodeNonAsciiUsesQEncoding(): void
    {
        // 2 non-ASCII bytes out of 12 total = 16% < 30%, so Q-encoding is chosen
        $encoded = EncodedWord\encode("hello caf\xC3\xA9!");

        static::assertStringStartsWith('=?UTF-8?Q?', $encoded);
        static::assertStringEndsWith('?=', $encoded);
    }

    public function testEncodeHighNonAsciiRatioUsesBEncoding(): void
    {
        $encoded = EncodedWord\encode("\xC3\xA9\xC3\xA8\xC3\xAA\xC3\xAB");

        static::assertStringStartsWith('=?UTF-8?B?', $encoded);
        static::assertStringEndsWith('?=', $encoded);
    }

    public function testDecodeQEncoded(): void
    {
        static::assertSame("caf\xC3\xA9", EncodedWord\decode('=?UTF-8?Q?caf=C3=A9?='));
    }

    public function testDecodeBEncoded(): void
    {
        static::assertSame("caf\xC3\xA9", EncodedWord\decode('=?UTF-8?B?Y2Fmw6k=?='));
    }

    /**
     * An "=" not followed by two hex digits is kept literally (RFC 2045 §6.7, note 2)
     * instead of being decoded to a NUL or other control byte.
     */
    #[DataProvider('provideInvalidQEscapes')]
    public function testDecodeQEncodedInvalidEscapeIsKeptLiterally(string $expected, string $input): void
    {
        static::assertSame($expected, EncodedWord\decode($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidQEscapes(): iterable
    {
        yield 'non-hex digits' => ['a=XYb', '=?UTF-8?Q?a=XYb?='];
        yield 'one non-hex digit' => ['a=4Gb', '=?UTF-8?Q?a=4Gb?='];
        yield 'followed by underscore' => ['100= off', '=?UTF-8?Q?100=_off?='];
        yield 'trailing' => ['a=', '=?UTF-8?Q?a=?='];
        yield 'trailing with one digit' => ['a=4', '=?UTF-8?Q?a=4?='];
        yield 'valid lowercase escape' => ['a=b', '=?UTF-8?Q?a=3db?='];
        yield 'invalid then valid escape' => ['=A', '=?UTF-8?Q?==41?='];
    }

    public function testDecodeQEncodedUnderscore(): void
    {
        static::assertSame('hello world', EncodedWord\decode('=?UTF-8?Q?hello_world?='));
    }

    public function testDecodeLowercaseEncoding(): void
    {
        static::assertSame("caf\xC3\xA9", EncodedWord\decode('=?utf-8?q?caf=C3=A9?='));
        static::assertSame("caf\xC3\xA9", EncodedWord\decode('=?utf-8?b?Y2Fmw6k=?='));
    }

    public function testDecodeAdjacentEncodedWordsWhitespaceRemoved(): void
    {
        $input = '=?UTF-8?Q?hel?= =?UTF-8?Q?lo?=';

        static::assertSame('hello', EncodedWord\decode($input));
    }

    public function testDecodeAdjacentEncodedWordsNonWhitespacePreserved(): void
    {
        $input = '=?UTF-8?Q?hel?=X=?UTF-8?Q?lo?=';

        static::assertSame('helXlo', EncodedWord\decode($input));
    }

    public function testDecodeAdjacentEncodedWordsFoldingWhitespaceRemoved(): void
    {
        $input = "=?UTF-8?Q?hel?=\r\n \t=?UTF-8?Q?lo?=";

        static::assertSame('hello', EncodedWord\decode($input));
    }

    #[DataProvider('provideNonWhitespaceControlCharacters')]
    public function testDecodeAdjacentEncodedWordsControlCharacterPreserved(string $char): void
    {
        $input = "=?UTF-8?Q?hel?= {$char} =?UTF-8?Q?lo?=";

        static::assertSame("hel {$char} lo", EncodedWord\decode($input));
    }

    /**
     * RFC 2047 Section 6.2 only ignores linear-white-space between adjacent encoded-words.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideNonWhitespaceControlCharacters(): iterable
    {
        yield 'form feed' => ["\x0C"];
        yield 'vertical tab' => ["\x0B"];
        yield 'NUL byte' => ["\0"];
    }

    public function testDecodeMixedEncodedAndPlainText(): void
    {
        $input = 'Subject: =?UTF-8?Q?caf=C3=A9?= is great';

        static::assertSame("Subject: caf\xC3\xA9 is great", EncodedWord\decode($input));
    }

    public function testDecodeInvalidBase64Throws(): void
    {
        $this->expectException(Exception\ParsingException::class);

        EncodedWord\decode('=?UTF-8?B?!!!invalid!!!?=');
    }

    public function testDecodeCharsetConversion(): void
    {
        $latin1Bytes = "\xe9";
        $encoded = '=?ISO-8859-1?Q?=E9?=';

        $decoded = EncodedWord\decode($encoded);

        static::assertSame("\xC3\xA9", $decoded);
    }

    #[DataProvider('provideUnsupportedCharsets')]
    public function testDecodeUnsupportedCharsetThrows(string $input): void
    {
        $this->expectException(Exception\ParsingException::class);
        $this->expectExceptionMessage('Unsupported encoded-word charset');

        EncodedWord\decode($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnsupportedCharsets(): iterable
    {
        yield 'Q-encoded' => ['=?X-UNKNOWN?Q?abc?='];
        yield 'B-encoded' => ['=?X-UNKNOWN?B?YWJj?='];
        yield 'between plain text' => ['Re: =?x-made-up-charset?Q?caf=E9?= time'];
    }

    /**
     * RFC 2231 §5: "encoded-word := "=?" charset ["*" language] "?" encoded-text "?="".
     */
    #[DataProvider('provideCharsetsWithLanguage')]
    public function testDecodeCharsetWithLanguage(string $expected, string $input): void
    {
        static::assertSame($expected, EncodedWord\decode($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideCharsetsWithLanguage(): iterable
    {
        yield 'RFC 2231 example' => ['Keith Moore', '=?US-ASCII*EN?Q?Keith_Moore?='];
        yield 'converted charset' => ["caf\u{e9}", '=?ISO-8859-1*fr?Q?caf=E9?='];
        yield 'language with region' => ["caf\u{e9}", '=?utf-8*en-US?B?Y2Fmw6k=?='];
    }

    public function testDecodeUsAsciiCharset(): void
    {
        static::assertSame('hello', EncodedWord\decode('=?US-ASCII?Q?hello?='));
    }

    public function testDecodeAsciiCharset(): void
    {
        static::assertSame('hello', EncodedWord\decode('=?ASCII?Q?hello?='));
    }

    public function testEncodeCustomCharset(): void
    {
        $encoded = EncodedWord\encode("caf\xC3\xA9", Encoding::Iso88591);

        static::assertStringStartsWith('=?ISO-8859-1?', $encoded);
    }

    /**
     * @param non-empty-string $input
     */
    #[DataProvider('provideRoundTripData')]
    public function testRoundTrip(string $input): void
    {
        $encoded = EncodedWord\encode($input);
        $decoded = EncodedWord\decode($encoded);

        static::assertSame($input, $decoded);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideRoundTripData(): iterable
    {
        yield 'simple non-ascii' => ["caf\xC3\xA9"];
        yield 'mostly non-ascii (B-encoding)' => ["\xC3\xA9\xC3\xA8\xC3\xAA\xC3\xAB\xC3\xAC"];
        yield 'mixed ascii and non-ascii' => ["Hello \xC3\xA9 World"];
        yield 'special chars' => ['test=value?question_underscore'];
        yield 'long non-ascii' => [str_repeat("\xC3\xA9", 50)];
    }

    public function testEncodedWordTokenLengthLimit(): void
    {
        $encoded = EncodedWord\encode(str_repeat("\xC3\xA9", 50));

        $words = explode(' ', $encoded);
        foreach ($words as $word) {
            static::assertLessThanOrEqual(75, strlen($word));
        }
    }

    /**
     * RFC 2047 §5: each encoded-word must represent an integral number of characters,
     * so a multi-byte character must never be split across two encoded-words.
     */
    #[DataProvider('provideMultiByteSplitData')]
    public function testEncodedWordsContainWholeCharacters(string $input, string $encoding): void
    {
        $encoded = EncodedWord\encode($input);

        $words = explode(' ', $encoded);
        static::assertGreaterThan(1, count($words));

        $decoded = '';
        foreach ($words as $word) {
            static::assertLessThanOrEqual(75, strlen($word));
            static::assertStringStartsWith('=?UTF-8?' . $encoding . '?', $word);

            $text = EncodedWord\decode($word);
            static::assertTrue(mb_check_encoding($text, 'UTF-8'), 'Encoded-word contains a partial character.');

            $decoded .= $text;
        }

        static::assertSame($input, $decoded);
        static::assertSame($input, EncodedWord\decode($encoded));
    }

    /**
     * @return iterable<string, array{non-empty-string, string}>
     */
    public static function provideMultiByteSplitData(): iterable
    {
        yield 'B: 2-byte characters' => [str_repeat("\u{e9}", 40), 'B'];
        yield 'B: 3-byte characters' => ['x' . str_repeat("\u{20ac}", 40), 'B'];
        yield 'B: 4-byte characters' => [str_repeat("\u{1F600}", 20), 'B'];
        yield 'Q: 2-byte characters' => [str_repeat("abcde\u{e9}", 20), 'Q'];
        yield 'Q: 3-byte characters' => [str_repeat("abcdefgh\u{20ac}", 10), 'Q'];
        yield 'Q: 4-byte characters' => [str_repeat("abcdefghijklmn\u{1F600}", 6), 'Q'];
    }

    public function testEncodedWordsContainWholeCharactersOfTheGivenCharset(): void
    {
        $input = 'x' . mb_convert_encoding(str_repeat("\u{65e5}\u{672c}\u{8a9e}", 20), 'SJIS', 'UTF-8');
        $encoded = EncodedWord\encode($input, Encoding::Sjis);

        $words = explode(' ', $encoded);
        static::assertGreaterThan(1, count($words));

        $decoded = '';
        foreach ($words as $word) {
            static::assertStringStartsWith('=?SJIS?B?', $word);
            $decoded .= EncodedWord\decode($word);
        }

        static::assertSame('x' . str_repeat("\u{65e5}\u{672c}\u{8a9e}", 20), $decoded);
    }

    public function testDecodeNoEncodedWords(): void
    {
        $plain = 'Subject: Just a normal header';

        static::assertSame($plain, EncodedWord\decode($plain));
    }

    public function testDecodeDuplicateEncodedWords(): void
    {
        $input = '=?UTF-8?Q?hello?= =?UTF-8?Q?hello?=';

        $decoded = EncodedWord\decode($input);

        static::assertSame('hellohello', $decoded);
    }

    public function testDecodeWithOverlappingMatches(): void
    {
        $input = '=?UTF-8?Q?a?= =?UTF-8?Q?a?=';
        $decoded = EncodedWord\decode($input);
        static::assertSame('aa', $decoded);
    }

    public function testEncodeSpaceBecomesUnderscore(): void
    {
        $encoded = EncodedWord\encode("hello world\x80");

        static::assertStringContainsString('_', $encoded);
        static::assertSame("hello world\x80", EncodedWord\decode($encoded));
    }
}
