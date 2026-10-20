<?php

declare(strict_types=1);

namespace Psl\Message\Tests\Unit\Address;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psl\Message\Address\Group;
use Psl\Message\Address\Mailbox;
use Psl\Message\Exception\ParsingException;

final class GroupTest extends TestCase
{
    #[Test]
    public function constructWithMailboxes(): void
    {
        $group = new Group('Team', [
            new Mailbox('alice', 'example.com', 'Alice'),
            new Mailbox('bob', 'example.com', 'Bob'),
        ]);

        self::assertSame('Team', $group->displayName);
        self::assertCount(2, $group->mailboxes);
    }

    #[Test]
    public function constructEmptyGroup(): void
    {
        $group = new Group('Undisclosed Recipients');

        self::assertSame('Undisclosed Recipients', $group->displayName);
        self::assertSame([], $group->mailboxes);
    }

    #[Test]
    public function parseWithMailboxes(): void
    {
        $group = Group::parse('Team: alice@example.com, bob@example.com;');

        self::assertSame('Team', $group->displayName);
        self::assertCount(2, $group->mailboxes);
        self::assertSame('alice@example.com', $group->mailboxes[0]->address);
        self::assertSame('bob@example.com', $group->mailboxes[1]->address);
    }

    #[Test]
    public function parseEmptyGroup(): void
    {
        $group = Group::parse('Undisclosed Recipients:;');

        self::assertSame('Undisclosed Recipients', $group->displayName);
        self::assertSame([], $group->mailboxes);
    }

    #[Test]
    public function parseMissingColonThrows(): void
    {
        $this->expectException(ParsingException::class);

        Group::parse('no colon here');
    }

    #[Test]
    public function parseMissingSemicolonThrows(): void
    {
        $this->expectException(ParsingException::class);

        Group::parse('Team: alice@example.com');
    }

    #[Test]
    public function toStringWithMailboxes(): void
    {
        $group = new Group('Team', [
            new Mailbox('alice', 'example.com'),
            new Mailbox('bob', 'example.com'),
        ]);

        self::assertSame('Team: alice@example.com, bob@example.com;', $group->toString());
        self::assertSame('Team: alice@example.com, bob@example.com;', (string) $group);
    }

    #[Test]
    public function toStringEmptyGroup(): void
    {
        $group = new Group('Undisclosed Recipients');

        self::assertSame('Undisclosed Recipients:;', $group->toString());
    }

    #[Test]
    public function parseRoundTrip(): void
    {
        $original = new Group('Team', [
            new Mailbox('alice', 'example.com'),
            new Mailbox('bob', 'example.com'),
        ]);

        $parsed = Group::parse($original->toString());

        self::assertSame($original->displayName, $parsed->displayName);
        self::assertCount(2, $parsed->mailboxes);
        self::assertSame('alice@example.com', $parsed->mailboxes[0]->address);
    }

    #[Test]
    public function parseSingleMember(): void
    {
        $group = Group::parse('Admin: admin@example.com;');

        self::assertSame('Admin', $group->displayName);
        self::assertCount(1, $group->mailboxes);
        self::assertSame('admin@example.com', $group->mailboxes[0]->address);
    }

    #[Test]
    public function parseTrimsWhitespace(): void
    {
        $group = Group::parse('  Team  :  alice@example.com  ;  ');

        self::assertSame('Team', $group->displayName);
        self::assertCount(1, $group->mailboxes);
    }

    #[Test]
    public function parseWithDisplayNameMailboxes(): void
    {
        $group = Group::parse('Team: "Alice" <alice@example.com>, Bob <bob@example.com>;');

        self::assertCount(2, $group->mailboxes);
        self::assertSame('Alice', $group->mailboxes[0]->displayName);
        self::assertSame('Bob', $group->mailboxes[1]->displayName);
    }

    #[Test]
    public function parseSkipsEmptyMailboxParts(): void
    {
        $group = Group::parse('Team: alice@example.com, , bob@example.com;');

        self::assertCount(2, $group->mailboxes);
    }

    #[Test]
    public function stringableInterface(): void
    {
        $group = new Group('Team', [new Mailbox('a', 'x.com')]);

        self::assertSame($group->toString(), (string) $group);
    }

    #[Test]
    public function parseWithLeadingWhitespace(): void
    {
        $group = Group::parse('  Team: alice@example.com;  ');

        static::assertSame('Team', $group->displayName);
        static::assertCount(1, $group->mailboxes);
    }

    #[Test]
    public function parseColonAtStartThrows(): void
    {
        $this->expectException(ParsingException::class);

        Group::parse(':alice@example.com;');
    }

    #[Test]
    public function parseNoColonThrowsException(): void
    {
        $this->expectException(ParsingException::class);

        Group::parse('no-colon-here');
    }

    #[Test]
    public function parseWithTrailingWhitespaceBeforeSemicolon(): void
    {
        $group = Group::parse('Team: alice@example.com;   ');

        static::assertSame('Team', $group->displayName);
        static::assertCount(1, $group->mailboxes);
    }

    #[Test]
    public function parseWithSpacesAroundMailboxList(): void
    {
        $group = Group::parse('Empty:   ;');

        static::assertSame('Empty', $group->displayName);
        static::assertSame([], $group->mailboxes);
    }

    #[Test]
    public function parseEmptyMailboxListReturnsGroupWithNoMailboxes(): void
    {
        $group = Group::parse('Undisclosed:;');

        static::assertSame('Undisclosed', $group->displayName);
        static::assertSame([], $group->mailboxes);
    }

    #[Test]
    public function toStringProducesCorrectFormat(): void
    {
        $group = new Group('Team', [
            new Mailbox('alice', 'example.com'),
            new Mailbox('bob', 'example.com'),
        ]);

        $result = $group->toString();

        static::assertSame('Team: alice@example.com, bob@example.com;', $result);
    }

    #[Test]
    public function parseTrimmedInputRequired(): void
    {
        $group = Group::parse('  Team: alice@example.com;  ');

        static::assertSame('Team', $group->displayName);
        static::assertCount(1, $group->mailboxes);
        static::assertSame('alice@example.com', $group->mailboxes[0]->address);
    }

    #[Test]
    public function parseSemicolonCheckUsesTrimmedInput(): void
    {
        $group = Group::parse('Team: alice@example.com;   ');

        static::assertSame('Team', $group->displayName);
        static::assertCount(1, $group->mailboxes);
    }

    #[Test]
    public function parseMailboxListIsTrimmed(): void
    {
        $group = Group::parse('Team:   alice@example.com   ;');

        static::assertSame('Team', $group->displayName);
        static::assertCount(1, $group->mailboxes);
        static::assertSame('alice@example.com', $group->mailboxes[0]->address);
    }

    #[Test]
    public function parseEmptyMailboxListReturnsNoMailboxes(): void
    {
        $group = Group::parse('Empty:;');

        static::assertSame('Empty', $group->displayName);
        static::assertSame([], $group->mailboxes);
    }

    #[Test]
    public function parseWhitespaceOnlyMailboxListReturnsNoMailboxes(): void
    {
        $group = Group::parse('Empty:   ;');

        static::assertSame('Empty', $group->displayName);
        static::assertSame([], $group->mailboxes);
    }

    #[Test]
    public function toStringUsesMailboxToStringMethod(): void
    {
        $group = new Group('Team', [
            new Mailbox('alice', 'example.com', 'Alice'),
        ]);

        $result = $group->toString();

        static::assertSame('Team: Alice <alice@example.com>;', $result);
    }

    #[Test]
    public function parseInputWithLeadingWhitespaceSucceeds(): void
    {
        $group = Group::parse('   Team: alice@example.com;');

        static::assertSame('Team', $group->displayName);
        static::assertCount(1, $group->mailboxes);
        static::assertSame('alice@example.com', $group->mailboxes[0]->address);
    }

    #[Test]
    public function parseInputWithTrailingWhitespaceCheckedForSemicolon(): void
    {
        $group = Group::parse('Team: alice@example.com;    ');

        static::assertSame('Team', $group->displayName);
        static::assertCount(1, $group->mailboxes);
    }

    #[Test]
    public function parseInputWithBothLeadingAndTrailingWhitespace(): void
    {
        $group = Group::parse("  \t Team: bob@example.com; \t ");

        static::assertSame('Team', $group->displayName);
        static::assertCount(1, $group->mailboxes);
        static::assertSame('bob@example.com', $group->mailboxes[0]->address);
    }

    #[Test]
    public function parseMissingSemicolonWithTrailingSpaceThrows(): void
    {
        $this->expectException(ParsingException::class);

        Group::parse('Team: alice@example.com   ');
    }

    #[Test]
    public function parseMissingSemicolonWithTrailingTabThrows(): void
    {
        $this->expectException(ParsingException::class);

        Group::parse("Team: alice@example.com\t");
    }

    #[Test]
    public function parseSemicolonCheckUsesTrimmedInputNotRaw(): void
    {
        $group = Group::parse("Team: alice@example.com;\n");

        static::assertSame('Team', $group->displayName);
    }

    #[Test]
    #[DataProvider('provideNonWhitespaceControlCharacters')]
    public function parseDoesNotTrimControlCharactersAfterSemicolon(string $char): void
    {
        $this->expectException(ParsingException::class);

        Group::parse("Team: alice@example.com;{$char}");
    }

    #[Test]
    #[DataProvider('provideNonWhitespaceControlCharacters')]
    public function parseDoesNotTrimControlCharactersAroundDisplayName(string $char): void
    {
        if ($char === "\0") {
            $this->expectException(ParsingException::class);
        }

        $group = Group::parse("{$char}Team{$char}: alice@example.com;");

        static::assertSame("{$char}Team{$char}", $group->displayName);
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
    public function parseMailboxListTrimmedBefore(): void
    {
        $group = Group::parse('Team:   ;');

        static::assertSame('Team', $group->displayName);
        static::assertSame([], $group->mailboxes);
    }

    #[Test]
    public function parseMailboxListWithLeadingSpaces(): void
    {
        $group = Group::parse('Team:    alice@example.com;');

        static::assertSame('Team', $group->displayName);
        static::assertCount(1, $group->mailboxes);
        static::assertSame('alice@example.com', $group->mailboxes[0]->address);
    }

    #[Test]
    public function parseMailboxListWithTrailingSpaces(): void
    {
        $group = Group::parse('Team: alice@example.com   ;');

        static::assertSame('Team', $group->displayName);
        static::assertCount(1, $group->mailboxes);
        static::assertSame('alice@example.com', $group->mailboxes[0]->address);
    }

    #[Test]
    public function parseEmptyMailboxListReturnsGroupWithoutMailboxes(): void
    {
        $group = Group::parse('NoMembers:;');

        static::assertSame('NoMembers', $group->displayName);
        static::assertSame([], $group->mailboxes);
        static::assertSame('NoMembers:;', $group->toString());
    }

    #[Test]
    public function parseWhitespaceOnlyMailboxListReturnsEmptyGroup(): void
    {
        $group = Group::parse('NoMembers:    ;');

        static::assertSame('NoMembers', $group->displayName);
        static::assertSame([], $group->mailboxes);
    }

    #[Test]
    public function parseEmptyMailboxListReturnsEmptyGroupCountZero(): void
    {
        $group = Group::parse('Empty:;');

        static::assertCount(0, $group->mailboxes);
        static::assertSame('Empty', $group->displayName);
    }

    #[Test]
    public function toStringWithMultipleMailboxesUsesToStringOnEach(): void
    {
        $group = new Group('Dev', [
            new Mailbox('alice', 'example.com', 'Alice'),
            new Mailbox('bob', 'example.com', 'Bob'),
        ]);

        $result = $group->toString();

        static::assertSame('Dev: Alice <alice@example.com>, Bob <bob@example.com>;', $result);
        static::assertStringContainsString('Alice <alice@example.com>', $result);
        static::assertStringContainsString('Bob <bob@example.com>', $result);
    }

    #[Test]
    public function toStringEmptyGroupUsesColonSemicolonFormat(): void
    {
        $group = new Group('Empty');

        static::assertSame('Empty:;', $group->toString());
        static::assertStringNotContainsString(': ;', $group->toString());
    }

    #[Test]
    public function toStringSingleMailboxUsesToStringMethod(): void
    {
        $group = new Group('Solo', [new Mailbox('user', 'example.com')]);

        $result = $group->toString();

        static::assertSame('Solo: user@example.com;', $result);
    }
}
