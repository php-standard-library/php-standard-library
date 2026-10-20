<?php

declare(strict_types=1);

namespace Psl\Message\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psl\IO;
use Psl\Message\Message;
use Psl\Message\MessageId;
use Psl\MIME\Exception\InvalidArgumentException;
use Psl\MIME\Headers;
use Psl\MIME\Part;

final class MessageTest extends TestCase
{
    #[Test]
    public function constructFromHeaders(): void
    {
        $message = new Message(Headers::fromPairs([
            ['From',       'alice@example.com'],
            ['To',         'bob@example.com'],
            ['Subject',    'Hello'],
            ['Date',       'Mon, 01 Jan 2024 12:00:00 +0000'],
            ['Message-ID', '<msg-001@example.com>'],
        ]), new Part\Text(new IO\MemoryHandle('Hello, Bob!')));

        self::assertNotNull($message->from);
        self::assertCount(1, $message->from);
        self::assertSame('alice@example.com', $message->from?->mailboxes()[0]?->address);

        self::assertNotNull($message->to);
        self::assertSame('bob@example.com', $message->to?->mailboxes()[0]?->address);

        self::assertSame('Hello', $message->subject);

        self::assertNotNull($message->messageId);
        self::assertSame('msg-001@example.com', $message->messageId->id);

        self::assertNotNull($message->date);
    }

    #[Test]
    public function missingHeadersYieldNull(): void
    {
        $message = new Message(Headers::fromPairs([]));

        self::assertNull($message->from);
        self::assertNull($message->sender);
        self::assertNull($message->to);
        self::assertNull($message->cc);
        self::assertNull($message->bcc);
        self::assertNull($message->replyTo);
        self::assertNull($message->date);
        self::assertNull($message->messageId);
        self::assertNull($message->subject);
        self::assertSame([], $message->references);
        self::assertSame([], $message->inReplyTo);
    }

    #[Test]
    public function parseSender(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Sender', 'admin@example.com'],
        ]));

        self::assertNotNull($message->sender);
        self::assertSame('admin@example.com', $message->sender->address);
    }

    #[Test]
    public function parseCcAndBcc(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Cc',  'cc1@example.com, cc2@example.com'],
            ['Bcc', 'bcc@example.com'],
        ]));

        self::assertNotNull($message->cc);
        self::assertCount(2, $message->cc);

        self::assertNotNull($message->bcc);
        self::assertCount(1, $message->bcc);
    }

    #[Test]
    public function parseReplyTo(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Reply-To', 'reply@example.com'],
        ]));

        self::assertNotNull($message->replyTo);
        self::assertSame('reply@example.com', $message->replyTo->mailboxes()[0]->address);
    }

    #[Test]
    public function parseEncodedSubject(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Subject', '=?utf-8?B?SGVsbG8gV29ybGQ=?='],
        ]));

        self::assertSame('Hello World', $message->subject);
    }

    #[Test]
    public function parseReferences(): void
    {
        $message = new Message(Headers::fromPairs([
            ['References', '<ref1@example.com> <ref2@example.com>'],
        ]));

        $refs = $message->references;
        self::assertCount(2, $refs);
        self::assertSame('ref1@example.com', $refs[0]->id);
        self::assertSame('ref2@example.com', $refs[1]->id);
    }

    #[Test]
    public function parseInReplyTo(): void
    {
        $message = new Message(Headers::fromPairs([
            ['In-Reply-To', '<parent@example.com>'],
        ]));

        $inReplyTo = $message->inReplyTo;
        self::assertCount(1, $inReplyTo);
        self::assertSame('parent@example.com', $inReplyTo[0]->id);
    }

    #[Test]
    public function malformedDateReturnsNull(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Date', 'not a date'],
        ]));

        self::assertNull($message->date);
    }

    #[Test]
    public function malformedAddressReturnsNull(): void
    {
        $message = new Message(Headers::fromPairs([
            ['From', 'not-an-address'],
        ]));

        self::assertNull($message->from);
    }

    #[Test]
    public function headersPropertyReturnsOriginalHeaders(): void
    {
        $headers = Headers::fromPairs([
            ['X-Custom', 'value'],
        ]);

        $message = new Message($headers);

        self::assertSame('value', $message->headers->get('X-Custom'));
    }

    #[Test]
    public function malformedMessageIdReturnsNull(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Message-ID', ''],
        ]));

        self::assertNull($message->messageId);
    }

    #[Test]
    public function malformedSenderReturnsNull(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Sender', 'not-valid'],
        ]));

        self::assertNull($message->sender);
    }

    #[Test]
    public function emptyFromReturnsNull(): void
    {
        $message = new Message(Headers::fromPairs([
            ['From', ''],
        ]));

        self::assertNull($message->from);
    }

    #[Test]
    public function emptySenderReturnsNull(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Sender', ''],
        ]));

        self::assertNull($message->sender);
    }

    #[Test]
    public function emptyDateReturnsNull(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Date', ''],
        ]));

        self::assertNull($message->date);
    }

    #[Test]
    public function emptyMessageIdReturnsNull(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Message-ID', '  '],
        ]));

        self::assertNull($message->messageId);
    }

    #[Test]
    public function emptyReferencesReturnsEmpty(): void
    {
        $message = new Message(Headers::fromPairs([
            ['References', ''],
        ]));

        self::assertSame([], $message->references);
    }

    #[Test]
    public function emptyInReplyToReturnsEmpty(): void
    {
        $message = new Message(Headers::fromPairs([
            ['In-Reply-To', ''],
        ]));

        self::assertSame([], $message->inReplyTo);
    }

    #[Test]
    public function referencesWithNoAngleBracketsReturnsEmpty(): void
    {
        $message = new Message(Headers::fromPairs([
            ['References', 'no-brackets-here'],
        ]));

        self::assertSame([], $message->references);
    }

    #[Test]
    public function referencesSkipsMalformedIds(): void
    {
        $message = new Message(Headers::fromPairs([
            ['References', '<valid@example.com> <> <also-valid@example.com>'],
        ]));

        self::assertCount(2, $message->references);
        self::assertSame('valid@example.com', $message->references[0]->id);
        self::assertSame('also-valid@example.com', $message->references[1]->id);
    }

    #[Test]
    public function plainSubjectNotEncoded(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Subject', 'Plain ASCII subject'],
        ]));

        self::assertSame('Plain ASCII subject', $message->subject);
    }

    #[Test]
    public function nullSubjectReturnsNull(): void
    {
        $message = new Message(Headers::fromPairs([]));

        self::assertNull($message->subject);
    }

    #[Test]
    public function dateWithWhitespaceIsTrimmed(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Date', '  Mon, 01 Jan 2024 12:00:00 +0000  '],
        ]));

        self::assertNotNull($message->date);
    }

    #[Test]
    public function multipleInReplyTo(): void
    {
        $message = new Message(Headers::fromPairs([
            ['In-Reply-To', '<a@example.com> <b@example.com>'],
        ]));

        self::assertCount(2, $message->inReplyTo);
        self::assertSame('a@example.com', $message->inReplyTo[0]->id);
        self::assertSame('b@example.com', $message->inReplyTo[1]->id);
    }

    #[Test]
    public function emptyAddressListReturnsNull(): void
    {
        $message = new Message(Headers::fromPairs([
            ['To', ',,,'],
        ]));

        self::assertNull($message->to);
    }

    #[Test]
    public function malformedEncodedSubjectReturnsRawValue(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Subject', '=?utf-8?B?!!!invalid-base64!!!?='],
        ]));

        self::assertSame('=?utf-8?B?!!!invalid-base64!!!?=', $message->subject);
    }

    #[Test]
    public function withReferencesStoresAngleBracketForm(): void
    {
        $id1 = MessageId::parse('<ref1@example.com>');
        $id2 = MessageId::parse('<ref2@example.com>');
        $message = new Message();
        $message = $message->withReferences([$id1, $id2]);

        static::assertCount(2, $message->references);
        static::assertSame('ref1@example.com', $message->references[0]->id);
        static::assertSame('ref2@example.com', $message->references[1]->id);
        $refHeader = $message->headers->get('References');
        static::assertNotNull($refHeader);
        static::assertStringContainsString('<ref1@example.com>', $refHeader);
        static::assertStringContainsString('<ref2@example.com>', $refHeader);
    }

    #[Test]
    public function withInReplyToStoresAngleBracketForm(): void
    {
        $id1 = MessageId::parse('<parent@example.com>');
        $id2 = MessageId::parse('<other@example.com>');
        $message = new Message();
        $message = $message->withInReplyTo([$id1, $id2]);

        static::assertCount(2, $message->inReplyTo);
        static::assertSame('parent@example.com', $message->inReplyTo[0]->id);
        $header = $message->headers->get('In-Reply-To');
        static::assertNotNull($header);
        static::assertStringContainsString('<parent@example.com>', $header);
        static::assertStringContainsString('<other@example.com>', $header);
    }

    #[Test]
    public function withReferencesEmptyRemovesHeader(): void
    {
        $id = MessageId::parse('<ref@example.com>');
        $message = new Message();
        $message = $message->withReferences([$id]);
        static::assertNotNull($message->headers->get('References'));

        $message = $message->withReferences([]);
        static::assertNull($message->headers->get('References'));
    }

    #[Test]
    public function withInReplyToEmptyRemovesHeader(): void
    {
        $id = MessageId::parse('<reply@example.com>');
        $message = new Message();
        $message = $message->withInReplyTo([$id]);
        static::assertNotNull($message->headers->get('In-Reply-To'));

        $message = $message->withInReplyTo([]);
        static::assertNull($message->headers->get('In-Reply-To'));
    }

    #[Test]
    public function dateWithWhitespaceIsParsedCorrectly(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Date', '   Mon, 01 Jan 2024 12:00:00 +0000   '],
        ]));

        static::assertNotNull($message->date);
    }

    #[Test]
    public function referencesWithNoAngleBracketsReturnsEmptyArray(): void
    {
        $message = new Message(Headers::fromPairs([
            ['References', 'plain-text-no-brackets'],
        ]));

        static::assertSame([], $message->references);
    }

    #[Test]
    public function withInReplyToHeaderContainsAngleBracketsFromToString(): void
    {
        $id = MessageId::parse('<reply-id@example.com>');
        $message = new Message()->withInReplyTo([$id]);

        $header = $message->headers->get('In-Reply-To');
        static::assertSame('<reply-id@example.com>', $header);
    }

    #[Test]
    public function withInReplyToMultipleIdsJoinedWithAngleBrackets(): void
    {
        $id1 = MessageId::parse('<first@example.com>');
        $id2 = MessageId::parse('<second@example.com>');
        $message = new Message()->withInReplyTo([$id1, $id2]);

        $header = $message->headers->get('In-Reply-To');
        static::assertSame('<first@example.com> <second@example.com>', $header);
    }

    #[Test]
    public function withInReplyToUsesToStringNotRawId(): void
    {
        $id = MessageId::parse('bare@example.com');
        $message = new Message()->withInReplyTo([$id]);

        $header = $message->headers->get('In-Reply-To');
        static::assertNotSame('bare@example.com', $header);
        static::assertSame('<bare@example.com>', $header);
    }

    #[Test]
    public function dateWithLeadingWhitespaceOnlyIsParsedViaTrim(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Date', '  Mon, 01 Jan 2024 12:00:00 +0000'],
        ]));

        static::assertNotNull($message->date);
    }

    #[Test]
    public function dateWithTrailingWhitespaceOnlyIsParsedViaTrim(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Date', 'Mon, 01 Jan 2024 12:00:00 +0000  '],
        ]));

        static::assertNotNull($message->date);
    }

    #[Test]
    public function dateWithTabWhitespaceIsTrimmed(): void
    {
        $message = new Message(Headers::fromPairs([
            ['Date', "\tMon, 01 Jan 2024 12:00:00 +0000\t"],
        ]));

        static::assertNotNull($message->date);
    }

    #[Test]
    public function dateWithFoldingWhitespaceIsTrimmed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Message(Headers::fromPairs([
            ['Date', "\r\n Mon, 01 Jan 2024 12:00:00 +0000\r\n"],
        ]));
    }

    #[Test]
    #[DataProvider('provideLeadingControlCharacters')]
    public function dateWithLeadingControlCharactersIsNotTrimmed(string $prefix): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Message(Headers::fromPairs([
            ['Date', "{$prefix}Mon, 01 Jan 2024 12:00:00 +0000"],
        ]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLeadingControlCharacters(): iterable
    {
        yield 'NUL byte' => ["\0"];
        yield 'form feed then NUL byte' => ["\x0C\0"];
        yield 'vertical tab then NUL byte' => ["\x0B\0"];
    }

    #[Test]
    public function parseMessageIdListWithNoMatchesReturnsEmptyNotNull(): void
    {
        $message = new Message(Headers::fromPairs([
            ['References', 'no-angle-brackets-at-all'],
        ]));

        $refs = $message->references;
        static::assertIsArray($refs);
        static::assertCount(0, $refs);
    }

    #[Test]
    public function parseMessageIdListWithEmptyBracketsResultIsArray(): void
    {
        $message = new Message(Headers::fromPairs([
            ['In-Reply-To', 'completely-plain-text'],
        ]));

        static::assertSame([], $message->inReplyTo);
    }

    #[Test]
    public function parseMessageIdListNoAngleBracketsIsEmptyArrayType(): void
    {
        $message = new Message(Headers::fromPairs([
            ['References', 'abc def ghi'],
        ]));

        $result = $message->references;
        static::assertSame([], $result);
        static::assertNotNull($result);
    }

    #[Test]
    public function withReferencesHeaderUsesToStringForEachId(): void
    {
        $id1 = MessageId::parse('<ref-a@example.com>');
        $id2 = MessageId::parse('<ref-b@example.com>');
        $message = new Message()->withReferences([$id1, $id2]);

        $header = $message->headers->get('References');
        static::assertSame('<ref-a@example.com> <ref-b@example.com>', $header);
    }

    #[Test]
    public function withReferencesHeaderContainsAngleBrackets(): void
    {
        $id = MessageId::parse('bare-id@example.com');
        $message = new Message()->withReferences([$id]);

        $header = $message->headers->get('References');
        static::assertNotNull($header);
        static::assertSame('<bare-id@example.com>', $header);
    }

    #[Test]
    public function withReferencesSingleIdProducesCorrectHeader(): void
    {
        $id = MessageId::parse('<single@example.com>');
        $message = new Message()->withReferences([$id]);

        $header = $message->headers->get('References');
        static::assertNotNull($header);
        static::assertStringStartsWith('<', $header);
        static::assertStringEndsWith('>', $header);
        static::assertSame('<single@example.com>', $header);
    }
}
