<?php

declare(strict_types=1);

namespace Psl\MIME\Tests\Unit\Sniff\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\MIME\Sniff\Internal\JsonPrefixScanner;

use function json_validate;
use function strlen;
use function substr;

final class JsonPrefixScannerTest extends TestCase
{
    private const string DOCUMENT = <<<'JSON'
    {
        "name": "café \"quoted\" \\ \/ \b\f\n\r\t",
        "utf8": "crème brûlée 😀",
        "escapes": "\u00e9 \ud83d\ude00 \uFFFF",
        "numbers": [0, -0, 1, -12, 3.25, -0.5e10, 6E+2, 7e-3, 1234567890],
        "literals": [true, false, null],
        "nested": {"empty_object": {}, "empty_array": [], "deep": [[[{"k": [1, {"v": "x"}]}]]]},
        "last": "end"
    }
    JSON;

    public function testDocumentIsValidJson(): void
    {
        static::assertTrue(json_validate(self::DOCUMENT));
    }

    public function testEveryPrefixOfAValidDocumentIsAccepted(): void
    {
        $length = strlen(self::DOCUMENT);
        for ($i = 0; $i <= $length; $i++) {
            $prefix = substr(self::DOCUMENT, 0, $i);

            static::assertTrue(
                JsonPrefixScanner::isValidPrefix($prefix),
                'Rejected prefix of length ' . $i . ': ' . $prefix,
            );
        }
    }

    /**
     * @param non-empty-string $document
     */
    #[DataProvider('provideCompleteDocuments')]
    public function testAgreesWithJsonValidateOnCompleteDocuments(string $document): void
    {
        static::assertSame(json_validate($document), JsonPrefixScanner::isValidPrefix($document));
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideCompleteDocuments(): iterable
    {
        yield 'surrogate pair' => ['["\\ud83d\\ude00"]'];
        yield 'lone high surrogate' => ['["\\ud800"]'];
        yield 'lone low surrogate' => ['["\\udfff"]'];
        yield 'reversed surrogate pair' => ['["\\ude00\\ud83d"]'];
        yield 'high surrogate then non-surrogate' => ['["\\udbff\\u0041"]'];
        yield 'noncharacter' => ['["\\uffff"]'];
        yield 'invalid utf-8' => ["[\"\xC0\xAF\"]"];
    }

    #[DataProvider('provideValidPrefixes')]
    public function testAcceptsValidPrefix(string $input): void
    {
        static::assertTrue(JsonPrefixScanner::isValidPrefix($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidPrefixes(): iterable
    {
        yield 'open object' => ['{'];
        yield 'open array' => ['['];
        yield 'open key' => ['{"ke'];
        yield 'key without colon' => ['{"key" '];
        yield 'colon without value' => ['{"key":'];
        yield 'partial literal' => ['{"key": tr'];
        yield 'open escape' => ['["\\'];
        yield 'partial unicode escape' => ['["\\u00'];
        yield 'minus sign' => ['[-'];
        yield 'fraction dot' => ['[1.'];
        yield 'exponent marker' => ['[1e'];
        yield 'exponent sign' => ['[1E+'];
        yield 'after comma' => ['[1, '];
        yield 'truncated utf-8 sequence' => ["[\"caf\xC3"];
        yield 'truncated 4-byte utf-8 sequence' => ["[\"\xF0\x9F\x98"];
        yield 'truncated 3-byte utf-8 sequence after a restricted second byte' => ["[\"\xE0\xA0"];
        yield 'truncated 4-byte utf-8 sequence at the top of the range' => ["[\"\xF4\x8F"];
        yield 'high surrogate escape at the end' => ['["\\ud83d'];
        yield 'high surrogate escape and a backslash' => ['["\\ud83d\\'];
        yield 'high surrogate escape and a partial low surrogate escape' => ['["\\ud83d\\ude'];
        yield 'surrogate pair escape' => ['["\\ud83d\\ude00"]'];
        yield 'complete document with trailing whitespace' => ["{\"a\": [1, 2]} \r\n"];
    }

    #[DataProvider('provideInvalidPrefixes')]
    public function testRejectsInvalidPrefix(string $input): void
    {
        static::assertFalse(JsonPrefixScanner::isValidPrefix($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidPrefixes(): iterable
    {
        yield 'unquoted key' => ['{key'];
        yield 'missing colon' => ['{"key" 1'];
        yield 'missing value' => ['{"key":}'];
        yield 'trailing comma in array' => ['[1,]'];
        yield 'trailing comma in object' => ['{"a": 1,}'];
        yield 'leading comma' => ['[,1]'];
        yield 'missing comma' => ['[1 2'];
        yield 'leading zero' => ['[01'];
        yield 'empty fraction' => ['[1.e5'];
        yield 'empty exponent' => ['[1e]'];
        yield 'minus without digit' => ['[-a'];
        yield 'bad literal' => ['[tx'];
        yield 'mismatched closer' => ['{"a": 1]'];
        yield 'extra closer' => ['[]]'];
        yield 'content after document' => ['{} x'];
        yield 'control character in string' => ["[\"a\x01"];
        yield 'raw newline in string' => ["[\"a\nb\"]"];
        yield 'invalid escape' => ['["\\x'];
        yield 'invalid unicode escape' => ['["\\u12g4'];
        yield 'invalid utf-8' => ["[\"\xFF\"]"];
        yield 'invalid utf-8 continuation' => ["[\"\xC3\x28\""];
        yield 'trailing overlong lead byte' => ["[\"\xC0"];
        yield 'trailing lead byte above U+10FFFF' => ["[\"\xF5"];
        yield 'trailing 0xFF byte' => ["[\"\xFF"];
        yield 'truncated overlong 3-byte sequence' => ["[\"\xE0\x80"];
        yield 'truncated utf-8 encoded surrogate' => ["[\"\xED\xA0"];
        yield 'truncated 4-byte sequence above U+10FFFF' => ["[\"\xF4\x90"];
        yield 'lone high surrogate escape' => ['["\\ud800"]'];
        yield 'lone low surrogate escape' => ['["\\udc00'];
        yield 'high surrogate escape followed by a non-surrogate escape' => ['["\\ud800\\u0041'];
        yield 'high surrogate escape followed by another escape' => ['["\\ud800\\n'];
        yield 'high surrogate escape followed by a character' => ['["\\ud800x'];
        yield 'single quotes' => ["['a']"];
        yield 'plain text in brackets' => ['[not valid json]'];
        yield 'plain text in braces' => ['{not valid json at all}'];
    }
}
