<?php

declare(strict_types=1);

namespace Psl\Message\Tests\Unit\Address;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psl\Message\Address\Mailbox;
use Psl\Message\Exception\InvalidMailboxException;
use Psl\Message\Exception\ParsingException;

final class MailboxTest extends TestCase
{
    #[Test]
    public function constructWithoutDisplayName(): void
    {
        $mailbox = new Mailbox('user', 'example.com');

        self::assertSame('user', $mailbox->localPart);
        self::assertSame('example.com', $mailbox->domain);
        self::assertNull($mailbox->displayName);
        self::assertSame('user@example.com', $mailbox->address);
    }

    #[Test]
    public function constructWithDisplayName(): void
    {
        $mailbox = new Mailbox('user', 'example.com', 'John Doe');

        self::assertSame('John Doe', $mailbox->displayName);
        self::assertSame('user@example.com', $mailbox->address);
    }

    #[Test]
    public function constructEmptyLocalPartThrows(): void
    {
        $this->expectException(InvalidMailboxException::class);

        new Mailbox('', 'example.com');
    }

    #[Test]
    public function constructEmptyDomainThrows(): void
    {
        $this->expectException(InvalidMailboxException::class);

        new Mailbox('user', '');
    }

    #[Test]
    public function parseBareAddress(): void
    {
        $mailbox = Mailbox::parse('user@example.com');

        self::assertSame('user', $mailbox->localPart);
        self::assertSame('example.com', $mailbox->domain);
        self::assertNull($mailbox->displayName);
    }

    #[Test]
    public function parseAngleBracketAddress(): void
    {
        $mailbox = Mailbox::parse('<user@example.com>');

        self::assertSame('user', $mailbox->localPart);
        self::assertSame('example.com', $mailbox->domain);
        self::assertNull($mailbox->displayName);
    }

    #[Test]
    public function parseWithQuotedDisplayName(): void
    {
        $mailbox = Mailbox::parse('"John Doe" <user@example.com>');

        self::assertSame('user', $mailbox->localPart);
        self::assertSame('example.com', $mailbox->domain);
        self::assertSame('John Doe', $mailbox->displayName);
    }

    #[Test]
    public function parseWithUnquotedDisplayName(): void
    {
        $mailbox = Mailbox::parse('John Doe <user@example.com>');

        self::assertSame('John Doe', $mailbox->displayName);
        self::assertSame('user@example.com', $mailbox->address);
    }

    #[Test]
    public function parseWithEncodedWordDisplayName(): void
    {
        $mailbox = Mailbox::parse('=?utf-8?B?SsO2aG4=?= <user@example.com>');

        self::assertSame("J\u{00F6}hn", $mailbox->displayName);
        self::assertSame('user@example.com', $mailbox->address);
    }

    #[Test]
    public function parseTrimsWhitespace(): void
    {
        $mailbox = Mailbox::parse('  user@example.com  ');

        self::assertSame('user@example.com', $mailbox->address);
    }

    #[Test]
    public function parseEmptyThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('');
    }

    #[Test]
    public function parseNoAtSignThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('invalid');
    }

    #[Test]
    public function parseMissingClosingAngleBracketThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('<user@example.com');
    }

    #[Test]
    public function parseAtSignAtStartThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('@example.com');
    }

    #[Test]
    public function parseAtSignAtEndThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('user@');
    }

    #[Test]
    public function toStringWithoutDisplayName(): void
    {
        $mailbox = new Mailbox('user', 'example.com');

        self::assertSame('user@example.com', $mailbox->toString());
        self::assertSame('user@example.com', (string) $mailbox);
    }

    #[Test]
    public function toStringWithDisplayName(): void
    {
        $mailbox = new Mailbox('user', 'example.com', 'John Doe');

        self::assertSame('John Doe <user@example.com>', $mailbox->toString());
    }

    #[Test]
    public function toStringWithSpecialCharDisplayName(): void
    {
        $mailbox = new Mailbox('user', 'example.com', 'Doe, John');

        self::assertSame('"Doe, John" <user@example.com>', $mailbox->toString());
    }

    #[Test]
    public function toStringWithNonAsciiDisplayName(): void
    {
        $mailbox = new Mailbox('user', 'example.com', "J\u{00F6}hn");

        $result = $mailbox->toString();
        self::assertStringContainsString('=?', $result);
        self::assertStringContainsString('<user@example.com>', $result);
    }

    #[Test]
    public function toStringWithEmptyDisplayName(): void
    {
        $mailbox = new Mailbox('user', 'example.com', '');

        self::assertSame('user@example.com', $mailbox->toString());
    }

    #[Test]
    public function toStringWithQuotesInDisplayName(): void
    {
        $mailbox = new Mailbox('user', 'example.com', 'O\'Brien, "Jim"');

        $result = $mailbox->toString();
        self::assertStringContainsString('<user@example.com>', $result);
    }

    #[Test]
    public function parseRoundTrip(): void
    {
        $original = new Mailbox('user', 'example.com', 'John Doe');
        $parsed = Mailbox::parse($original->toString());

        self::assertSame($original->address, $parsed->address);
        self::assertSame($original->displayName, $parsed->displayName);
    }

    #[Test]
    public function parseWithSubaddress(): void
    {
        $mailbox = Mailbox::parse('user+tag@example.com');

        self::assertSame('user+tag', $mailbox->localPart);
        self::assertSame('example.com', $mailbox->domain);
    }

    #[Test]
    public function constructWithComment(): void
    {
        $mailbox = new Mailbox('user', 'example.com', null, 'a]comment');

        self::assertSame('a]comment', $mailbox->comment);
        self::assertSame('user@example.com', $mailbox->address);
    }

    #[Test]
    public function parseCommentAfterAddress(): void
    {
        $mailbox = Mailbox::parse('john@example.com (John Smith)');

        self::assertSame('john', $mailbox->localPart);
        self::assertSame('example.com', $mailbox->domain);
        self::assertSame('John Smith', $mailbox->comment);
        self::assertNull($mailbox->displayName);
    }

    #[Test]
    public function parseCommentInAddrSpec(): void
    {
        $mailbox = Mailbox::parse('john@(comment)example.com');

        self::assertSame('john', $mailbox->localPart);
        self::assertSame('example.com', $mailbox->domain);
        self::assertSame('comment', $mailbox->comment);
    }

    #[Test]
    public function parseCommentBeforeAngleBracket(): void
    {
        $mailbox = Mailbox::parse('"John" (comment) <john@example.com>');

        self::assertSame('John', $mailbox->displayName);
        self::assertSame('john@example.com', $mailbox->address);
        self::assertSame('comment', $mailbox->comment);
    }

    #[Test]
    public function parseNestedComment(): void
    {
        $mailbox = Mailbox::parse('john@example.com (outer (inner) text)');

        self::assertSame('john@example.com', $mailbox->address);
        self::assertSame('outer (inner) text', $mailbox->comment);
    }

    #[Test]
    public function parseEscapedParenInComment(): void
    {
        $mailbox = Mailbox::parse('john@example.com (has \) paren)');

        self::assertSame('john@example.com', $mailbox->address);
        self::assertSame('has ) paren', $mailbox->comment);
    }

    #[Test]
    public function parseNoCommentReturnsNull(): void
    {
        $mailbox = Mailbox::parse('john@example.com');

        self::assertNull($mailbox->comment);
    }

    #[Test]
    public function toStringWithComment(): void
    {
        $mailbox = new Mailbox('john', 'example.com', null, 'a comment');

        self::assertSame('john@example.com (a comment)', $mailbox->toString());
    }

    #[Test]
    public function toStringWithDisplayNameAndComment(): void
    {
        $mailbox = new Mailbox('john', 'example.com', 'John Doe', 'a comment');

        self::assertSame('John Doe <john@example.com> (a comment)', $mailbox->toString());
    }

    #[Test]
    public function roundTripWithComment(): void
    {
        $original = new Mailbox('john', 'example.com', 'John Doe', 'a comment');
        $parsed = Mailbox::parse($original->toString());

        self::assertSame($original->address, $parsed->address);
        self::assertSame($original->displayName, $parsed->displayName);
        self::assertSame($original->comment, $parsed->comment);
    }

    #[Test]
    public function parseAngleBracketWithoutAtThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('<no-at-sign>');
    }

    #[Test]
    public function parseEmptyAngleBracketThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('<>');
    }

    #[Test]
    public function parseOnlyCommentThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('(just a comment)');
    }

    #[Test]
    public function parseEscapedCharOutsideComment(): void
    {
        $mailbox = Mailbox::parse('john@example.com');

        self::assertSame('john@example.com', $mailbox->address);
    }

    #[Test]
    public function parseMultipleAtSignsUsesLast(): void
    {
        $mailbox = Mailbox::parse('"user@host"@example.com');

        self::assertSame('"user@host"', $mailbox->localPart);
        self::assertSame('example.com', $mailbox->domain);
    }

    #[Test]
    public function parseWhitespaceOnlyThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('   ');
    }

    #[Test]
    public function parseEmptyStringThrowsParsingException(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('');
    }

    #[Test]
    public function parseCommentOnlyThrowsAfterTrim(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse(' (just a comment) ');
    }

    #[Test]
    public function parseOnlyCommentResultsInException(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('(comment)');
    }

    #[Test]
    public function toStringEscapesQuotesInDisplayName(): void
    {
        $mailbox = new Mailbox('user', 'example.com', 'John "JD" Doe');

        $result = $mailbox->toString();

        static::assertStringContainsString('John \\"JD\\" Doe', $result);
        static::assertStringContainsString('<user@example.com>', $result);
    }

    #[Test]
    public function parseBackslashEscapeInCommentAndOutside(): void
    {
        $mailbox = Mailbox::parse('john@example.com (has \\) paren)');

        static::assertSame('john@example.com', $mailbox->address);
        static::assertSame('has ) paren', $mailbox->comment);
    }

    #[Test]
    public function parseEscapedCharOutsideCommentPreserved(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('john\\@extra@example.com');
    }

    #[Test]
    public function parseQuotedStringInsideCommentNotTreatedAsQuote(): void
    {
        $mailbox = Mailbox::parse('john@example.com (has "quote" inside)');

        static::assertSame('john@example.com', $mailbox->address);
        static::assertSame('has "quote" inside', $mailbox->comment);
    }

    #[Test]
    public function parseQuotedDisplayNameWithCommentAfter(): void
    {
        $mailbox = Mailbox::parse('"Doe, John" <john@example.com> (a comment)');

        static::assertSame('Doe, John', $mailbox->displayName);
        static::assertSame('john@example.com', $mailbox->address);
        static::assertSame('a comment', $mailbox->comment);
    }

    #[Test]
    public function parseUnmatchedClosingParenDoesNotCrash(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('john)@example.com');
    }

    #[Test]
    public function parseMultipleCommentsKeepsFirst(): void
    {
        $mailbox = Mailbox::parse('john@example.com (first) (second)');

        static::assertSame('john@example.com', $mailbox->address);
        static::assertSame('first', $mailbox->comment);
    }

    #[Test]
    public function parseDisplayNameStartsWithQuoteButNotEnds(): void
    {
        $mailbox = Mailbox::parse('"John <john@example.com>');

        static::assertSame('john@example.com', $mailbox->address);
    }

    #[Test]
    public function parseEmptyQuotedDisplayName(): void
    {
        $mailbox = Mailbox::parse('"" <john@example.com>');

        static::assertSame('john@example.com', $mailbox->address);
        static::assertSame('', $mailbox->displayName);
    }

    #[Test]
    public function parseQuotedDisplayNameUnescapesQuotes(): void
    {
        $mailbox = Mailbox::parse('"O\\"Brien" <john@example.com>');

        static::assertSame('O"Brien', $mailbox->displayName);
        static::assertSame('john@example.com', $mailbox->address);
    }

    #[Test]
    public function parseAddrSpecWithWhitespace(): void
    {
        $mailbox = Mailbox::parse('  john@example.com  ');

        static::assertSame('john', $mailbox->localPart);
        static::assertSame('example.com', $mailbox->domain);
    }

    #[Test]
    public function parseAtSignAtPosition0Throws(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('@example.com');
    }

    #[Test]
    public function parseAtSignAtLastPositionThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('user@');
    }

    #[Test]
    public function parseNoAtSignThrowsWithLogicalOr(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('natsign');
    }

    #[Test]
    public function parseMinimalValidAddress(): void
    {
        $mailbox = Mailbox::parse('a@b');

        static::assertSame('a', $mailbox->localPart);
        static::assertSame('b', $mailbox->domain);
    }

    #[Test]
    public function parseInputWithLeadingAndTrailingWhitespace(): void
    {
        $mailbox = Mailbox::parse("  \t user@example.com \t ");

        static::assertSame('user', $mailbox->localPart);
        static::assertSame('example.com', $mailbox->domain);
    }

    #[Test]
    public function parseWhitespaceOnlyInputThrowsViaTrim(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse("\t  \t");
    }

    #[Test]
    public function parseCommentOnlyLeavesEmptyAfterSecondTrim(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('  (only comment)  ');
    }

    #[Test]
    public function parseEscapedCharInCommentPreserved(): void
    {
        $mailbox = Mailbox::parse('user@example.com (has \\x inside)');

        static::assertSame('user@example.com', $mailbox->address);
        static::assertSame('has x inside', $mailbox->comment);
    }

    #[Test]
    public function parseEscapedCharOutsideCommentPreservesBackslash(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse("john\\@@example.com");
    }

    #[Test]
    public function parseQuotedStringWithCommentParenInside(): void
    {
        $mailbox = Mailbox::parse('"user(name)" <test@example.com>');

        static::assertSame('user(name)', $mailbox->displayName);
        static::assertSame('test@example.com', $mailbox->address);
    }

    #[Test]
    public function parseDisplayNameStartingWithQuoteNoEndQuote(): void
    {
        $mailbox = Mailbox::parse('"start <user@example.com>');

        static::assertSame('user@example.com', $mailbox->address);
    }

    #[Test]
    public function parseDisplayNameEndingWithQuote(): void
    {
        $mailbox = Mailbox::parse('end" <user@example.com>');

        static::assertSame('user@example.com', $mailbox->address);
    }

    #[Test]
    public function parseAddrSpecWithSurroundingWhitespace(): void
    {
        $mailbox = Mailbox::parse('<  user@example.com  >');

        static::assertSame('user', $mailbox->localPart);
        static::assertSame('example.com', $mailbox->domain);
    }

    #[Test]
    public function parseTwoCharAddress(): void
    {
        $mailbox = Mailbox::parse('a@b');

        static::assertSame('a', $mailbox->localPart);
        static::assertSame('b', $mailbox->domain);
    }

    #[Test]
    public function parseAtPositionZeroInAddrSpecThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('<@domain>');
    }

    #[Test]
    public function parseAtLastPositionInAddrSpecThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('<user@>');
    }

    #[Test]
    public function parseLeadingWhitespaceTrimsBeforeEmptyCheck(): void
    {
        $mailbox = Mailbox::parse('  user@example.com  ');

        static::assertSame('user', $mailbox->localPart);
        static::assertSame('example.com', $mailbox->domain);
    }

    #[Test]
    public function parseTabWhitespaceTrimsBeforeEmptyCheck(): void
    {
        $mailbox = Mailbox::parse("\tuser@example.com\t");

        static::assertSame('user', $mailbox->localPart);
        static::assertSame('example.com', $mailbox->domain);
    }

    #[Test]
    public function parseNewlineWhitespaceOnlyThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse("\n\t");
    }

    #[Test]
    public function parseTrimsFoldingWhitespace(): void
    {
        $mailbox = Mailbox::parse("\r\n Alice\r\n <\r\n alice@example.com\r\n >\r\n");

        static::assertSame('Alice', $mailbox->displayName);
        static::assertSame('alice@example.com', $mailbox->address);
    }

    #[Test]
    #[DataProvider('provideNonWhitespaceControlCharacters')]
    public function parseDoesNotTrimControlCharacters(string $char): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse("{$char}Alice{$char} <{$char}alice@example.com{$char}>");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonWhitespaceControlCharacters(): iterable
    {
        yield 'form feed' => ["\x0C"];
        yield 'vertical tab' => ["\x0B"];
        yield 'NUL byte' => ["\0"];
    }

    #[Test]
    public function parseEmptyStringThrowsActualException(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('');
    }

    #[Test]
    public function parseWhitespaceOnlyThrowsActualException(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('   ');
    }

    #[Test]
    public function parseTabOnlyThrowsActualException(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse("\t\t");
    }

    #[Test]
    public function parseSecondTrimAfterCommentStripThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('(comment only)');
    }

    #[Test]
    public function parseSecondTrimAfterCommentStripWithSpaces(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('  (comment)  ');
    }

    #[Test]
    public function parseCommentWithSpacesAroundItThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse(' (only a comment) ');
    }

    #[Test]
    public function parseEscapeAtLastCharInComment(): void
    {
        $mailbox = Mailbox::parse('user@example.com (test\\)');

        static::assertSame('user@example.com', $mailbox->address);
    }

    #[Test]
    public function parseBackslashEscapeAtSecondToLastInComment(): void
    {
        $mailbox = Mailbox::parse('user@example.com (\\x)');

        static::assertSame('user@example.com', $mailbox->address);
        static::assertSame('x', $mailbox->comment);
    }

    #[Test]
    public function parseBackslashAtEndOfCommentInput(): void
    {
        $mailbox = Mailbox::parse('user@example.com (end\\)');

        static::assertSame('user@example.com', $mailbox->address);
    }

    #[Test]
    public function parseBackslashEscapeAtEndOfStringOutsideComment(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('user\\@@example.com');
    }

    #[Test]
    public function parseDisplayNameQuotedBothEnds(): void
    {
        $mailbox = Mailbox::parse('"John Doe" <user@example.com>');

        static::assertSame('John Doe', $mailbox->displayName);
        static::assertSame('user@example.com', $mailbox->address);
    }

    #[Test]
    public function parseDisplayNameStartsWithQuoteOnly(): void
    {
        $mailbox = Mailbox::parse('"NotClosed <user@example.com>');

        static::assertSame('user@example.com', $mailbox->address);
        static::assertNotSame('NotClosed', $mailbox->displayName);
    }

    #[Test]
    public function parseDisplayNameEndsWithQuoteOnly(): void
    {
        $mailbox = Mailbox::parse('NotOpened" <user@example.com>');

        static::assertSame('user@example.com', $mailbox->address);
    }

    #[Test]
    public function parseDisplayNameSingleQuoteChar(): void
    {
        $mailbox = Mailbox::parse('" <user@example.com>');

        static::assertSame('user@example.com', $mailbox->address);
    }

    #[Test]
    public function parseAtPosition0InBareAddressThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('@domain.com');
    }

    #[Test]
    public function parseAtLastPositionInBareAddressThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('user@');
    }

    #[Test]
    public function parseTwoCharMinimalAddress(): void
    {
        $mailbox = Mailbox::parse('a@b');

        static::assertSame('a', $mailbox->localPart);
        static::assertSame('b', $mailbox->domain);
        static::assertSame('a@b', $mailbox->address);
    }

    #[Test]
    public function parseAtLastInAngleBracketThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('Name <local@>');
    }

    #[Test]
    public function parseAtFirstInAngleBracketThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('Name <@domain>');
    }

    #[Test]
    public function parseNoAtSignInAddrSpecThrows(): void
    {
        $this->expectException(ParsingException::class);

        Mailbox::parse('nodomain');
    }

    #[Test]
    public function parseBackslashEscapeConsumesNextCharInComment(): void
    {
        $mailbox = Mailbox::parse('a@b.com (\\))');

        static::assertSame('a@b.com', $mailbox->address);
        static::assertSame(')', $mailbox->comment);
    }

    #[Test]
    public function parseBackslashAtPenultimatePositionInComment(): void
    {
        $input = 'a@b.com(\\)';
        $mailbox = Mailbox::parse($input);

        static::assertSame('a@b.com', $mailbox->address);
        static::assertNull($mailbox->comment);
    }

    #[Test]
    public function parseBackslashEscapeInCommentAtBoundary(): void
    {
        $input = "a@b.com(\\x)";
        $mailbox = Mailbox::parse($input);

        static::assertSame('a@b.com', $mailbox->address);
        static::assertSame('x', $mailbox->comment);
    }

    #[Test]
    public function parseBackslashEscapeOutsideCommentAtBoundary(): void
    {
        $this->expectException(ParsingException::class);

        $input = 'u\\@r@example.com';
        Mailbox::parse($input);
    }

    #[Test]
    public function parseBackslashAsLastCharInInput(): void
    {
        $input = 'a@b.com(\\';
        $mailbox = Mailbox::parse($input);

        static::assertSame('a@b.com', $mailbox->address);
        static::assertNull($mailbox->comment);
    }

    #[Test]
    public function parseBackslashAsLastCharOutsideComment(): void
    {
        $this->expectException(ParsingException::class);

        $input = 'a@b.com\\';
        Mailbox::parse($input);
    }

    #[Test]
    public function parseBackslashAsVeryLastCharInCommentIsNotEscape(): void
    {
        $input = "u@e.com(a\\";
        $mailbox = Mailbox::parse($input);

        static::assertSame('u@e.com', $mailbox->address);
        static::assertNull($mailbox->comment);
    }
}
