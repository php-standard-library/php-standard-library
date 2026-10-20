<?php

declare(strict_types=1);

namespace Psl\MIME\Tests\Unit\Part;

use PHPUnit\Framework\TestCase;
use Psl\IO;
use Psl\MIME\Part\Text;
use Psl\MIME\TransferEncoding;

final class TextPartTest extends TestCase
{
    public function testDefaultPlainText(): void
    {
        $part = new Text(new IO\MemoryHandle('Hello, World!'));

        static::assertSame('text/plain; charset=utf-8', $part->mediaType->toString());
    }

    public function testHtmlSubtype(): void
    {
        $part = new Text(new IO\MemoryHandle('<b>bold</b>'), 'html');

        static::assertSame('text/html; charset=utf-8', $part->mediaType->toString());
    }

    public function testCustomCharset(): void
    {
        $part = new Text(new IO\MemoryHandle('data'), 'plain', 'iso-8859-1');

        static::assertSame('text/plain; charset=iso-8859-1', $part->mediaType->toString());
    }

    public function testDefaultEncodingIsQuotedPrintable(): void
    {
        $part = new Text(new IO\MemoryHandle('Simple ASCII text'));

        static::assertSame(TransferEncoding::QuotedPrintable, $part->encoding);
    }

    public function testQuotedPrintableBodyKeepsTrailingLineBreaks(): void
    {
        $part = new Text(new IO\MemoryHandle("caf\u{e9}\nau lait\n\n"));

        static::assertSame("caf=C3=A9\r\nau lait\r\n\r\n", $part->body()->readAll());
    }

    public function testExplicitEncoding(): void
    {
        $part = new Text(new IO\MemoryHandle('Hello'), encoding: TransferEncoding::Base64);

        static::assertSame(TransferEncoding::Base64, $part->encoding);
    }

    public function testEncodedBodyBase64(): void
    {
        $part = new Text(new IO\MemoryHandle('Hello'), encoding: TransferEncoding::Base64);

        static::assertSame('SGVsbG8=', $part->body()->readAll());
    }

    public function testEncodedBodySevenBitPassthrough(): void
    {
        $part = new Text(new IO\MemoryHandle('Hello'), encoding: TransferEncoding::SevenBit);

        static::assertSame('Hello', $part->body()->readAll());
    }

    public function testToPartHeaders(): void
    {
        $part = new Text(new IO\MemoryHandle('Hello'), encoding: TransferEncoding::SevenBit);

        $headerMap = [];
        foreach ($part->headers->pairs() as [$name, $value]) {
            $headerMap[$name] = $value;
        }

        static::assertSame('text/plain; charset=utf-8', $headerMap['Content-Type']);
        static::assertSame('7bit', $headerMap['Content-Transfer-Encoding']);
    }

    public function testToPartBody(): void
    {
        $part = new Text(new IO\MemoryHandle('Hello'), encoding: TransferEncoding::Base64);

        static::assertSame('SGVsbG8=', $part->body()->readAll());
    }

    public function testEightBitEncodingPassthrough(): void
    {
        $content = "Hello with high bytes: \xC3\xA9";
        $part = new Text(new IO\MemoryHandle($content), encoding: TransferEncoding::EightBit);

        static::assertSame($content, $part->body()->readAll());
        static::assertSame(TransferEncoding::EightBit, $part->encoding);
    }

    public function testBinaryEncodingPassthrough(): void
    {
        $content = "Binary \x00\x01\x02 data";
        $part = new Text(new IO\MemoryHandle($content), encoding: TransferEncoding::Binary);

        static::assertSame($content, $part->body()->readAll());
        static::assertSame(TransferEncoding::Binary, $part->encoding);
    }

    public function testEightBitEncodingHeader(): void
    {
        $part = new Text(new IO\MemoryHandle('Hello'), encoding: TransferEncoding::EightBit);

        $headerMap = [];
        foreach ($part->headers->pairs() as [$name, $value]) {
            $headerMap[$name] = $value;
        }

        static::assertSame('8bit', $headerMap['Content-Transfer-Encoding']);
    }
}
