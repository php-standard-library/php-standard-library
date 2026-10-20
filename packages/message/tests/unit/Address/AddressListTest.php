<?php

declare(strict_types=1);

namespace Psl\Message\Tests\Unit\Address;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psl\Message\Address\AddressList;
use Psl\Message\Address\Group;
use Psl\Message\Address\Mailbox;
use Psl\Message\Exception\ParsingException;

use function array_key_first;

final class AddressListTest extends TestCase
{
    #[Test]
    public function constructEmpty(): void
    {
        $list = new AddressList([]);

        self::assertCount(0, $list);
        self::assertSame([], $list->addresses);
    }

    #[Test]
    public function ofSingleMailbox(): void
    {
        $mailbox = new Mailbox('user', 'example.com');
        $list = AddressList::of($mailbox);

        self::assertCount(1, $list);
        self::assertSame($mailbox, $list->addresses[0]);
    }

    #[Test]
    public function ofMultipleAddresses(): void
    {
        $a = new Mailbox('alice', 'example.com');
        $b = new Mailbox('bob', 'example.com');
        $list = AddressList::of($a, $b);

        self::assertCount(2, $list);
    }

    #[Test]
    public function parseSingleMailbox(): void
    {
        $list = AddressList::parse('user@example.com');

        self::assertCount(1, $list);
        self::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        self::assertSame('user@example.com', $list->addresses[0]->address);
    }

    #[Test]
    public function parseMultipleMailboxes(): void
    {
        $list = AddressList::parse('alice@example.com, bob@example.com');

        self::assertCount(2, $list);
        self::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        self::assertSame('alice@example.com', $list->addresses[0]->address);
        self::assertInstanceOf(Mailbox::class, $list->addresses[1]);
        self::assertSame('bob@example.com', $list->addresses[1]->address);
    }

    #[Test]
    public function parseWithDisplayNames(): void
    {
        $list = AddressList::parse('"Alice" <alice@example.com>, "Bob" <bob@example.com>');

        self::assertCount(2, $list);
        self::assertSame('Alice', $list->addresses[0]->displayName);
        self::assertSame('Bob', $list->addresses[1]->displayName);
    }

    #[Test]
    public function parseWithGroup(): void
    {
        $list = AddressList::parse('Team: alice@example.com, bob@example.com;');

        self::assertCount(1, $list);
        self::assertInstanceOf(Group::class, $list->addresses[0]);
        self::assertSame('Team', $list->addresses[0]->displayName);
        self::assertCount(2, $list->addresses[0]->mailboxes);
    }

    #[Test]
    public function parseMixedMailboxesAndGroups(): void
    {
        $list = AddressList::parse('admin@example.com, Team: alice@example.com;');

        self::assertCount(2, $list);
        self::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        self::assertInstanceOf(Group::class, $list->addresses[1]);
    }

    #[Test]
    public function parseEmpty(): void
    {
        $list = AddressList::parse('');

        self::assertCount(0, $list);
    }

    #[Test]
    public function mailboxesFlattensGroups(): void
    {
        $alice = new Mailbox('alice', 'example.com');
        $bob = new Mailbox('bob', 'example.com');
        $admin = new Mailbox('admin', 'example.com');

        $list = new AddressList([
            $admin,
            new Group('Team', [$alice, $bob]),
        ]);

        $mailboxes = $list->mailboxes();
        self::assertCount(3, $mailboxes);
        self::assertSame('admin@example.com', $mailboxes[0]->address);
        self::assertSame('alice@example.com', $mailboxes[1]->address);
        self::assertSame('bob@example.com', $mailboxes[2]->address);
    }

    #[Test]
    public function iteratorAggregate(): void
    {
        $list = AddressList::of(new Mailbox('alice', 'example.com'), new Mailbox('bob', 'example.com'));

        $addresses = [];
        foreach ($list as $address) {
            $addresses[] = $address;
        }

        self::assertCount(2, $addresses);
    }

    #[Test]
    public function toStringCommaSeparated(): void
    {
        $list = AddressList::of(new Mailbox('alice', 'example.com'), new Mailbox('bob', 'example.com'));

        self::assertSame('alice@example.com, bob@example.com', $list->toString());
        self::assertSame('alice@example.com, bob@example.com', (string) $list);
    }

    #[Test]
    public function parseRoundTrip(): void
    {
        $original = AddressList::of(
            new Mailbox('alice', 'example.com', 'Alice'),
            new Mailbox('bob', 'example.com', 'Bob'),
        );

        $parsed = AddressList::parse($original->toString());

        self::assertCount(2, $parsed);
        self::assertInstanceOf(Mailbox::class, $parsed->addresses[0]);
        self::assertSame('alice@example.com', $parsed->addresses[0]->address);
        self::assertInstanceOf(Mailbox::class, $parsed->addresses[1]);
        self::assertSame('bob@example.com', $parsed->addresses[1]->address);
    }

    #[Test]
    public function parseWithCommaInQuotedDisplayName(): void
    {
        $list = AddressList::parse('"Doe, John" <john@example.com>, bob@example.com');

        self::assertCount(2, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertInstanceOf(Mailbox::class, $list->addresses[1]);
        self::assertSame('Doe, John', $list->addresses[0]->displayName);
        self::assertSame('bob@example.com', $list->addresses[1]->address);
    }

    #[Test]
    public function parseWithEscapedQuoteInDisplayName(): void
    {
        $list = AddressList::parse('"O\\"Brien" <john@example.com>');

        self::assertCount(1, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        self::assertSame('john@example.com', $list->addresses[0]->address);
    }

    #[Test]
    public function parseSkipsEmptySegments(): void
    {
        $list = AddressList::parse('alice@example.com, , bob@example.com');

        self::assertCount(2, $list);
    }

    #[Test]
    public function toStringEmpty(): void
    {
        $list = new AddressList([]);

        self::assertSame('', $list->toString());
    }

    #[Test]
    public function toStringWithGroup(): void
    {
        $group = new Group('Team', [
            new Mailbox('alice', 'example.com'),
        ]);
        $list = new AddressList([$group]);

        self::assertSame('Team: alice@example.com;', $list->toString());
    }

    #[Test]
    public function parseCommaOnlyInput(): void
    {
        $list = AddressList::parse(',,,');

        self::assertCount(0, $list);
    }

    #[Test]
    public function mailboxesFromEmptyList(): void
    {
        $list = new AddressList([]);

        self::assertSame([], $list->mailboxes());
    }

    #[Test]
    public function countReturnsAddressCount(): void
    {
        $list = AddressList::of(new Mailbox('a', 'x.com'), new Mailbox('b', 'x.com'), new Mailbox('c', 'x.com'));

        self::assertCount(3, $list);
    }

    #[Test]
    public function excludeRemovesMatchingMailbox(): void
    {
        $list = AddressList::of(
            new Mailbox('alice', 'example.com'),
            new Mailbox('bob', 'example.com'),
            new Mailbox('carol', 'example.com'),
        );

        $filtered = $list->exclude(new Mailbox('bob', 'example.com'));

        self::assertCount(2, $filtered);
        static::assertInstanceOf(Mailbox::class, $filtered->addresses[0]);
        static::assertInstanceOf(Mailbox::class, $filtered->addresses[1]);
        self::assertSame('alice@example.com', $filtered->addresses[0]->address);
        self::assertSame('carol@example.com', $filtered->addresses[1]->address);
    }

    #[Test]
    public function excludeIsCaseInsensitive(): void
    {
        $list = AddressList::of(new Mailbox('Bob', 'Example.COM'));

        $filtered = $list->exclude(new Mailbox('bob', 'example.com'));

        self::assertCount(0, $filtered);
    }

    #[Test]
    public function excludeFiltersInsideGroups(): void
    {
        $group = new Group('Team', [
            new Mailbox('alice', 'example.com'),
            new Mailbox('bob', 'example.com'),
        ]);
        $list = new AddressList([$group]);

        $filtered = $list->exclude(new Mailbox('bob', 'example.com'));

        self::assertCount(1, $filtered);
        self::assertInstanceOf(Group::class, $filtered->addresses[0]);
        self::assertCount(1, $filtered->addresses[0]->mailboxes);
        self::assertSame('alice@example.com', $filtered->addresses[0]->mailboxes[0]->address);
    }

    #[Test]
    public function excludeRemovesEmptyGroups(): void
    {
        $group = new Group('Solo', [new Mailbox('bob', 'example.com')]);
        $list = new AddressList([$group]);

        $filtered = $list->exclude(new Mailbox('bob', 'example.com'));

        self::assertCount(0, $filtered);
    }

    #[Test]
    public function excludeNoMatch(): void
    {
        $list = AddressList::of(new Mailbox('alice', 'example.com'));

        $filtered = $list->exclude(new Mailbox('bob', 'example.com'));

        self::assertCount(1, $filtered);
    }

    #[Test]
    public function mergeMultipleLists(): void
    {
        $a = AddressList::of(new Mailbox('alice', 'example.com'));
        $b = AddressList::of(new Mailbox('bob', 'example.com'));
        $c = AddressList::of(new Mailbox('carol', 'example.com'));

        $merged = AddressList::merge($a, $b, $c);

        self::assertCount(3, $merged);
        static::assertInstanceOf(Mailbox::class, $merged->addresses[0]);
        static::assertInstanceOf(Mailbox::class, $merged->addresses[1]);
        static::assertInstanceOf(Mailbox::class, $merged->addresses[2]);
        self::assertSame('alice@example.com', $merged->addresses[0]->address);
        self::assertSame('bob@example.com', $merged->addresses[1]->address);
        self::assertSame('carol@example.com', $merged->addresses[2]->address);
    }

    #[Test]
    public function mergeEmpty(): void
    {
        $merged = AddressList::merge();

        self::assertCount(0, $merged);
    }

    #[Test]
    public function parseQuotedDisplayNameWithEscapedBackslash(): void
    {
        $list = AddressList::parse('"O\\"Brien\\\\" <john@example.com>, alice@example.com');

        static::assertCount(2, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertInstanceOf(Mailbox::class, $list->addresses[1]);
        static::assertSame('john@example.com', $list->addresses[0]->address);
        static::assertSame('alice@example.com', $list->addresses[1]->address);
    }

    #[Test]
    public function parseUnclosedQuoteDoesNotAccessOutOfBounds(): void
    {
        $this->expectException(ParsingException::class);

        AddressList::parse('"unclosed, bob@example.com');
    }

    #[Test]
    public function parseAngleBracketDepthTracking(): void
    {
        $this->expectException(ParsingException::class);

        AddressList::parse('Alice <alice@example.com>, >bob@example.com');
    }

    #[Test]
    public function parseMultipleGroupsThenMailbox(): void
    {
        $list = AddressList::parse('Team: alice@example.com; , Ops: bob@example.com; , carol@example.com');

        static::assertCount(3, $list);
        static::assertInstanceOf(Group::class, $list->addresses[0]);
        static::assertInstanceOf(Group::class, $list->addresses[1]);
        static::assertInstanceOf(Mailbox::class, $list->addresses[2]);
        static::assertSame('carol@example.com', $list->addresses[2]->address);
    }

    #[Test]
    public function parseTrailingWhitespaceNotAddedAsSegment(): void
    {
        $list = AddressList::parse('alice@example.com,   ');

        static::assertCount(1, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('alice@example.com', $list->addresses[0]->address);
    }

    #[Test]
    public function ofWithNamedArguments(): void
    {
        $a = new Mailbox('alice', 'example.com');
        $b = new Mailbox('bob', 'example.com');
        $list = AddressList::of($a, $b);

        static::assertCount(2, $list);
        static::assertSame($a, $list->addresses[0]);
        static::assertSame($b, $list->addresses[1]);
    }

    #[Test]
    public function parseWhitespaceOnlyInput(): void
    {
        $list = AddressList::parse('   ');

        static::assertCount(0, $list);
    }

    #[Test]
    public function parseLeadingTrailingWhitespace(): void
    {
        $list = AddressList::parse('  alice@example.com  ');

        static::assertCount(1, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('alice@example.com', $list->addresses[0]->address);
    }

    #[Test]
    public function parseEmptyReturnsEmptyList(): void
    {
        $list = AddressList::parse('');

        static::assertCount(0, $list);
        static::assertSame([], $list->addresses);
    }

    #[Test]
    public function parseGroupWithTrailingWhitespaceBeforeSemicolon(): void
    {
        $list = AddressList::parse('Team: alice@example.com ;');

        static::assertCount(1, $list);
        static::assertInstanceOf(Group::class, $list->addresses[0]);
        static::assertSame('Team', $list->addresses[0]->displayName);
    }

    #[Test]
    public function parseColonWithoutSemicolonIsMailbox(): void
    {
        $this->expectException(ParsingException::class);

        AddressList::parse('just-semicolon@example.com;');
    }

    #[Test]
    public function excludeCaseInsensitiveOnExcludeAddress(): void
    {
        $list = AddressList::of(new Mailbox('Alice', 'Example.COM'), new Mailbox('bob', 'example.com'));

        $filtered = $list->exclude(new Mailbox('ALICE', 'EXAMPLE.COM'));

        static::assertCount(1, $filtered);
        static::assertInstanceOf(Mailbox::class, $filtered->addresses[0]);
        static::assertSame('bob@example.com', $filtered->addresses[0]->address);
    }

    #[Test]
    public function excludeCaseInsensitiveInGroupFilter(): void
    {
        $group = new Group('Team', [
            new Mailbox('Alice', 'Example.COM'),
            new Mailbox('bob', 'example.com'),
        ]);
        $list = new AddressList([$group]);

        $filtered = $list->exclude(new Mailbox('alice', 'example.com'));

        static::assertCount(1, $filtered);
        static::assertInstanceOf(Group::class, $filtered->addresses[0]);
        static::assertCount(1, $filtered->addresses[0]->mailboxes);
        static::assertSame('bob@example.com', $filtered->addresses[0]->mailboxes[0]->address);
    }

    #[Test]
    public function excludeFromGroupPreservesListKeys(): void
    {
        $group = new Group('Team', [
            new Mailbox('alice', 'example.com'),
            new Mailbox('bob', 'example.com'),
            new Mailbox('carol', 'example.com'),
        ]);
        $list = new AddressList([$group]);

        $filtered = $list->exclude(new Mailbox('bob', 'example.com'));

        static::assertCount(1, $filtered);
        static::assertInstanceOf(Group::class, $filtered->addresses[0]);
        static::assertCount(2, $filtered->addresses[0]->mailboxes);
        static::assertSame('alice@example.com', $filtered->addresses[0]->mailboxes[0]->address);
        static::assertSame('carol@example.com', $filtered->addresses[0]->mailboxes[1]->address);
    }

    #[Test]
    public function toStringUsesAddressToString(): void
    {
        $list = AddressList::of(new Mailbox('alice', 'example.com', 'Alice'), new Mailbox('bob', 'example.com'));

        $result = $list->toString();

        static::assertSame('Alice <alice@example.com>, bob@example.com', $result);
    }

    #[Test]
    public function parseQuotedStringWithPrecedingContent(): void
    {
        $list = AddressList::parse('prefix"quoted"suffix <addr@example.com>');

        static::assertCount(1, $list);
    }

    #[Test]
    public function parseQuotedDisplayNamePreservesEscapedChars(): void
    {
        $list = AddressList::parse('"John \\"Doe\\"" <john@example.com>');

        static::assertCount(1, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('john@example.com', $list->addresses[0]->address);
    }

    #[Test]
    public function parseQuotedStringWithMultipleEscapedChars(): void
    {
        $input = '"A\\\\B\\,C" <user@example.com>, other@example.com';
        $list = AddressList::parse($input);

        static::assertCount(2, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('user@example.com', $list->addresses[0]->address);
        static::assertInstanceOf(Mailbox::class, $list->addresses[1]);
        static::assertSame('other@example.com', $list->addresses[1]->address);
    }

    #[Test]
    public function parseQuotedStringEscapeAtEndOfInput(): void
    {
        $input = '"test\\\\" <user@example.com>';
        $list = AddressList::parse($input);

        static::assertCount(1, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('user@example.com', $list->addresses[0]->address);
    }

    #[Test]
    public function splitAddressesCommaInsideQuotedStringNotSplit(): void
    {
        $list = AddressList::parse('"Doe, Jr." <john@example.com>, bob@example.com');

        static::assertCount(2, $list);
        static::assertSame('Doe, Jr.', $list->addresses[0]->displayName);
        static::assertSame('bob@example.com', $list->addresses[1]->address);
    }

    #[Test]
    public function parseDoubleEscapedBackslashInQuotedName(): void
    {
        $input = '"test\\\\" <a@b.com>, c@d.com';
        $list = AddressList::parse($input);

        static::assertCount(2, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('a@b.com', $list->addresses[0]->address);
        static::assertInstanceOf(Mailbox::class, $list->addresses[1]);
        static::assertSame('c@d.com', $list->addresses[1]->address);
    }

    #[Test]
    public function parseTrimmedInputBeforeEmptyCheck(): void
    {
        $list = AddressList::parse('  ');

        static::assertCount(0, $list);
        static::assertSame([], $list->addresses);
    }

    #[Test]
    public function parseEmptyInputReturnsEmptyListNotNull(): void
    {
        $list = AddressList::parse('');

        static::assertCount(0, $list);
        static::assertSame('', $list->toString());
    }

    #[Test]
    public function parseGroupDetectionUsesTrimmedSegment(): void
    {
        $list = AddressList::parse('Team: alice@example.com ;');

        static::assertCount(1, $list);
        static::assertInstanceOf(Group::class, $list->addresses[0]);
    }

    #[Test]
    public function toStringCallsAddressToStringMethod(): void
    {
        $group = new Group('Team', [new Mailbox('alice', 'example.com')]);
        $list = new AddressList([$group, new Mailbox('bob', 'example.com')]);

        $result = $list->toString();

        static::assertSame('Team: alice@example.com;, bob@example.com', $result);
    }

    #[Test]
    public function splitAddressesQuotedStringAppendsNotReplaces(): void
    {
        $input = 'prefix"quoted" <addr@example.com>';
        $list = AddressList::parse($input);

        static::assertCount(1, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('addr@example.com', $list->addresses[0]->address);
    }

    #[Test]
    public function splitAddressesEscapedCharInQuotedStringPreserved(): void
    {
        $input = '"test\\@value" <user@example.com>, other@example.com';
        $list = AddressList::parse($input);

        static::assertCount(2, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('user@example.com', $list->addresses[0]->address);
    }

    #[Test]
    public function splitAddressesClosingAngleBracketDecreasesDepth(): void
    {
        $input = '<alice@example.com>, bob@example.com';
        $list = AddressList::parse($input);

        static::assertCount(2, $list);
    }

    #[Test]
    public function splitAddressesClosingAngleBracketIgnoredAtDepthZero(): void
    {
        $this->expectException(ParsingException::class);

        $input = '>alice@example.com, bob@example.com';
        AddressList::parse($input);
    }

    #[Test]
    public function splitAddressesTrimmedCurrentBeforeSegmentAdd(): void
    {
        $input = 'alice@example.com, bob@example.com   ';
        $list = AddressList::parse($input);

        static::assertCount(2, $list);
    }

    #[Test]
    public function splitAddressesTrailingWhitespaceOnlySegmentNotAdded(): void
    {
        $input = 'alice@example.com,    ';
        $list = AddressList::parse($input);

        static::assertCount(1, $list);
    }

    #[Test]
    public function parseQuotedEscapeCharIsBackslashFollowedByNextChar(): void
    {
        $input = '"A\\BC" <user@example.com>';
        $list = AddressList::parse($input);

        static::assertCount(1, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('user@example.com', $list->addresses[0]->address);
        static::assertSame('ABC', $list->addresses[0]->displayName);
    }

    #[Test]
    public function parseQuotedEscapedCharPositionCorrect(): void
    {
        $input = '"te\\st" <user@example.com>, other@example.com';
        $list = AddressList::parse($input);

        static::assertCount(2, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('user@example.com', $list->addresses[0]->address);
        static::assertInstanceOf(Mailbox::class, $list->addresses[1]);
        static::assertSame('other@example.com', $list->addresses[1]->address);
    }

    #[Test]
    public function ofProducesZeroIndexedList(): void
    {
        $a = new Mailbox('alice', 'example.com');
        $b = new Mailbox('bob', 'example.com');
        $c = new Mailbox('carol', 'example.com');
        $list = AddressList::of(first: $a, second: $b, third: $c);

        static::assertCount(3, $list);
        static::assertSame($a, $list->addresses[0]);
        static::assertSame($b, $list->addresses[1]);
        static::assertSame($c, $list->addresses[2]);
    }

    #[Test]
    public function ofWithSingleNamedArgumentProducesListIndexZero(): void
    {
        $m = new Mailbox('user', 'example.com');
        $list = AddressList::of(addr: $m);

        static::assertCount(1, $list);
        static::assertSame(0, array_key_first($list->addresses));
    }

    #[Test]
    public function ofWithGroupProducesListKeys(): void
    {
        $g = new Group('Team', [new Mailbox('a', 'b.com')]);
        $m = new Mailbox('c', 'd.com');
        $list = AddressList::of(x: $g, y: $m);

        static::assertCount(2, $list);
        static::assertArrayHasKey(0, $list->addresses);
        static::assertArrayHasKey(1, $list->addresses);
        static::assertSame($g, $list->addresses[0]);
        static::assertSame($m, $list->addresses[1]);
    }

    #[Test]
    public function parseTabWhitespaceReturnsEmpty(): void
    {
        $list = AddressList::parse("\t");

        static::assertCount(0, $list);
        static::assertSame([], $list->addresses);
    }

    #[Test]
    public function parseNewlineWhitespaceReturnsEmpty(): void
    {
        $list = AddressList::parse("\n  \t  ");

        static::assertCount(0, $list);
        static::assertSame([], $list->addresses);
    }

    #[Test]
    public function parseMixedWhitespaceReturnsEmptyList(): void
    {
        $list = AddressList::parse("  \r\n  ");

        static::assertCount(0, $list);
        static::assertSame('', $list->toString());
    }

    #[Test]
    #[DataProvider('provideNonWhitespaceControlCharacters')]
    public function parseDoesNotSkipControlCharacterOnlySegments(string $char): void
    {
        $this->expectException(ParsingException::class);

        AddressList::parse("alice@example.com,{$char}");
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
    public function parseEmptyReturnsEmptyAndDoesNotParseFurther(): void
    {
        $list = AddressList::parse('');

        static::assertCount(0, $list);
        static::assertSame([], $list->addresses);
        static::assertSame('', $list->toString());
    }

    #[Test]
    public function parseWhitespaceOnlyReturnsEmptyNotException(): void
    {
        $list = AddressList::parse('   ');

        static::assertSame([], $list->addresses);
        static::assertCount(0, $list);
    }

    #[Test]
    public function parseTabOnlyReturnsEmptyAddressList(): void
    {
        $list = AddressList::parse("\t\t");

        static::assertSame([], $list->addresses);
    }

    #[Test]
    public function parseGroupWithTrailingSpaceAroundSemicolon(): void
    {
        $list = AddressList::parse('Team: alice@example.com   ;  ');

        static::assertCount(1, $list);
        static::assertInstanceOf(Group::class, $list->addresses[0]);
        static::assertSame('Team', $list->addresses[0]->displayName);
        static::assertCount(1, $list->addresses[0]->mailboxes);
    }

    #[Test]
    public function parseGroupWithWhitespaceBeforeSemicolonUsesGroup(): void
    {
        $list = AddressList::parse('Dev: bob@example.com  ;');

        static::assertCount(1, $list);
        static::assertInstanceOf(Group::class, $list->addresses[0]);
    }

    #[Test]
    public function parseGroupWithInternalWhitespaceDetected(): void
    {
        $list = AddressList::parse('  Team  :  alice@example.com  ;  ');

        static::assertCount(1, $list);
        static::assertInstanceOf(Group::class, $list->addresses[0]);
        static::assertSame('Team', $list->addresses[0]->displayName);
    }

    #[Test]
    public function toStringConvertsSingleMailboxToString(): void
    {
        $list = new AddressList([new Mailbox('user', 'example.com', 'User Name')]);

        static::assertSame('User Name <user@example.com>', $list->toString());
    }

    #[Test]
    public function toStringConvertsMultipleMailboxesToCommaSeparated(): void
    {
        $list = new AddressList([
            new Mailbox('alice', 'example.com', 'Alice'),
            new Mailbox('bob', 'example.com'),
        ]);

        static::assertSame('Alice <alice@example.com>, bob@example.com', $list->toString());
    }

    #[Test]
    public function toStringConvertsGroupCorrectly(): void
    {
        $list = new AddressList([
            new Group('Team', [
                new Mailbox('alice', 'example.com'),
                new Mailbox('bob', 'example.com'),
            ]),
        ]);

        $result = $list->toString();
        static::assertSame('Team: alice@example.com, bob@example.com;', $result);
    }

    #[Test]
    public function splitAddressesQuotedContentAppendedToExisting(): void
    {
        $input = 'Name "Middle" Last <user@example.com>';
        $list = AddressList::parse($input);

        static::assertCount(1, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('user@example.com', $list->addresses[0]->address);
        static::assertNotNull($list->addresses[0]->displayName);
        static::assertStringContainsString('Name', $list->addresses[0]->displayName);
    }

    #[Test]
    public function splitAddressesBackslashInQuotedStringAtEndOfString(): void
    {
        $input = '"name\\\\" <a@b.com>';
        $list = AddressList::parse($input);

        static::assertCount(1, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('a@b.com', $list->addresses[0]->address);
    }

    #[Test]
    public function splitAddressesBackslashAtSecondToLastPositionInQuote(): void
    {
        $input = '"x\\\\" <a@b.com>, c@d.com';
        $list = AddressList::parse($input);

        static::assertCount(2, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('a@b.com', $list->addresses[0]->address);
        static::assertInstanceOf(Mailbox::class, $list->addresses[1]);
        static::assertSame('c@d.com', $list->addresses[1]->address);
    }

    #[Test]
    public function splitAddressesClosingAngleBracketDecreasesDepthCorrectly(): void
    {
        $input = 'Name <user@example.com>, other@example.com';
        $list = AddressList::parse($input);

        static::assertCount(2, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('user@example.com', $list->addresses[0]->address);
        static::assertInstanceOf(Mailbox::class, $list->addresses[1]);
        static::assertSame('other@example.com', $list->addresses[1]->address);
    }

    #[Test]
    public function splitAddressesCommaNotSplitInsideAngleBrackets(): void
    {
        $input = '<user@example.com>, second@example.com';
        $list = AddressList::parse($input);

        static::assertCount(2, $list);
    }

    #[Test]
    public function splitAddressesClosingBracketAtZeroDepthIsLiteral(): void
    {
        $this->expectException(ParsingException::class);

        $input = 'a>b@example.com';
        AddressList::parse($input);
    }

    #[Test]
    public function splitAddressesTrailingOnlyWhitespaceSegmentNotAdded(): void
    {
        $list = AddressList::parse('user@example.com,   ');

        static::assertCount(1, $list);
    }

    #[Test]
    public function splitAddressesTrailingTabsNotAdded(): void
    {
        $list = AddressList::parse("user@example.com,\t\t");

        static::assertCount(1, $list);
    }

    #[Test]
    public function splitAddressesTrailingNewlineNotAdded(): void
    {
        $list = AddressList::parse("user@example.com, \n ");

        static::assertCount(1, $list);
    }

    #[Test]
    public function splitAddressesBackslashEscapeInQuotedAtPenultimatePosition(): void
    {
        $input = '"ab\\c" <u@e.com>, x@y.com';
        $list = AddressList::parse($input);

        static::assertCount(2, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('u@e.com', $list->addresses[0]->address);
        static::assertInstanceOf(Mailbox::class, $list->addresses[1]);
        static::assertSame('x@y.com', $list->addresses[1]->address);
    }

    #[Test]
    public function splitAddressesEscapedCommaInQuotedStringDoesNotSplit(): void
    {
        $input = '"a\\,b" <u@e.com>, x@y.com';
        $list = AddressList::parse($input);

        static::assertCount(2, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('u@e.com', $list->addresses[0]->address);
        static::assertInstanceOf(Mailbox::class, $list->addresses[1]);
        static::assertSame('x@y.com', $list->addresses[1]->address);
    }

    #[Test]
    public function splitAddressesBackslashFollowedByQuoteInQuotedString(): void
    {
        $input = '"a\\"b" <u@e.com>, x@y.com';
        $list = AddressList::parse($input);

        static::assertCount(2, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('u@e.com', $list->addresses[0]->address);
    }

    #[Test]
    public function splitAddressesPreContentBeforeQuoteIsPreserved(): void
    {
        $input = 'before"quoted" <user@example.com>';
        $list = AddressList::parse($input);

        static::assertCount(1, $list);
        static::assertInstanceOf(Mailbox::class, $list->addresses[0]);
        static::assertSame('user@example.com', $list->addresses[0]->address);
        $display = $list->addresses[0]->displayName;
        static::assertNotNull($display);
        static::assertStringStartsWith('before', $display);
    }
}
