<?php

declare(strict_types=1);

namespace Psl\MIME\Tests\Unit\Multipart;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Encoding\Base64;
use Psl\IO;
use Psl\MIME\ContentDisposition;
use Psl\MIME\Exception\MultiPartException;
use Psl\MIME\MultiPart\Parser;
use Psl\Str;

use function iterator_to_array;
use function str_pad;
use function strpos;

use const STR_PAD_LEFT;

final class ParserTest extends TestCase
{
    public function testSimpleTwoParts(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nHello\r\n--boundary\r\nContent-Type: text/html\r\n\r\n<b>World</b>\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(2, $parts);
        static::assertSame('Hello', $parts[0]->body->readAll());
        static::assertSame('text/plain', $parts[0]->mediaType->essence());
        static::assertSame('<b>World</b>', $parts[1]->body->readAll());
        static::assertSame('text/html', $parts[1]->mediaType->essence());
    }

    public function testFormDataWithContentDisposition(): void
    {
        $body = "--boundary\r\nContent-Disposition: form-data; name=\"field1\"\r\n\r\nvalue1\r\n--boundary\r\nContent-Disposition: form-data; name=\"field2\"; filename=\"file.txt\"\r\nContent-Type: text/plain\r\n\r\nfile content\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(2, $parts);

        $dispositionValue1 = $parts[0]->headers->get('content-disposition');
        static::assertNotNull($dispositionValue1);
        $disposition1 = ContentDisposition::parse($dispositionValue1);
        static::assertSame('field1', $disposition1->parameters->get('name'));

        $dispositionValue2 = $parts[1]->headers->get('content-disposition');
        static::assertNotNull($dispositionValue2);
        $disposition2 = ContentDisposition::parse($dispositionValue2);
        static::assertSame('field2', $disposition2->parameters->get('name'));
        static::assertSame('file.txt', $disposition2->parameters->get('filename'));
    }

    public function testEmptyPart(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('', $parts[0]->body->readAll());
    }

    public function testPreambleIgnored(): void
    {
        $body = "This is the preamble.\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nBody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('Body', $parts[0]->body->readAll());
    }

    public function testEpilogueIgnored(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nBody\r\n--boundary--\r\nThis is the epilogue.";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('Body', $parts[0]->body->readAll());
    }

    public function testMissingBoundaryThrows(): void
    {
        $this->expectException(MultiPartException::class);

        $parser = new Parser('boundary');
        iterator_to_array($parser->parse(new IO\MemoryHandle('no boundary here')));
    }

    public function testMissingClosingDelimiterThrows(): void
    {
        $this->expectException(MultiPartException::class);

        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nBody\r\n";
        $parser = new Parser('boundary');
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testInvalidBoundaryEmpty(): void
    {
        $this->expectException(MultiPartException::class);

        new Parser('');
    }

    public function testInvalidBoundaryTooLong(): void
    {
        $this->expectException(MultiPartException::class);

        new Parser(Str\repeat('a', 71));
    }

    public function testDefaultContentType(): void
    {
        $body = "--boundary\r\n\r\nno headers\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('text/plain', $parts[0]->mediaType->essence());
    }

    public function testNoContentDisposition(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nBody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertNull($parts[0]->headers->get('content-disposition'));
    }

    public function testLineFoldedHeaders(): void
    {
        $body = "--boundary\r\nContent-Type:\r\n text/plain\r\n\r\nBody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('text/plain', $parts[0]->mediaType->essence());
    }

    public function testLfLineEndings(): void
    {
        $body = "--boundary\nContent-Type: text/plain\n\nBody\n--boundary--\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('Body', $parts[0]->body->readAll());
    }

    public function testDecodeBase64TransferEncoding(): void
    {
        $encoded = Base64\encode('Hello, World!');
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: base64\r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('Hello, World!', $parts[0]->body->readAll());
    }

    public function testDecodeQuotedPrintableTransferEncoding(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nHello=20World\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('Hello World', $parts[0]->body->readAll());
    }

    public function testDecodeQuotedPrintableTransferEncodingKeepsTrailingLineBreaks(): void
    {
        $body = "--boundary\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nHello=20World\r\n\r\n\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame("Hello World\r\n\r\n", $parts[0]->body->readAll());
    }

    public function testDecodeTransferEncodingStripsHeader(): void
    {
        $encoded = Base64\encode('data');
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: base64\r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        foreach ($parts[0]->headers->pairs() as [$name, $_]) {
            static::assertNotSame('Content-Transfer-Encoding', $name);
        }
    }

    public function testDecodeTransferEncodingPassthrough7bit(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: 7bit\r\n\r\nASCII text\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('ASCII text', $parts[0]->body->readAll());
    }

    public function testNoDecodeByDefault(): void
    {
        $encoded = Base64\encode('Hello');
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: base64\r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame($encoded, $parts[0]->body->readAll());
    }

    public function testMaxPartsLimitThrows(): void
    {
        $this->expectException(MultiPartException::class);

        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nPart1\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nPart2\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nPart3\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxParts: 2);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testMaxPartsZeroIsUnlimited(): void
    {
        $body = '';
        for ($i = 0; $i < 20; $i++) {
            $body .= "--boundary\r\nContent-Type: text/plain\r\n\r\nPart{$i}\r\n";
        }

        $body .= "--boundary--\r\n";

        $parser = new Parser('boundary', maxParts: 0);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(20, $parts);
    }

    public function testCustomSpoolThreshold(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nHello\r\n--boundary--\r\n";

        $parser = new Parser('boundary', spoolThreshold: 1024);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('Hello', $parts[0]->body->readAll());
    }

    public function testLargePartBody(): void
    {
        $largeBody = Str\repeat('x', 100_000);
        $body = "--boundary\r\nContent-Type: application/octet-stream\r\n\r\n{$largeBody}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', spoolThreshold: 1024);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame($largeBody, $parts[0]->body->readAll());
    }

    public function testPartsYieldedLazily(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nFirst\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nSecond\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $generator = $parser->parse(new IO\MemoryHandle($body));

        $first = $generator->current();
        static::assertNotNull($first);
        static::assertSame('First', $first->body->readAll());

        $generator->next();
        $second = $generator->current();
        static::assertNotNull($second);
        static::assertSame('Second', $second->body->readAll());

        $generator->next();
        static::assertFalse($generator->valid());
    }

    public function testMaxPartSizeThrows(): void
    {
        $this->expectException(MultiPartException::class);

        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n" . Str\repeat('A', 1000) . "\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 500);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testMaxPartSizeAllowsWithinLimit(): void
    {
        $content = Str\repeat('A', 500);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n" . $content . "\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 500);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testMaxPartSizeZeroIsUnlimited(): void
    {
        $content = Str\repeat('A', 10_000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n" . $content . "\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 0);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testSpoolThresholdDefault(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nHello\r\n--boundary--\r\n";
        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
        static::assertCount(1, $parts);
        static::assertSame('Hello', $parts[0]->body->readAll());
    }

    public function testBoundaryExactly70CharsValid(): void
    {
        $boundary = Str\repeat('a', 70);
        $parser = new Parser($boundary);

        $body = "--{$boundary}\r\nContent-Type: text/plain\r\n\r\nTest\r\n--{$boundary}--\r\n";
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('Test', $parts[0]->body->readAll());
    }

    public function testMaxPartsExactlyAtLimit(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nPart1\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nPart2\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxParts: 2);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(2, $parts);
    }

    public function testMaxPartsExceedingLimitThrows(): void
    {
        $this->expectException(MultiPartException::class);

        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nPart1\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nPart2\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nPart3\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxParts: 2);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testEmptyHeaderSectionReturnsNoHeaders(): void
    {
        $body = "--boundary\r\n\r\nbody text\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('text/plain', $parts[0]->mediaType->essence());
    }

    public function testHeadersWithCRLFNormalized(): void
    {
        $body = "--boundary\r\nContent-Type: text/html\r\nX-Custom: value\r\n\r\nbody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('text/html', $parts[0]->mediaType->essence());
        static::assertSame('value', $parts[0]->headers->get('x-custom'));
    }

    public function testHeaderContinuationWithTab(): void
    {
        $body = "--boundary\r\nContent-Type:\r\n\ttext/plain\r\n\r\nBody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('text/plain', $parts[0]->mediaType->essence());
    }

    public function testHeaderContinuationPreservesAndAppendsValue(): void
    {
        $body = "--boundary\r\nX-Long-Header: first part\r\n second part\r\n\r\nBody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        $value = $parts[0]->headers->get('x-long-header');
        static::assertNotNull($value);
        static::assertSame('first part second part', $value);
    }

    public function testMultipleHeaderContinuationLines(): void
    {
        $body = "--boundary\r\nX-Multi: part1\r\n part2\r\n part3\r\n\r\nBody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        $value = $parts[0]->headers->get('x-multi');
        static::assertNotNull($value);
        static::assertStringContainsString('part1', $value);
        static::assertStringContainsString('part2', $value);
        static::assertStringContainsString('part3', $value);
    }

    public function testHeaderNameAndValueParsedCorrectly(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\nX-Test:  value-with-spaces  \r\n\r\nBody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('value-with-spaces', $parts[0]->headers->get('x-test'));
    }

    public function testHeaderValueStartsAfterColon(): void
    {
        $body = "--boundary\r\nX-NoSpace:value\r\n\r\nBody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('value', $parts[0]->headers->get('x-nospace'));
    }

    public function testCloseDelimiterStopsParsing(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nFirst\r\n--boundary--\r\nSome trailing epilogue";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('First', $parts[0]->body->readAll());
    }

    public function testDecodeTransferEncoding8bitPassthrough(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: 8bit\r\n\r\nHigh bytes: \xC3\xA9\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame("High bytes: \xC3\xA9", $parts[0]->body->readAll());
    }

    public function testDecodeTransferEncodingBinaryPassthrough(): void
    {
        $body = "--boundary\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: binary\r\n\r\n\x00\x01\x02\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame("\x00\x01\x02", $parts[0]->body->readAll());
    }

    public function testMaxPartSizeExactlyAtLimitSucceeds(): void
    {
        $content = Str\repeat('A', 100);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n" . $content . "\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 100);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testMaxPartSizeExceededByOneThrows(): void
    {
        $this->expectException(MultiPartException::class);

        $content = Str\repeat('A', 101);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n" . $content . "\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 100);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testNoBoundaryThrows(): void
    {
        $this->expectException(MultiPartException::class);

        $parser = new Parser('boundary');
        iterator_to_array($parser->parse(new IO\MemoryHandle('this has no boundary at all')));
    }

    public function testDecodeTransferEncodingCaseInsensitive(): void
    {
        $encoded = Base64\encode('Test');
        $body = "--boundary\r\nContent-Type: text/plain\r\nCONTENT-TRANSFER-ENCODING: base64\r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('Test', $parts[0]->body->readAll());
    }

    public function testDecodeTransferEncodingValueTrimmed(): void
    {
        $encoded = Base64\encode('Trimmed');
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding:  base64 \r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('Trimmed', $parts[0]->body->readAll());
    }

    public function testDecodeTransferEncodingValueWithNonWspCharacterIsNotDecoded(): void
    {
        $encoded = Base64\encode('Test');
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: base64\x0C\r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame("base64\x0C", $parts[0]->headers->get('content-transfer-encoding'));
        static::assertSame($encoded, $parts[0]->body->readAll());
    }

    #[DataProvider('provideNonWspControlCharacters')]
    public function testHeaderValueKeepsSurroundingNonWspCharacters(string $char): void
    {
        $body = "--boundary\r\nX-Custom: {$char}value{$char} \r\nX-Folded: first\r\n \t{$char}second{$char}\r\n\r\nbody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame("{$char}value{$char}", $parts[0]->headers->get('x-custom'));
        static::assertSame("first {$char}second{$char}", $parts[0]->headers->get('x-folded'));
    }

    /**
     * Header fields are separated from their values by WSP (SP / HTAB) only.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideNonWspControlCharacters(): iterable
    {
        yield 'form feed' => ["\x0C"];
        yield 'vertical tab' => ["\x0B"];
        yield 'NUL byte' => ["\0"];
    }

    public function testDefaultSpoolThresholdIs2MB(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nHello\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
    }

    public function testDefaultMaxPartsIsZeroUnlimited(): void
    {
        $body = '';
        for ($i = 0; $i < 5; $i++) {
            $body .= "--boundary\r\nContent-Type: text/plain\r\n\r\nPart{$i}\r\n";
        }

        $body .= "--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(5, $parts);
    }

    public function testDefaultMaxPartSizeIsZeroUnlimited(): void
    {
        $content = Str\repeat('X', 5000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testMissingBoundaryThrowsWithThrowKeyword(): void
    {
        $this->expectException(MultiPartException::class);

        $parser = new Parser('boundary');
        iterator_to_array($parser->parse(new IO\MemoryHandle('no boundary here at all')));
    }

    public function testCloseDelimiterBreaksLoop(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nFirst\r\n--boundary--\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nSecond\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('First', $parts[0]->body->readAll());
    }

    public function testEmptyHeaderSectionReturnsEmptyArray(): void
    {
        $body = "--boundary\r\n\r\nno-header body\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('text/plain', $parts[0]->mediaType->essence());
    }

    public function testHeaderSectionCRLFToLFNormalization(): void
    {
        $body = "--boundary\r\nContent-Type: text/html\r\nX-Custom: value\r\n\r\nbody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('text/html', $parts[0]->mediaType->essence());
        static::assertSame('value', $parts[0]->headers->get('x-custom'));
    }

    public function testHeaderNameTrimmed(): void
    {
        $body = "--boundary\r\n Content-Type : text/plain\r\n\r\nBody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
    }

    public function testParserBodyContentPreservedExactly(): void
    {
        $content = 'Hello, World! This is some content.';
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testMultiplePartsBodyContent(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nAlpha\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nBravo\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nCharlie\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(3, $parts);
        static::assertSame('Alpha', $parts[0]->body->readAll());
        static::assertSame('Bravo', $parts[1]->body->readAll());
        static::assertSame('Charlie', $parts[2]->body->readAll());
    }

    public function testMaxPartSizeExceededThrowsWithCorrectMessage(): void
    {
        $content = Str\repeat('A', 200);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessageMatches('/part body exceeds maximum size of 100 bytes/');

        $parser = new Parser('boundary', maxPartSize: 100);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testLargeBodyStreamedCorrectly(): void
    {
        $content = Str\repeat('Z', 50_000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', spoolThreshold: 1024);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame(50_000, Str\Byte\length($parts[0]->body->readAll()));
    }

    public function testMaxPartSizeExactBoundaryNoThrow(): void
    {
        $content = Str\repeat('B', 50);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 50);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testMaxPartSizeExceededByOneByte(): void
    {
        $this->expectException(MultiPartException::class);

        $content = Str\repeat('C', 51);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 50);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testTwoPartsSecondBodyReadCorrectly(): void
    {
        $body = "--delim\r\nContent-Type: text/plain\r\n\r\nAAA\r\n--delim\r\nContent-Type: text/plain\r\n\r\nBBB\r\n--delim--\r\n";

        $parser = new Parser('delim');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(2, $parts);
        static::assertSame('AAA', $parts[0]->body->readAll());
        static::assertSame('BBB', $parts[1]->body->readAll());
    }

    public function testDecodeTransferEncodingMixedCase(): void
    {
        $encoded = Base64\encode('CaseTest');
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: BASE64\r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('CaseTest', $parts[0]->body->readAll());
    }

    public function testMaxPartSizeEnforcedInStreamBodyToSpool(): void
    {
        $content = Str\repeat('A', 10_000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessageMatches('/part body exceeds maximum size of.*5000.*bytes/');

        $parser = new Parser('boundary', maxPartSize: 5_000);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testMaxPartSizeErrorMessageFormat(): void
    {
        $content = Str\repeat('Z', 300);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessage('Malformed multipart body: part body exceeds maximum size of 100 bytes.');

        $parser = new Parser('boundary', maxPartSize: 100);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testBodyWithContentBeforeBoundary(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nSomePrefix\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nBody2\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(2, $parts);
        static::assertSame('SomePrefix', $parts[0]->body->readAll());
        static::assertSame('Body2', $parts[1]->body->readAll());
    }

    public function testLargeBodyContentIntegrity(): void
    {
        $uniqueStart = 'START_MARKER_';
        $uniqueEnd = '_END_MARKER';
        $padding = Str\repeat('X', 20_000);
        $content = $uniqueStart . $padding . $uniqueEnd;
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', spoolThreshold: 256);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        $result = $parts[0]->body->readAll();
        static::assertSame($content, $result);
        static::assertStringStartsWith($uniqueStart, $result);
        static::assertStringEndsWith($uniqueEnd, $result);
    }

    public function testMultipleLargePartsIntegrity(): void
    {
        $content1 = Str\repeat('A', 15_000);
        $content2 = Str\repeat('B', 15_000);

        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content1}\r\n--boundary\r\nContent-Type: text/plain\r\n\r\n{$content2}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', spoolThreshold: 256);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(2, $parts);
        static::assertSame($content1, $parts[0]->body->readAll());
        static::assertSame($content2, $parts[1]->body->readAll());
    }

    public function testHeaderNameWithLeadingSpaceTrimmed(): void
    {
        $body = "--boundary\r\n  Content-Type  : text/plain\r\n\r\nBody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
    }

    public function testDecodeTransferEncodingPreservesOtherHeaders(): void
    {
        $encoded = Base64\encode('Preserved');
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: base64\r\nX-Custom: keep-me\r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('Preserved', $parts[0]->body->readAll());
        static::assertSame('keep-me', $parts[0]->headers->get('x-custom'));
        static::assertNull($parts[0]->headers->get('content-transfer-encoding'));
    }

    public function testMaxPartSizeThrowsWithCorrectMessage(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n" . Str\repeat('A', 600) . "\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessageMatches('/part body exceeds maximum size of.*500.*bytes/');

        $parser = new Parser('boundary', maxPartSize: 500);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testMaxPartSizeThrowsMessageFormat(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n" . Str\repeat('B', 200) . "\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessageMatches('/part body exceeds maximum size of 50 bytes/');

        $parser = new Parser('boundary', maxPartSize: 50);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testMaxPartSizeExceptionMessageContainsSizeBeforeBytes(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n" . Str\repeat('C', 300) . "\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 100);

        try {
            iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
            static::fail('Expected MultiPartException');
        } catch (MultiPartException $e) {
            $msg = $e->getMessage();
            $sizePos = strpos($msg, '100');
            $bytesPos = strpos($msg, 'bytes');
            static::assertNotFalse($sizePos);
            static::assertNotFalse($bytesPos);
            static::assertGreaterThan($sizePos, $bytesPos);
        }
    }

    public function testMissingClosingDelimiterFlushesBuffer(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nSome content here";

        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessageMatches('/missing closing delimiter/');

        $parser = new Parser('boundary');
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testMissingClosingDelimiterWithBufferContentStillThrows(): void
    {
        $content = Str\repeat('data', 100);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n" . $content;

        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessageMatches('/missing closing delimiter/');

        $parser = new Parser('boundary');
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testMissingClosingDelimiterFlushesBufferedContentToSpool(): void
    {
        $content = 'partial-data-without-close';
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n" . $content;

        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessageMatches('/missing closing delimiter/');

        $parser = new Parser('boundary');
        $generator = $parser->parse(new IO\MemoryHandle($body));
        iterator_to_array($generator);
    }

    public function testMaxPartSizeEnforcedViaThrow(): void
    {
        $content = Str\repeat('Z', 2000);
        $body = "--boundary\r\nContent-Type: application/octet-stream\r\n\r\n" . $content . "\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);

        $parser = new Parser('boundary', maxPartSize: 1000);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testMaxPartSizeLargeContentThrowsWithDetails(): void
    {
        $content = Str\repeat('X', 5000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n" . $content . "\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessageMatches('/2048/');

        $parser = new Parser('boundary', maxPartSize: 2048);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testDefaultSpoolThresholdValue(): void
    {
        $content = Str\repeat('Y', 2_097_152);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame(2_097_152, Str\Byte\length($parts[0]->body->readAll()));
    }

    public function testCustomSpoolThresholdSmallValue(): void
    {
        $content = Str\repeat('Z', 1000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', spoolThreshold: 100);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testSpoolThresholdZeroForcesImmediateDisk(): void
    {
        $content = Str\repeat('A', 500);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', spoolThreshold: 0);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testDefaultMaxPartsAllowsManyParts(): void
    {
        $body = '';
        for ($i = 0; $i < 10; $i++) {
            $body .= "--boundary\r\nContent-Type: text/plain\r\n\r\nPart{$i}\r\n";
        }

        $body .= "--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(10, $parts);
    }

    public function testMaxPartsOneAllowsExactlyOnePart(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nOnly\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxParts: 1);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('Only', $parts[0]->body->readAll());
    }

    public function testMaxPartsOneWithTwoPartsThrows(): void
    {
        $this->expectException(MultiPartException::class);

        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nFirst\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nSecond\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxParts: 1);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testDefaultMaxPartSizeAllowsLargeContent(): void
    {
        $content = Str\repeat('W', 50_000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame(50_000, Str\Byte\length($parts[0]->body->readAll()));
    }

    public function testMaxPartSizeOneThrowsForAnyContent(): void
    {
        $this->expectException(MultiPartException::class);

        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nAB\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 1);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testMaxPartSizeOneAllowsSingleByte(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nA\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 1);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('A', $parts[0]->body->readAll());
    }

    public function testNoBoundaryInBodyThrowsException(): void
    {
        $parser = new Parser('myboundary');
        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessage('boundary not found');
        iterator_to_array($parser->parse(new IO\MemoryHandle('just some text without any boundary')));
    }

    public function testNoBoundaryReturnsZeroParts(): void
    {
        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessageMatches('/boundary not found/');

        $parser = new Parser('unique-boundary');
        iterator_to_array($parser->parse(new IO\MemoryHandle('')));
    }

    public function testNoBoundaryThrowsNotSilentlyIgnored(): void
    {
        $this->expectException(MultiPartException::class);

        $parser = new Parser('missing');
        iterator_to_array($parser->parse(new IO\MemoryHandle('random data with no boundaries anywhere')));
    }

    public function testCloseDelimiterProducesExactPartCount(): void
    {
        $body = "--bd\r\nContent-Type: text/plain\r\n\r\nA\r\n--bd\r\nContent-Type: text/plain\r\n\r\nB\r\n--bd--\r\n--bd\r\nContent-Type: text/plain\r\n\r\nC\r\n--bd--\r\n";

        $parser = new Parser('bd');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(2, $parts);
    }

    public function testCloseDelimiterIgnoresTrailingParts(): void
    {
        $body = "--bd\r\nContent-Type: text/plain\r\n\r\nOnly\r\n--bd--\r\n--bd\r\nContent-Type: text/plain\r\n\r\nExtra\r\n--bd--\r\n";

        $parser = new Parser('bd');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('Only', $parts[0]->body->readAll());
    }

    public function testCloseDelimiterDoesNotYieldPartsAfterClose(): void
    {
        $body = "--b\r\nContent-Type: text/plain\r\n\r\nX\r\n--b\r\nContent-Type: text/plain\r\n\r\nY\r\n--b\r\nContent-Type: text/plain\r\n\r\nZ\r\n--b--\r\n--b\r\nContent-Type: text/plain\r\n\r\nW\r\n--b--\r\n";

        $parser = new Parser('b');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(3, $parts);
        static::assertSame('X', $parts[0]->body->readAll());
        static::assertSame('Y', $parts[1]->body->readAll());
        static::assertSame('Z', $parts[2]->body->readAll());
    }

    public function testEmptyHeaderSectionYieldsDefaultContentType(): void
    {
        $body = "--boundary\r\n\r\nsome body\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('some body', $parts[0]->body->readAll());
        static::assertSame('text/plain', $parts[0]->mediaType->essence());
    }

    public function testEmptyHeaderSectionReturnsPartWithEmptyHeaders(): void
    {
        $body = "--boundary\r\n\r\nthe body\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertNull($parts[0]->headers->get('content-type'));
    }

    public function testEmptyHeaderSectionDoesNotThrow(): void
    {
        $body = "--boundary\r\n\r\ncontent\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
    }

    public function testCRLFInHeaderSectionNormalizedToLF(): void
    {
        $body = "--boundary\r\nX-One: alpha\r\nX-Two: beta\r\n\r\ndata\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('alpha', $parts[0]->headers->get('x-one'));
        static::assertSame('beta', $parts[0]->headers->get('x-two'));
    }

    public function testPureLFHeaderSectionParsedCorrectly(): void
    {
        $body = "--boundary\nX-One: gamma\nX-Two: delta\n\ndata\n--boundary--\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('gamma', $parts[0]->headers->get('x-one'));
        static::assertSame('delta', $parts[0]->headers->get('x-two'));
    }

    public function testMixedLineEndingsInHeaderSection(): void
    {
        $body = "--boundary\r\nX-Header: crlfval\r\n\r\nbody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('crlfval', $parts[0]->headers->get('x-header'));
    }

    public function testHeaderNameWithSpacesIsTrimmed(): void
    {
        $body = "--boundary\r\n  X-Spaced  : trimvalue\r\n\r\nBody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('trimvalue', $parts[0]->headers->get('x-spaced'));
    }

    public function testHeaderNameTrimmedWithMultipleParts(): void
    {
        $body = "--boundary\r\n X-A : val1\r\n\r\nBody1\r\n--boundary\r\n X-B : val2\r\n\r\nBody2\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('val1', $parts[0]->headers->get('x-a'));
        static::assertSame('val2', $parts[1]->headers->get('x-b'));
    }

    public function testHeaderNameTrimmedNotAffectingValue(): void
    {
        $body = "--boundary\r\n  Content-Type  :text/html\r\n\r\n<p>hi</p>\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('text/html', $parts[0]->mediaType->essence());
    }

    public function testTransferEncodingHeaderNameCaseInsensitive(): void
    {
        $encoded = Base64\encode('MbTest');
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-ENCODING: base64\r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('MbTest', $parts[0]->body->readAll());
    }

    public function testTransferEncodingHeaderAllUpperCase(): void
    {
        $encoded = Base64\encode('UPPER');
        $body = "--boundary\r\nContent-Type: text/plain\r\nCONTENT-TRANSFER-ENCODING: base64\r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('UPPER', $parts[0]->body->readAll());
    }

    public function testTransferEncodingHeaderMixedCase(): void
    {
        $encoded = Base64\encode('Mixed');
        $body = "--boundary\r\nContent-Type: text/plain\r\nCoNtEnT-tRaNsFeR-eNcOdInG: base64\r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('Mixed', $parts[0]->body->readAll());
    }

    public function testTransferEncodingValueWithSurroundingWhitespace(): void
    {
        $encoded = Base64\encode('TrimVal');
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding:   base64   \r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('TrimVal', $parts[0]->body->readAll());
    }

    public function testTransferEncodingValueWithTabAndSpace(): void
    {
        $encoded = Base64\encode('TabSpace');
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: \tbase64\t \r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('TabSpace', $parts[0]->body->readAll());
    }

    public function testTransferEncodingValueTrimmedForQuotedPrintable(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding:  quoted-printable  \r\n\r\nHello=20World\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('Hello World', $parts[0]->body->readAll());
    }

    public function testTransferEncodingValueMbLowercased(): void
    {
        $encoded = Base64\encode('MbLower');
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: BASE64\r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('MbLower', $parts[0]->body->readAll());
    }

    public function testTransferEncodingValueQuotedPrintableMixedCase(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: QUOTED-PRINTABLE\r\n\r\nHello=20World\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('Hello World', $parts[0]->body->readAll());
    }

    public function testTransferEncodingMixedCaseValue(): void
    {
        $encoded = Base64\encode('CaseMix');
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: BaSe64\r\n\r\n{$encoded}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('CaseMix', $parts[0]->body->readAll());
    }

    public function testBodyContentStartsExactlyAfterHeaders(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nPREFIX-content-SUFFIX\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        $result = $parts[0]->body->readAll();
        static::assertSame('PREFIX-content-SUFFIX', $result);
        static::assertStringStartsWith('PREFIX', $result);
        static::assertStringEndsWith('SUFFIX', $result);
    }

    public function testBodyContentNotTruncatedAtStart(): void
    {
        $content = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        $result = $parts[0]->body->readAll();
        static::assertSame($content, $result);
        static::assertSame(26, Str\Byte\length($result));
    }

    public function testBodyContentPreservedWithSpecialChars(): void
    {
        $content = '!@#$%^&*()_+-=[]{}|;:,.<>?';
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testBodyNotIncludingDelimiter(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nMyBody\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        $result = $parts[0]->body->readAll();
        static::assertSame('MyBody', $result);
        static::assertStringNotContainsString('boundary', $result);
    }

    public function testBufferConcatenationWithLargeBody(): void
    {
        $content = Str\repeat('ABCD', 5000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', spoolThreshold: 256);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        $result = $parts[0]->body->readAll();
        static::assertSame($content, $result);
        static::assertSame(20_000, Str\Byte\length($result));
    }

    public function testBufferConcatenationOrderPreserved(): void
    {
        $content = '';
        for ($i = 0; $i < 1000; $i++) {
            $content .= str_pad((string) $i, 10, '0', STR_PAD_LEFT);
        }

        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', spoolThreshold: 256);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testTailSizeWithShortDelimiter(): void
    {
        $body = "--ab\r\nContent-Type: text/plain\r\n\r\nBody\r\n--ab--\r\n";

        $parser = new Parser('ab');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('Body', $parts[0]->body->readAll());
    }

    public function testTailSizeWithLongDelimiter(): void
    {
        $longBoundary = Str\repeat('x', 70);
        $body = "--{$longBoundary}\r\nContent-Type: text/plain\r\n\r\nLongBoundaryBody\r\n--{$longBoundary}--\r\n";

        $parser = new Parser($longBoundary);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('LongBoundaryBody', $parts[0]->body->readAll());
    }

    public function testTailSizeWithMultipleParts(): void
    {
        $body = "--bd\r\nContent-Type: text/plain\r\n\r\nPart1Content\r\n--bd\r\nContent-Type: text/plain\r\n\r\nPart2Content\r\n--bd--\r\n";

        $parser = new Parser('bd');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(2, $parts);
        static::assertSame('Part1Content', $parts[0]->body->readAll());
        static::assertSame('Part2Content', $parts[1]->body->readAll());
    }

    public function testMaxPartSizeWrittenAccumulation(): void
    {
        $content = Str\repeat('A', 100) . Str\repeat('B', 100) . Str\repeat('C', 100);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 300);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testMaxPartSizeWrittenAccumulationExceedsLimit(): void
    {
        $content = Str\repeat('A', 100) . Str\repeat('B', 100) . Str\repeat('C', 101);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $parser = new Parser('boundary', maxPartSize: 300);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testMaxPartSizeAccumulatesAcrossChunks(): void
    {
        $content = Str\repeat('X', 20_000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $parser = new Parser('boundary', maxPartSize: 10_000);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testSafeLenWriteFlushesPartialBuffer(): void
    {
        $content = Str\repeat('Y', 30_000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', spoolThreshold: 256);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame(30_000, Str\Byte\length($parts[0]->body->readAll()));
    }

    public function testSafeLenWithTinyContent(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nA\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('A', $parts[0]->body->readAll());
    }

    public function testSafeLenGuardWithMaxPartSize(): void
    {
        $content = Str\repeat('Q', 15_000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 15_000, spoolThreshold: 256);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testMaxPartSizeExactByteBoundary(): void
    {
        $content = Str\repeat('W', 500);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 500);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testMaxPartSizeOneByteOverThrows(): void
    {
        $content = Str\repeat('W', 501);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $parser = new Parser('boundary', maxPartSize: 500);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testMaxPartSizeEnforcedPerPartNotGlobal(): void
    {
        $content1 = Str\repeat('A', 100);
        $content2 = Str\repeat('B', 100);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content1}\r\n--boundary\r\nContent-Type: text/plain\r\n\r\n{$content2}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 100);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(2, $parts);
        static::assertSame($content1, $parts[0]->body->readAll());
        static::assertSame($content2, $parts[1]->body->readAll());
    }

    public function testMaxPartSizeLargeBodyThrowsCorrectMessage(): void
    {
        $content = Str\repeat('A', 20_000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessageMatches('/5000.*bytes/');

        $parser = new Parser('boundary', maxPartSize: 5_000, spoolThreshold: 256);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testMaxPartSizeLargeBodyWithSmallSpoolThrows(): void
    {
        $content = Str\repeat('M', 30_000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $parser = new Parser('boundary', maxPartSize: 10_000, spoolThreshold: 64);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testMaxPartSizeMultiplePartsSecondExceedsLimit(): void
    {
        $content1 = Str\repeat('A', 50);
        $content2 = Str\repeat('B', 200);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content1}\r\n--boundary\r\nContent-Type: text/plain\r\n\r\n{$content2}\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $parser = new Parser('boundary', maxPartSize: 100);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testBodyEndChunkWrittenTracked(): void
    {
        $content = Str\repeat('Z', 99);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 99);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testBodyEndChunkAccumulatesWithStreamedBytes(): void
    {
        $content = Str\repeat('D', 20_000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxPartSize: 20_000, spoolThreshold: 256);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame(20_000, Str\Byte\length($parts[0]->body->readAll()));
    }

    public function testBodyEndChunkOverflowThrowsWhenPreviouslyWritten(): void
    {
        $content = Str\repeat('E', 20_001);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $parser = new Parser('boundary', maxPartSize: 20_000, spoolThreshold: 256);
        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testThreePartsAllBodiesCorrect(): void
    {
        $body = "--d\r\nContent-Type: text/plain\r\n\r\nFirst\r\n--d\r\nContent-Type: text/plain\r\n\r\nSecond\r\n--d\r\nContent-Type: text/plain\r\n\r\nThird\r\n--d--\r\n";

        $parser = new Parser('d');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(3, $parts);
        static::assertSame('First', $parts[0]->body->readAll());
        static::assertSame('Second', $parts[1]->body->readAll());
        static::assertSame('Third', $parts[2]->body->readAll());
    }

    public function testLargeBodyWithSmallSpoolPreservesContent(): void
    {
        $content = Str\repeat('G', 40_000);
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--boundary--\r\n";

        $parser = new Parser('boundary', spoolThreshold: 64);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame($content, $parts[0]->body->readAll());
    }

    public function testHeaderBlockEndingWithMixedLineEndingsDoesNotLeaveCarriageReturn(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: base64\r\n\naGVsbG8=\r\n--boundary--\r\n";

        $parser = new Parser('boundary', decodeTransferEncoding: true);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('hello', $parts[0]->body->readAll());
    }

    public function testPreambleSkippedBeforeBoundary(): void
    {
        $body = "This preamble should be skipped entirely\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nActual\r\n--boundary--\r\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        $result = $parts[0]->body->readAll();
        static::assertSame('Actual', $result);
        static::assertStringNotContainsString('preamble', $result);
    }

    public function testHeaderSectionAtMaxHeaderSizeIsAccepted(): void
    {
        // A 100-byte header field; the line break of the boundary line is not counted.
        $header = 'X-Pad: ' . Str\repeat('a', 93);
        $body = "--boundary\r\n" . $header . "\r\n\r\nvalue\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxHeaderSize: 100);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame(Str\repeat('a', 93), $parts[0]->headers->get('x-pad'));
        static::assertSame('value', $parts[0]->body->readAll());
    }

    public function testMaxHeaderSizeCountsLineBreaksBetweenHeaderFieldsOnly(): void
    {
        // Two header fields of 49 bytes each, joined by a 2-byte CRLF: 100 bytes. Transport padding
        // on the boundary line and the blank line are not counted.
        $headers = 'X-One: ' . Str\repeat('a', 42) . "\r\n" . 'X-Two: ' . Str\repeat('b', 42);
        $body = "--boundary  \t\r\n" . $headers . "\r\n\r\nvalue\r\n--boundary--\r\n";

        $parts = iterator_to_array(new Parser('boundary', maxHeaderSize: 100)->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame(Str\repeat('b', 42), $parts[0]->headers->get('x-two'));

        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessage('part header section exceeds maximum size of 99 bytes');

        iterator_to_array(new Parser('boundary', maxHeaderSize: 99)->parse(new IO\MemoryHandle($body)));
    }

    public function testHeaderSectionOverMaxHeaderSizeThrows(): void
    {
        $header = 'X-Pad: ' . Str\repeat('a', 94);
        $body = "--boundary\r\n" . $header . "\r\n\r\nvalue\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxHeaderSize: 100);

        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessage('part header section exceeds maximum size of 100 bytes');

        iterator_to_array($parser->parse(new IO\MemoryHandle($body)));
    }

    public function testUnterminatedHeaderSectionIsNotBufferedWhole(): void
    {
        // 4 MiB of header data that never ends: the parser must give up after the limit
        // instead of reading the whole stream into memory.
        $handle = new IO\MemoryHandle("--boundary\r\nX-Flood: " . Str\repeat('a', 4 * 1024 * 1024));
        $parser = new Parser('boundary', maxHeaderSize: 1024);

        try {
            iterator_to_array($parser->parse($handle));
            static::fail('Expected a MultiPartException.');
        } catch (MultiPartException $e) {
            static::assertSame(
                'Malformed multipart body: part header section exceeds maximum size of 1024 bytes.',
                $e->getMessage(),
            );
        }

        static::assertLessThan(64 * 1024, $handle->tell());
    }

    public function testDefaultMaxHeaderSizeIs16KiB(): void
    {
        $accepted = "--boundary\r\nX-Pad: " . Str\repeat('a', 16_384 - 7) . "\r\n\r\nok\r\n--boundary--\r\n";
        $parts = iterator_to_array(new Parser('boundary')->parse(new IO\MemoryHandle($accepted)));
        static::assertSame('ok', $parts[0]->body->readAll());

        $rejected = "--boundary\r\nX-Pad: " . Str\repeat('a', 16_384 - 6) . "\r\n\r\nok\r\n--boundary--\r\n";

        $this->expectException(MultiPartException::class);
        $this->expectExceptionMessage('part header section exceeds maximum size of 16384 bytes');

        iterator_to_array(new Parser('boundary')->parse(new IO\MemoryHandle($rejected)));
    }

    public function testZeroMaxHeaderSizeMeansUnlimited(): void
    {
        $body = "--boundary\r\nX-Pad: " . Str\repeat('a', 100_000) . "\r\n\r\nok\r\n--boundary--\r\n";

        $parser = new Parser('boundary', maxHeaderSize: 0);
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertSame('ok', $parts[0]->body->readAll());
    }

    public function testLfHeaderSectionEndsAtFirstBlankLine(): void
    {
        // LF-only part headers whose body contains a CRLF blank line further down.
        $body = "--boundary\nContent-Type: text/plain\n\nline1\r\n\r\nline2\n--boundary--\n";

        $parser = new Parser('boundary');
        $parts = iterator_to_array($parser->parse(new IO\MemoryHandle($body)));

        static::assertCount(1, $parts);
        static::assertSame('text/plain', $parts[0]->headers->get('content-type'));
        static::assertSame("line1\r\n\r\nline2", $parts[0]->body->readAll());
    }
}
