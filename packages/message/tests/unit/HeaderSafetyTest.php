<?php

declare(strict_types=1);

namespace Psl\Message\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Message\Address\Group;
use Psl\Message\Address\Mailbox;
use Psl\Message\Exception\InvalidArgumentException;
use Psl\Message\Exception\InvalidMailboxException;
use Psl\Message\Exception\ParsingException;
use Psl\Message\Message;
use Psl\Message\MessageId;
use Psl\MIME;

final class HeaderSafetyTest extends TestCase
{
    #[DataProvider('invalidMailboxes')]
    public function testRejectsInvalidMailboxParts(string $local, string $domain): void
    {
        $this->expectException(InvalidMailboxException::class);

        new Mailbox($local, $domain);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidMailboxes(): iterable
    {
        yield 'leading dot' => ['.user', 'example.com'];
        yield 'trailing dot' => ['user.', 'example.com'];
        yield 'consecutive dots' => ['first..last', 'example.com'];
        yield 'unquoted space' => ['first last', 'example.com'];
        yield 'unquoted at' => ['user@other', 'example.com'];
        yield 'recipient injection' => ['user,other', 'example.com'];
        yield 'local CRLF' => ["user\r\nBcc: victim", 'example.com'];
        yield 'local NUL' => ["user\0", 'example.com'];
        yield 'domain CRLF' => ['user', "example.com\r\nBcc: victim@example.com"];
        yield 'domain NUL' => ['user', "example.com\0"];
        yield 'domain space' => ['user', 'example .com'];
        yield 'domain dot' => ['user', 'example..com'];
        yield 'unclosed quote' => ['"user', 'example.com'];
        yield 'broken literal' => ['user', '[::1'];
        yield 'literal escape' => ['user', '[bad\\domain]'];
    }

    #[DataProvider('validMailboxes')]
    public function testAcceptsMailboxSyntax(string $local, string $domain): void
    {
        static::assertSame($local . '@' . $domain, new Mailbox($local, $domain)->address);
    }

    /** @return iterable<array{string, string}> */
    public static function validMailboxes(): iterable
    {
        yield ['first.last+tag', 'example.com'];
        yield ['"first last@home"', 'example.com'];
        yield ['"quote\\"and\\\\slash"', 'example.com'];
        yield ['""', 'example.com'];
        yield ['user', '[IPv6:::1]'];
        yield ['user', '[127.0.0.1]'];
        yield ['δοκιμή', 'bücher.example'];
    }

    #[DataProvider('headerDelimiters')]
    public function testRejectsMailboxDisplayNameDelimiters(string $delimiter): void
    {
        $this->expectException(InvalidMailboxException::class);

        new Mailbox('user', 'example.com', 'Name' . $delimiter);
    }

    #[DataProvider('headerDelimiters')]
    public function testRejectsMailboxCommentDelimiters(string $delimiter): void
    {
        $this->expectException(InvalidMailboxException::class);

        new Mailbox('user', 'example.com', null, 'Comment' . $delimiter);
    }

    #[DataProvider('headerDelimiters')]
    public function testRejectsGroupDelimiters(string $delimiter): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Group('Group' . $delimiter);
    }

    #[DataProvider('headerDelimiters')]
    public function testRejectsSubjectDelimiters(string $delimiter): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Message()->withSubject('Subject' . $delimiter);
    }

    #[DataProvider('headerDelimiters')]
    public function testRejectsCustomHeaderDelimiters(string $delimiter): void
    {
        $this->expectException(MIME\Exception\InvalidArgumentException::class);

        new Message()->withHeader('X-Test', 'Value' . $delimiter);
    }

    #[DataProvider('headerDelimiters')]
    public function testRejectsMessageIdDelimiters(string $delimiter): void
    {
        $this->expectException(ParsingException::class);

        new MessageId('id@example.com' . $delimiter);
    }

    /** @return iterable<array{string}> */
    public static function headerDelimiters(): iterable
    {
        yield ["\r"];
        yield ["\n"];
        yield ["\0"];
    }

    public function testEscapesDisplayNameAndComment(): void
    {
        $mailbox = new Mailbox('user', 'example.com', 'Name\\", victim@example.com', 'Text) \\ (more');
        $wire = $mailbox->toString();

        static::assertSame('"Name\\\\\\", victim@example.com" <user@example.com> (Text\\) \\\\ \\(more)', $wire);
        $parsed = Mailbox::parse($wire);
        static::assertSame($mailbox->displayName, $parsed->displayName);
        static::assertSame($mailbox->comment, $parsed->comment);
    }
}
