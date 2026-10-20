<?php

declare(strict_types=1);

namespace Psl\Message\Tests\Unit\Address;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Message;
use Psl\Message\Address\AddressList;
use Psl\Message\Address\Group;
use Psl\Message\Address\Mailbox;
use Psl\Message\Exception\InvalidMailboxException;

use function str_repeat;

final class InternationalDomainTest extends TestCase
{
    #[DataProvider('domains')]
    public function testHeaderDomain(string $domain, string $encoded): void
    {
        $mailbox = new Mailbox('sender', $domain);

        static::assertSame('sender@' . $encoded, $mailbox->toString());
        static::assertSame($domain, $mailbox->domain);
        static::assertSame('sender@' . $domain, $mailbox->address);
        static::assertSame($encoded, Mailbox::parse($mailbox->toString())->domain);
    }

    /** @return iterable<string, array{string, string}> */
    public static function domains(): iterable
    {
        yield 'U-label' => ['münchen.de', 'xn--mnchen-3ya.de'];
        yield 'uppercase' => ['BÜCHER.EXAMPLE', 'xn--bcher-kva.example'];
        yield 'NFC' => ["bu\u{0308}cher.example", 'xn--bcher-kva.example'];
        yield 'two U-labels' => ['bücher.münchen', 'xn--bcher-kva.xn--mnchen-3ya'];
        yield 'A-label' => ['xn--bcher-kva.example', 'xn--bcher-kva.example'];
        yield 'ASCII' => ['MAIL.EXAMPLE', 'mail.example'];
        yield '63 bytes' => [str_repeat('a', 63) . '.example', str_repeat('a', 63) . '.example'];
        yield '63 encoded bytes' => [str_repeat('ü', 57) . '.example', 'xn--td' . str_repeat('a', 57) . '.example'];
        yield 'domain literal' => ['[IPv6:::1]', '[IPv6:::1]'];
    }

    public function testNamedMailboxAndGroupHeaders(): void
    {
        $mailbox = new Mailbox('sender', 'münchen.de', 'Doe, Alice');
        $named = '"Doe, Alice" <sender@xn--mnchen-3ya.de>';
        $group = new Group('Team', [$mailbox]);
        $list = AddressList::of($group);

        static::assertSame($named, $mailbox->toString());
        static::assertSame(
            'Alice <sender@xn--mnchen-3ya.de>',
            new Mailbox('sender', 'münchen.de', 'Alice')->toString(),
        );
        static::assertSame('Team: ' . $named . ';', $group->toString());
        static::assertSame($group->toString(), $list->toString());

        $message = new Message\Message()
            ->withFrom($mailbox)
            ->withTo($list);
        $wire = Message\serialize($message)->readAll();
        static::assertStringContainsString('From: ' . $named . "\r\n", $wire);
        static::assertStringContainsString('To: Team: ' . $named . ";\r\n", $wire);
    }

    public function testQuotedAsciiLocalPart(): void
    {
        static::assertSame('"sender@home"@xn--mnchen-3ya.de', new Mailbox('"sender@home"', 'münchen.de')->toString());
    }

    public function testUtf8LocalPartKeepsUtf8Address(): void
    {
        static::assertSame('josé@münchen.de', new Mailbox('josé', 'münchen.de')->toString());
        static::assertSame('"josé"@münchen.de', new Mailbox('"josé"', 'münchen.de')->toString());
    }

    #[DataProvider('longDomains')]
    public function testRejectsLongLabels(string $domain): void
    {
        $mailbox = new Mailbox('sender', $domain);
        $this->expectException(InvalidMailboxException::class);

        $mailbox->toString();
    }

    /** @return iterable<string, array{string}> */
    public static function longDomains(): iterable
    {
        yield 'ASCII' => [str_repeat('a', 64) . '.example'];
        yield 'encoded Unicode' => [str_repeat('ü', 58) . '.example'];
    }
}
