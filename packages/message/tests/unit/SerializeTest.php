<?php

declare(strict_types=1);

namespace Psl\Message\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psl\IO;
use Psl\Message;
use Psl\MIME\Headers;
use Psl\MIME\Part;

use function explode;
use function str_repeat;
use function strlen;

final class SerializeTest extends TestCase
{
    #[Test]
    public function serializeSimpleTextMessage(): void
    {
        $message = new Message\Message()
            ->withFrom(Message\Address\AddressList::parse('alice@example.com'))
            ->withTo(Message\Address\AddressList::parse('bob@example.com'))
            ->withSubject('Hello')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello, Bob!')));

        $result = Message\serialize($message)->readAll();

        self::assertStringContainsString("From: alice@example.com\r\n", $result);
        self::assertStringContainsString("To: bob@example.com\r\n", $result);
        self::assertStringContainsString("Subject: Hello\r\n", $result);
        self::assertStringContainsString('Hello, Bob!', $result);
    }

    #[Test]
    public function serializeOmitsBlindRecipientsWithoutChangingEnvelope(): void
    {
        $message = new Message\Message()
            ->withFrom('alice@example.com')
            ->withTo('bob@example.com')
            ->withBcc('secret@example.com')
            ->withHeader('bCC', 'other@example.com')
            ->withHeader('rEsEnT-bCc', 'hidden@example.com');
        $envelope = Message\Envelope::fromMessage($message);

        $result = Message\serialize($message)->readAll();

        self::assertStringNotContainsString('secret@example.com', $result);
        self::assertStringNotContainsString('other@example.com', $result);
        self::assertStringNotContainsString('hidden@example.com', $result);
        self::assertStringContainsString("To: bob@example.com\r\n", $result);
        self::assertSame('secret@example.com', $message->headers->get('Bcc'));
        self::assertSame('secret@example.com', $envelope->recipients[1]->address);
    }

    #[Test]
    public function serializeEmptyBody(): void
    {
        $message = new Message\Message(Headers::fromPairs([
            ['Subject', 'Empty'],
        ]));

        $result = Message\serialize($message)->readAll();

        self::assertStringContainsString("Subject: Empty\r\n", $result);
    }

    #[Test]
    public function roundTripParseSerialize(): void
    {
        $message = new Message\Message()
            ->withFrom(Message\Address\AddressList::parse('alice@example.com'))
            ->withTo(Message\Address\AddressList::parse('bob@example.com'))
            ->withSubject('Hello')
            ->withContent(new Part\Text(new IO\MemoryHandle('Body here')));

        $serialized = Message\serialize($message)->readAll();
        $reparsed = Message\parse($serialized);

        self::assertSame($message->subject, $reparsed->subject);
        self::assertSame($message->from?->mailboxes()[0]?->address, $reparsed->from?->mailboxes()[0]?->address);
    }

    #[Test]
    public function serializeFoldsLongHeaders(): void
    {
        $longSubject = str_repeat('word ', 20);
        $message = new Message\Message()
            ->withSubject($longSubject)
            ->withContent(new Part\Text(new IO\MemoryHandle('')));

        $result = Message\serialize($message)->readAll();

        $lines = explode("\r\n", $result);
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            self::assertLessThanOrEqual(998, strlen($line));
        }
    }
}
