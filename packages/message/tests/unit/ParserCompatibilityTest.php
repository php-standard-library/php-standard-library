<?php

declare(strict_types=1);

namespace Psl\Message\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Message;
use Psl\Message\Address\AddressList;
use Psl\Message\Address\Mailbox;
use Psl\Message\Exception\InvalidMailboxException;
use Psl\Message\Exception\ParsingException;

final class ParserCompatibilityTest extends TestCase
{
    #[DataProvider('receivedHeaders')]
    public function testParsesReceivedHeaders(string $prefix): void
    {
        $message = Message\parse($prefix . "Subject \t: hello\r\n\r\nbody");

        static::assertSame([['Subject', 'hello']], $message->headers->pairs());
        static::assertSame('hello', $message->subject);
        static::assertSame('body', $message->content->body()->readAll());
    }

    /** @return iterable<string, array{string}> */
    public static function receivedHeaders(): iterable
    {
        yield 'obsolete whitespace' => [''];
        yield 'mbox envelope' => ["From MAILER-DAEMON Tue Oct 20 12:08:34 2026\r\n"];
        yield 'space in field name' => ["Bad Name: ignored\r\n"];
        yield '8-bit field name' => ["\xff-Name: ignored\r\n"];
        yield 'NUL in field value' => ["X-Bad: before\0after\r\n"];
        yield 'bare CR in field value' => ["X-Bad: before\rafter\r\n"];
        yield 'NUL in folded field value' => ["X-Bad: before\r\n after\0end\r\n"];
    }

    public function testNoUsableHeadersThrowsParsingException(): void
    {
        $this->expectException(ParsingException::class);

        Message\parse("Bad Name: ignored\r\n\r\nbody");
    }

    public function testParsedMessageKeepsValidRecipients(): void
    {
        $message = Message\parse(
            "To: alice@example.com, invalid, bob@example.com\r\n"
            . "Cc: Team: carol@example.com, broken, dave@example.com;\r\n\r\nbody",
        );

        static::assertSame('alice@example.com, bob@example.com', $message->to?->toString());
        static::assertSame('Team: carol@example.com, dave@example.com;', $message->cc?->toString());
    }

    public function testStrictAddressListStillRejectsInvalidEntries(): void
    {
        $this->expectException(ParsingException::class);

        AddressList::parse('alice@example.com, invalid, bob@example.com');
    }

    #[DataProvider('legacyMailboxes')]
    public function testNormalizesLegacyMailboxOnParse(string $input, string $expected): void
    {
        $mailbox = Mailbox::parse($input);

        static::assertSame($expected, $mailbox->address);
        static::assertSame($expected, Mailbox::parse($mailbox->toString())->address);
    }

    /** @return iterable<string, array{string, string}> */
    public static function legacyMailboxes(): iterable
    {
        yield 'consecutive dots' => ['first..last@docomo.ne.jp', '"first..last"@docomo.ne.jp'];
        yield 'trailing dot' => ['user.@ezweb.ne.jp', '"user."@ezweb.ne.jp'];
        yield 'leading dot' => ['.user@example.com', '".user"@example.com'];
        yield 'obsolete quoted word' => ['"john".doe@example.com', '"john.doe"@example.com'];
        yield 'obsolete escaped quote' => ['"jo\\"hn".doe@example.com', '"jo\\"hn.doe"@example.com'];
        yield 'final domain dot' => ['user@example.com.', 'user@example.com'];
    }

    public function testDirectConstructionRejectsFinalDomainDot(): void
    {
        $this->expectException(InvalidMailboxException::class);

        new Mailbox('user', 'example.com.');
    }
}
