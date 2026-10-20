<?php

declare(strict_types=1);

namespace Psl\SMTP\Tests\Unit\Client;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\IO;
use Psl\Message\Address\Mailbox;
use Psl\Message\Envelope;
use Psl\Message\Message;
use Psl\SMTP\Client\SendConfiguration;
use Psl\SMTP\Client\Transport;
use Psl\SMTP\Client\TransportConfiguration;
use Psl\SMTP\Exception\TransmissionException;
use Psl\SMTP\Security;
use Psl\TCP;
use ReflectionClass;
use ReflectionProperty;
use WeakMap;

use function str_repeat;

final class TransportSafetyTest extends TestCase
{
    public function testRequireTlsFailsBeforeMailWithoutServerSupport(): void
    {
        [$transport, $output] = $this->transport('OK');
        $envelope = new Envelope(new Mailbox('sender', 'example.com'), [new Mailbox('recipient', 'example.com')]);

        try {
            $transport->send($envelope, new Message(), new SendConfiguration(requireTls: true));
            static::fail('Sending must fail when REQUIRETLS is unavailable.');
        } catch (TransmissionException $e) {
            static::assertStringContainsString('REQUIRETLS', $e->getMessage());
            $output->seek(0);
            static::assertSame("EHLO localhost\r\n", $output->readAll());
        }
    }

    public function testRequireTlsIsSentWhenSupported(): void
    {
        [$transport, $output] = $this->transport('REQUIRETLS');
        $envelope = new Envelope(new Mailbox('sender', 'example.com'), [new Mailbox('recipient', 'example.com')]);

        $transport->send($envelope, new Message(), new SendConfiguration(requireTls: true));

        $output->seek(0);
        static::assertStringContainsString("MAIL FROM:<sender@example.com> REQUIRETLS\r\n", $output->readAll());
    }

    #[DataProvider('internationalizedAddresses')]
    public function testEncodesDomainLabels(string $local, string $domain, string $encoded): void
    {
        [$transport, $output] = $this->transport('OK');
        $mailbox = new Mailbox($local, $domain);

        $message = new Message()
            ->withFrom($mailbox)
            ->withTo($mailbox);
        $transport->send(new Envelope($mailbox, [$mailbox]), $message);

        $output->seek(0);
        $commands = $output->readAll();
        static::assertStringContainsString("MAIL FROM:<{$encoded}>\r\n", $commands);
        static::assertStringContainsString("RCPT TO:<{$encoded}>\r\n", $commands);
        static::assertStringContainsString("From: {$encoded}\r\n", $commands);
        static::assertStringContainsString("To: {$encoded}\r\n", $commands);
        static::assertStringNotContainsString('SMTPUTF8', $commands);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function internationalizedAddresses(): iterable
    {
        yield 'one Unicode label' => ['user', 'bücher.example', 'user@xn--bcher-kva.example'];
        yield 'uppercase Unicode' => ['user', 'BÜCHER.EXAMPLE', 'user@xn--bcher-kva.example'];
        yield 'decomposed Unicode' => ['user', "bu\u{0308}cher.example", 'user@xn--bcher-kva.example'];
        yield 'two Unicode labels' => ['user', 'bücher.münchen', 'user@xn--bcher-kva.xn--mnchen-3ya'];
        yield 'maximum ASCII label' => [
            'user',
            str_repeat('a', 63) . '.example',
            'user@' . str_repeat('a', 63) . '.example',
        ];
        yield 'ASCII domain' => ['user', 'mail.example.com', 'user@mail.example.com'];
        yield 'A-label' => ['user', 'xn--bcher-kva.example', 'user@xn--bcher-kva.example'];
        yield 'quoted local at' => ['"user@home"', 'bücher.example', '"user@home"@xn--bcher-kva.example'];
        yield 'domain literal' => ['user', '[IPv6:::1]', 'user@[IPv6:::1]'];
    }

    #[DataProvider('overlongDomains')]
    public function testRejectsLongDomainLabelsBeforeMail(string $domain): void
    {
        [$transport, $output] = $this->transport('OK');
        $mailbox = new Mailbox('sender', $domain);

        try {
            $transport->send(new Envelope($mailbox, [$mailbox]), new Message());
            static::fail('SMTP domain labels must fit in 63 bytes.');
        } catch (TransmissionException $e) {
            static::assertStringContainsString('domain', $e->getMessage());
            $output->seek(0);
            static::assertSame("EHLO localhost\r\n", $output->readAll());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function overlongDomains(): iterable
    {
        yield 'ASCII' => [str_repeat('a', 64) . '.example'];
        yield 'encoded Unicode' => [str_repeat('ü', 58) . '.example'];
    }

    #[DataProvider('utf8LocalParts')]
    public function testRejectsUtf8LocalPartsBeforeMail(Mailbox $sender, Mailbox $recipient): void
    {
        [$transport, $output] = $this->transport('OK');

        try {
            $message = new Message()
                ->withFrom($sender)
                ->withTo($recipient);
            $transport->send(new Envelope($sender, [$recipient]), $message);
            static::fail('Sending an international local part must require SMTPUTF8.');
        } catch (TransmissionException $e) {
            static::assertStringContainsString('SMTPUTF8', $e->getMessage());
            $output->seek(0);
            static::assertSame("EHLO localhost\r\n", $output->readAll());
        }
    }

    /** @return iterable<string, array{Mailbox, Mailbox}> */
    public static function utf8LocalParts(): iterable
    {
        yield 'sender' => [new Mailbox('δοκιμή', 'example.com'), new Mailbox('recipient', 'example.com')];
        yield 'recipient' => [new Mailbox('sender', 'example.com'), new Mailbox('δοκιμή', 'example.com')];
        yield 'quoted recipient' => [new Mailbox('sender', 'example.com'), new Mailbox('"δοκιμή"', 'example.com')];
    }

    public function testSendsUtf8LocalPartsWhenSupported(): void
    {
        [$transport, $output] = $this->transport('SMTPUTF8');
        $sender = new Mailbox('δοκιμή', 'example.com');
        $recipient = new Mailbox('受信者', 'example.com');
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient);

        $transport->send(new Envelope($sender, [$recipient]), $message);

        $output->seek(0);
        $commands = $output->readAll();
        static::assertStringContainsString("MAIL FROM:<δοκιμή@example.com> SMTPUTF8\r\n", $commands);
        static::assertStringContainsString("RCPT TO:<受信者@example.com>\r\n", $commands);
    }

    /**
     * Use a scripted stream to test the full send path without loopback sockets.
     *
     * @return array{Transport, IO\MemoryHandle}
     */
    private function transport(string $capability): array
    {
        $input = new IO\MemoryHandle(
            "220 mail.example.com ESMTP\r\n250-mail.example.com\r\n250 {$capability}\r\n"
            . "250 OK\r\n250 OK\r\n354 Go ahead\r\n250 OK\r\n250 OK\r\n",
        );
        $output = new IO\MemoryHandle();
        $stream = $this->createStub(TCP\StreamInterface::class);
        $stream->method('read')->willReturnCallback($input->read(...));
        $stream
            ->method('writeAll')
            ->willReturnCallback(static function (string $data) use ($output): void {
                $output->writeAll($data);
            });
        $pool = $this->createStub(TCP\SocketPoolInterface::class);
        $pool->method('checkout')->willReturn($stream);

        $transport = new ReflectionClass(Transport::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(Transport::class, 'configuration')->setValue(
            $transport,
            new TransportConfiguration(security: Security::TLS),
        );
        new ReflectionProperty(Transport::class, 'authenticator')->setValue($transport, null);
        new ReflectionProperty(Transport::class, 'pool')->setValue($transport, $pool);
        new ReflectionProperty(Transport::class, 'initialized')->setValue($transport, new WeakMap());

        return [$transport, $output];
    }
}
