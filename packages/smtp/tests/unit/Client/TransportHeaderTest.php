<?php

declare(strict_types=1);

namespace Psl\SMTP\Tests\Unit\Client;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\IO;
use Psl\Message\Address\Mailbox;
use Psl\Message\Envelope;
use Psl\Message\Message;
use Psl\MIME\Headers;
use Psl\MIME\Part;
use Psl\MIME\TransferEncoding;
use Psl\SMTP\Client\Transport;
use Psl\SMTP\Client\TransportConfiguration;
use Psl\SMTP\Exception\TransmissionException;
use Psl\SMTP\Security;
use Psl\TCP;
use ReflectionClass;
use ReflectionProperty;
use WeakMap;

use function str_repeat;

final class TransportHeaderTest extends TestCase
{
    #[DataProvider('utf8Headers')]
    public function testRejectsUtf8HeadersBeforeMail(string $name, string $value): void
    {
        [$transport, $output] = $this->transport('OK');
        $mailbox = new Mailbox('sender', 'example.com');

        try {
            $transport->send(new Envelope($mailbox, [$mailbox]), new Message()->withHeader($name, $value));
            static::fail('UTF-8 headers need SMTPUTF8 even with an ASCII envelope.');
        } catch (TransmissionException $e) {
            static::assertStringContainsString('SMTPUTF8', $e->getMessage());
            $output->seek(0);
            static::assertSame("EHLO localhost\r\n", $output->readAll());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function utf8Headers(): iterable
    {
        yield 'mailbox local part' => ['From', 'josé@example.com'];
        yield 'mailbox domain' => ['To', 'user@bücher.example'];
        yield 'raw subject' => ['Subject', 'café'];
    }

    public function testRejectsUtf8MimeHeaders(): void
    {
        [$transport, $output] = $this->transport('OK');
        $mailbox = new Mailbox('sender', 'example.com');
        $part = $this->createStub(Part\PartInterface::class);
        $part->method('$headers::get')->willReturn(Headers::fromPairs([['X-Part', 'café']]));
        $part->method('body')->willReturn(new IO\MemoryHandle());

        try {
            $transport->send(new Envelope($mailbox, [$mailbox]), new Message()->withContent($part));
            static::fail('Transmitted MIME headers need SMTPUTF8.');
        } catch (TransmissionException) {
            $output->seek(0);
            static::assertSame("EHLO localhost\r\n", $output->readAll());
        }
    }

    #[DataProvider('nestedHeaders')]
    public function testRejectsNestedUtf8Headers(string $contentType, string $body): void
    {
        [$transport, $output] = $this->transport('OK');
        $mailbox = new Mailbox('sender', 'example.com');
        $part = new Part\Part(Headers::fromPairs([['Content-Type', $contentType]]), new IO\MemoryHandle($body));

        try {
            $transport->send(new Envelope($mailbox, [$mailbox]), new Message()->withContent($part));
            static::fail('Nested raw UTF-8 headers need SMTPUTF8.');
        } catch (TransmissionException) {
            $output->seek(0);
            static::assertSame("EHLO localhost\r\n", $output->readAll());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function nestedHeaders(): iterable
    {
        yield 'multipart' => [
            'multipart/mixed; boundary=outer',
            "--outer\r\nX-Part: café\r\n\r\ntext\r\n--outer--\r\n",
        ];
        yield 'raw invalid field name' => [
            'multipart/mixed; boundary=outer',
            "--outer\r\nBad Name: café\r\n\r\ntext\r\n--outer--\r\n",
        ];
        yield 'nested multipart' => [
            'multipart/mixed; boundary=outer',
            "--outer\r\nContent-Type: multipart/mixed; boundary=inner\r\n\r\n--inner\r\nX-Part: café\r\n\r\ntext\r\n--inner--\r\n--outer--\r\n",
        ];
        yield 'message' => ['message/rfc822', "From: josé@example.com\r\n\r\ntext"];
        yield 'nested message' => [
            'multipart/mixed; boundary=outer',
            "--outer\r\nContent-Type: message/rfc822\r\n\r\nFrom: josé@example.com\r\n\r\ntext\r\n--outer--\r\n",
        ];
        yield 'digest default' => [
            'multipart/digest; boundary=outer',
            "--outer\r\n\r\nFrom: josé@example.com\r\n\r\ntext\r\n--outer--\r\n",
        ];
        yield 'digest header without content type' => [
            'multipart/digest; boundary=outer',
            "--outer\r\nContent-Disposition: inline\r\n\r\nFrom: josé@example.com\r\n\r\ntext\r\n--outer--\r\n",
        ];
    }

    #[DataProvider('asciiStructuredBodies')]
    public function testKeepsAsciiStructuredBodies(string $contentType, null|string $encoding, string $body): void
    {
        [$transport, $output] = $this->transport('OK');
        $mailbox = new Mailbox('sender', 'example.com');
        $headers = Headers::fromPairs([['Content-Type', $contentType]]);
        if ($encoding !== null) {
            $headers = $headers->with('Content-Transfer-Encoding', $encoding);
        }

        $part = new Part\Part($headers, new IO\MemoryHandle($body));
        $transport->send(new Envelope($mailbox, [$mailbox]), new Message()->withContent($part));

        $output->seek(0);
        $commands = $output->readAll();
        static::assertStringNotContainsString('SMTPUTF8', $commands);
        static::assertStringContainsString($body, $commands);
    }

    /** @return iterable<string, array{string, null|string, string}> */
    public static function asciiStructuredBodies(): iterable
    {
        yield 'empty part headers' => [
            'multipart/mixed; boundary=outer',
            null,
            "--outer\r\n\r\nASCII body\r\n--outer--\r\n",
        ];
        yield 'invalid ASCII field' => [
            'multipart/mixed; boundary=outer',
            null,
            "--outer\r\nBad Name: text\r\n\r\nASCII body\r\n--outer--\r\n",
        ];
        yield 'invalid ASCII content type' => [
            'multipart/mixed; boundary=outer',
            null,
            "--outer\r\nContent-Type: invalid\r\n\r\nASCII body\r\n--outer--\r\n",
        ];
        yield 'partial message' => ['message/partial; id=test; number=2', null, "ASCII fragment\r\n\r\nbody"];
        yield 'base64 global message' => [
            'message/global',
            'base64',
            "RnJvbTogam9zw6lAZXhhbXBsZS5jb20NCg0KdGV4dA==\r\n\r\n",
        ];
        yield 'nested base64 global message' => [
            'multipart/mixed; boundary=outer',
            null,
            "--outer\r\nContent-Type: message/global\r\nContent-Transfer-Encoding: base64\r\n\r\nRnJvbTogam9zw6lAZXhhbXBsZS5jb20NCg0KdGV4dA==\r\n\r\n--outer--\r\n",
        ];
    }

    public function testKeepsMultipartBodyBytesAndIgnoresUtf8BodyText(): void
    {
        [$transport, $output] = $this->transport('8BITMIME');
        $mailbox = new Mailbox('sender', 'example.com');
        $body = "--outer\r\nContent-Type: text/plain\r\n\r\n" . str_repeat('café', 500_000) . "\r\n--outer--\r\n";
        $part = new Part\Part(Headers::fromPairs([[
            'Content-Type',
            'multipart/mixed; boundary=outer',
        ]]), new IO\MemoryHandle($body));
        $transport->send(new Envelope($mailbox, [$mailbox]), new Message()->withContent($part));

        $output->seek(0);
        $commands = $output->readAll();
        static::assertStringNotContainsString('SMTPUTF8', $commands);
        static::assertStringContainsString($body, $commands);
    }

    #[DataProvider('transfers')]
    public function testSendsUtf8HeadersWhenSupported(bool $chunking): void
    {
        [$transport, $output] = $this->transport('SMTPUTF8', $chunking);
        $mailbox = new Mailbox('sender', 'example.com');
        $transport->send(new Envelope($mailbox, [$mailbox]), new Message()->withHeader('From', 'josé@example.com'));

        $output->seek(0);
        $commands = $output->readAll();
        static::assertStringContainsString("MAIL FROM:<sender@example.com> SMTPUTF8\r\n", $commands);
        static::assertStringContainsString("From: josé@example.com\r\n", $commands);
    }

    /** @return iterable<string, array{bool}> */
    public static function transfers(): iterable
    {
        yield 'DATA' => [false];
        yield 'BDAT' => [true];
    }

    public function testOnlyTransmittedHeadersNeedSmtpUtf8(): void
    {
        [$transport, $output] = $this->transport('8BITMIME');
        $mailbox = new Mailbox('sender', 'example.com');
        $message = new Message()
            ->withHeader('Bcc', 'josé@example.com')
            ->withHeader('Resent-Bcc', 'josé@example.com')
            ->withSubject('café')
            ->withHeader('Content-Type', 'text/plain; ignored=café')
            ->withContent(new Part\Text(new IO\MemoryHandle('UTF-8 body: café'), encoding: TransferEncoding::EightBit));
        $transport->send(new Envelope($mailbox, [$mailbox]), $message);

        $output->seek(0);
        $commands = $output->readAll();
        static::assertStringNotContainsString('SMTPUTF8', $commands);
        static::assertStringNotContainsString('josé', $commands);
        static::assertStringContainsString('Subject: =?UTF-8?', $commands);
        static::assertStringContainsString("\r\n\r\nUTF-8 body: café\r\n.\r\n", $commands);
    }

    /** @return array{Transport, IO\MemoryHandle} */
    private function transport(string $capability, bool $chunking = false): array
    {
        $capabilities = $chunking ? "250-{$capability}\r\n250 CHUNKING" : "250 {$capability}";
        $input = new IO\MemoryHandle(
            "220 mail.example.com ESMTP\r\n250-mail.example.com\r\n{$capabilities}\r\n"
            . "250 OK\r\n250 OK\r\n"
            . ($chunking ? '' : "354 Go ahead\r\n")
            . "250 OK\r\n250 OK\r\n"
            . ($chunking ? "250 OK\r\n" : ''),
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
            new TransportConfiguration(security: Security::None),
        );
        new ReflectionProperty(Transport::class, 'authenticator')->setValue($transport, null);
        new ReflectionProperty(Transport::class, 'pool')->setValue($transport, $pool);
        new ReflectionProperty(Transport::class, 'initialized')->setValue($transport, new WeakMap());

        return [$transport, $output];
    }
}
