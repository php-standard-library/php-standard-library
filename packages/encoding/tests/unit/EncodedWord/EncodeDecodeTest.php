<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit\EncodedWord;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Encoding\EncodedWord;
use Psl\Encoding\Exception;
use Psl\Str\Encoding;

use function explode;
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
