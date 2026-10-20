<?php

declare(strict_types=1);

namespace Psl\Message\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psl\Message;
use Psl\Message\Exception\ParsingException;

final class ParseTest extends TestCase
{
    #[Test]
    public function parseSimpleMessage(): void
    {
        $raw = "From: alice@example.com\r\nTo: bob@example.com\r\nSubject: Hello\r\n\r\nHello, Bob!";

        $message = Message\parse($raw);

        self::assertNotNull($message->from);
        self::assertSame('alice@example.com', $message->from->mailboxes()[0]->address);
        self::assertNotNull($message->to);
        self::assertSame('bob@example.com', $message->to->mailboxes()[0]->address);
        self::assertSame('Hello', $message->subject);
        self::assertSame('Hello, Bob!', $message->content->body()->readAll());
    }

    #[Test]
    public function parseFoldedHeaders(): void
    {
        $raw = "Subject: This is a very long\r\n subject that is folded\r\n\r\nbody";

        $message = Message\parse($raw);

        self::assertSame('This is a very long subject that is folded', $message->subject);
    }

    #[Test]
    public function parseWithTabContinuation(): void
    {
        $raw = "Subject: Line one\r\n\tline two\r\n\r\nbody";

        $message = Message\parse($raw);

        self::assertSame('Line one line two', $message->subject);
    }

    #[Test]
    public function parseToleratesLfLineEndings(): void
    {
        $raw = "From: alice@example.com\nTo: bob@example.com\n\nHello!";

        $message = Message\parse($raw);

        self::assertNotNull($message->from);
        self::assertSame('alice@example.com', $message->from->mailboxes()[0]->address);
        self::assertSame('Hello!', $message->content->body()->readAll());
    }

    #[Test]
    public function parseEmptyBody(): void
    {
        $raw = "From: alice@example.com\r\n\r\n";

        $message = Message\parse($raw);

        self::assertSame('', $message->content->body()->readAll());
    }

    #[Test]
    public function parseMalformedMessageThrows(): void
    {
        $this->expectException(ParsingException::class);

        Message\parse('no separator here');
    }

    #[Test]
    public function parseMultipleHeaders(): void
    {
        $raw = "Received: from a\r\nReceived: from b\r\n\r\nbody";

        $message = Message\parse($raw);

        $received = $message->headers->all('Received');
        self::assertCount(2, $received);
    }

    #[Test]
    #[DataProvider('provideNonWspControlCharacters')]
    public function parseOnlyStripsWspAroundHeaderValues(string $char): void
    {
        $raw = "Subject: test\r\nX-Custom: \t{$char}value{$char} \t\r\nX-Folded: first\r\n \t{$char}second{$char}\r\n\r\nbody";

        $message = Message\parse($raw);

        if ($char === "\0") {
            self::assertNull($message->headers->get('X-Custom'));
            self::assertNull($message->headers->get('X-Folded'));

            return;
        }

        self::assertSame("{$char}value{$char}", $message->headers->get('X-Custom'));
        self::assertSame("first {$char}second{$char}", $message->headers->get('X-Folded'));
    }

    /**
     * Header values are separated and unfolded by WSP (SP / HTAB) only, per RFC 5322.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideNonWspControlCharacters(): iterable
    {
        yield 'form feed' => ["\x0C"];
        yield 'vertical tab' => ["\x0B"];
        yield 'NUL byte' => ["\0"];
    }

    #[Test]
    public function parsePreservesBodyWithBlankLines(): void
    {
        $raw = "Subject: test\r\n\r\nline1\r\n\r\nline2";

        $message = Message\parse($raw);

        self::assertSame("line1\r\n\r\nline2", $message->content->body()->readAll());
    }

    #[Test]
    public function parseSkipsHeaderLineWithNoColon(): void
    {
        $raw = "Subject: test\r\nno-colon-here\r\n\r\nbody";

        $message = Message\parse($raw);

        self::assertSame('test', $message->subject);
        self::assertSame('body', $message->content->body()->readAll());
    }

    #[Test]
    public function parseSkipsEmptyHeaderLines(): void
    {
        $raw = "Subject: test\r\n\r\nbody";

        $message = Message\parse($raw);

        self::assertSame('test', $message->subject);
    }

    #[Test]
    public function parseSkipsHeaderLineStartingWithColon(): void
    {
        $raw = ":bad-header\r\nSubject: ok\r\n\r\nbody";

        $message = Message\parse($raw);

        self::assertSame('ok', $message->subject);
    }

    #[Test]
    public function parseMultipartAlternative(): void
    {
        $boundary = 'alt-boundary';
        $raw =
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/plain\r\n\r\n"
            . "Plain text\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html\r\n\r\n"
            . "<p>HTML</p>\r\n"
            . "--{$boundary}--\r\n";

        $message = Message\parse($raw);

        self::assertSame('multipart/alternative', $message->content->mediaType->essence());
    }

    #[Test]
    public function parseMultipartMixed(): void
    {
        $boundary = 'mixed-boundary';
        $raw =
            "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/plain\r\n\r\n"
            . "Body text\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: application/pdf\r\n"
            . "Content-Disposition: attachment; filename=\"doc.pdf\"\r\n\r\n"
            . "pdf-data\r\n"
            . "--{$boundary}--\r\n";

        $message = Message\parse($raw);

        self::assertSame('multipart/mixed', $message->content->mediaType->essence());
    }

    #[Test]
    public function parseMultipartRelated(): void
    {
        $boundary = 'related-boundary';
        $raw =
            "Content-Type: multipart/related; boundary=\"{$boundary}\"\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html\r\n\r\n"
            . "<p>HTML with image</p>\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: image/png\r\n"
            . "Content-ID: <img@example.com>\r\n\r\n"
            . "image-data\r\n"
            . "--{$boundary}--\r\n";

        $message = Message\parse($raw);

        self::assertSame('multipart/related', $message->content->mediaType->essence());
    }

    #[Test]
    public function parseSinglePartBody(): void
    {
        $raw = "Content-Type: text/plain\r\n\r\nPlain text";

        $message = Message\parse($raw);

        self::assertSame('text/plain', $message->content->mediaType->essence());
        self::assertSame('Plain text', $message->content->body()->readAll());
    }

    #[Test]
    public function parseNoContentTypeDefaultsToTextPlain(): void
    {
        $raw = "Subject: test\r\n\r\nPlain body";

        $message = Message\parse($raw);

        self::assertSame('text/plain', $message->content->mediaType->essence());
        self::assertSame('Plain body', $message->content->body()->readAll());
    }

    #[Test]
    public function parseSinglePartHtml(): void
    {
        $raw = "Content-Type: text/html\r\n\r\n<p>Hello</p>";

        $message = Message\parse($raw);

        self::assertSame('text/html', $message->content->mediaType->essence());
        self::assertSame('<p>Hello</p>', $message->content->body()->readAll());
    }

    #[Test]
    public function parseSinglePartWithCharset(): void
    {
        $raw = "Content-Type: text/plain; charset=utf-8\r\n\r\nHello \xC3\x84";

        $message = Message\parse($raw);

        self::assertSame("Hello \xC3\x84", $message->content->body()->readAll());
    }

    #[Test]
    public function parseSinglePartWithCharsetParam(): void
    {
        $latin1Bytes = "\xC4\xD6\xDC";
        $raw = "Content-Type: text/plain; charset=iso-8859-1\r\n\r\n{$latin1Bytes}";

        $message = Message\parse($raw);

        self::assertSame("\xC4\xD6\xDC", $message->content->body()->readAll());
    }
}
