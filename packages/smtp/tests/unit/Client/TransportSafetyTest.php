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

        $transport->send(new Envelope($mailbox, [$mailbox]), new Message());

        $output->seek(0);
        $commands = $output->readAll();
        static::assertStringContainsString("MAIL FROM:<{$encoded}>\r\n", $commands);
        static::assertStringContainsString("RCPT TO:<{$encoded}>\r\n", $commands);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function internationalizedAddresses(): iterable
    {
        yield 'one Unicode label' => ['user', 'bücher.example', 'user@xn--bcher-kva.example'];
        yield 'two Unicode labels' => ['user', 'bücher.münchen', 'user@xn--bcher-kva.xn--mnchen-3ya'];
        yield 'ASCII domain' => ['user', 'mail.example.com', 'user@mail.example.com'];
        yield 'A-label' => ['user', 'xn--bcher-kva.example', 'user@xn--bcher-kva.example'];
        yield 'quoted local at' => ['"user@home"', 'bücher.example', '"user@home"@xn--bcher-kva.example'];
        yield 'domain literal' => ['user', '[IPv6:::1]', 'user@[IPv6:::1]'];
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
