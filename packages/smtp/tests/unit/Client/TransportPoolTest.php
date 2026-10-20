<?php

declare(strict_types=1);

namespace Psl\SMTP\Tests\Unit\Client;

use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Message\Address\Mailbox;
use Psl\Message\Envelope;
use Psl\Message\Message;
use Psl\SMTP\Client\Connection;
use Psl\SMTP\Client\SendConfiguration;
use Psl\SMTP\Client\Transport;
use Psl\SMTP\Client\TransportConfiguration;
use Psl\SMTP\DeliverBy;
use Psl\SMTP\DeliverByMode;
use Psl\SMTP\Exception\TransmissionException;
use Psl\SMTP\Priority;
use Psl\SMTP\Security;
use Psl\TCP;
use ReflectionClass;
use ReflectionProperty;
use WeakMap;
use WeakReference;

use function gc_collect_cycles;
use function substr_count;

final class TransportPoolTest extends TestCase
{
    public function testRequireTlsSurvivesPoolReuse(): void
    {
        [$transport, $output] = $this->transport('REQUIRETLS', Security::TLS);
        $mailbox = new Mailbox('sender', 'example.com');
        $envelope = new Envelope($mailbox, [$mailbox]);
        $configuration = new SendConfiguration(requireTls: true);
        $transport->send($envelope, new Message(), $configuration);
        $transport->send($envelope, new Message(), $configuration);

        $output->seek(0);
        $commands = $output->readAll();
        static::assertSame(2, substr_count($commands, ' REQUIRETLS'));
        static::assertSame(1, substr_count($commands, 'EHLO '));
    }

    public function testSmtpUtf8SurvivesPoolReuse(): void
    {
        [$transport, $output] = $this->transport('SMTPUTF8');
        $mailbox = new Mailbox('δοκιμή', 'example.com');
        $envelope = new Envelope($mailbox, [$mailbox]);
        $transport->send($envelope, new Message());
        $transport->send($envelope, new Message());

        $output->seek(0);
        $commands = $output->readAll();
        static::assertSame(2, substr_count($commands, ' SMTPUTF8'));
        static::assertSame(1, substr_count($commands, 'EHLO '));
    }

    public function testRequireTlsRejectsPlaintextEvenWhenAdvertised(): void
    {
        [$transport, $output] = $this->transport('REQUIRETLS');
        $mailbox = new Mailbox('sender', 'example.com');

        try {
            $transport->send(
                new Envelope($mailbox, [$mailbox]),
                new Message(),
                new SendConfiguration(requireTls: true),
            );
            static::fail('REQUIRETLS must reject a plaintext session.');
        } catch (TransmissionException $e) {
            static::assertStringContainsString('TLS session', $e->getMessage());
            $output->seek(0);
            static::assertSame("EHLO localhost\r\n", $output->readAll());
        }
    }

    public function testRequireTlsKeepsExtensionOrder(): void
    {
        [$transport, $output] = $this->transport(
            "8BITMIME\r\n250-SMTPUTF8\r\n250-REQUIRETLS\r\n250-MT-PRIORITY\r\n250-DELIVERBY",
            Security::TLS,
        );
        $mailbox = new Mailbox('sender', 'example.com');
        $transport->send(
            new Envelope($mailbox, [$mailbox]),
            new Message(),
            new SendConfiguration(
                requireTls: true,
                priority: Priority::Urgent,
                deliverBy: new DeliverBy(Duration::hours(1), DeliverByMode::Return),
            ),
        );

        $output->seek(0);
        static::assertStringContainsString(
            "MAIL FROM:<sender@example.com> BODY=8BITMIME SMTPUTF8 REQUIRETLS MT-PRIORITY=4 BY=3600;R\r\n",
            $output->readAll(),
        );
    }

    public function testRestoresAllCapabilitiesAndSize(): void
    {
        $input = new IO\MemoryHandle(
            "250-server\r\n250-SIZE 12345\r\n250-AUTH PLAIN LOGIN\r\n250 CUSTOM value\r\n250 OK\r\n",
        );
        $stream = $this->createStub(TCP\StreamInterface::class);
        $stream->method('read')->willReturnCallback(static fn(): string => $input->read(1));
        new Connection($stream)->ehlo('localhost');
        $connection = new Connection($stream);

        static::assertSame('PLAIN LOGIN', $connection->getCapabilityValue('AUTH'));
        static::assertSame('value', $connection->getCapabilityValue('CUSTOM'));
        static::assertSame(12_345, $connection->maxSize);
        $connection->helo('localhost');
        $connection = new Connection($stream);
        static::assertFalse($connection->supportsCapability('AUTH'));
        static::assertNull($connection->maxSize);
    }

    public function testCapabilityCacheDoesNotRetainStream(): void
    {
        $input = new IO\MemoryHandle("250-server\r\n250 SMTPUTF8\r\n");
        $stream = $this->createStub(TCP\StreamInterface::class);
        $stream->method('read')->willReturnCallback($input->read(...));
        $reference = WeakReference::create($stream);
        new Connection($stream)->ehlo('localhost');
        unset($stream);
        gc_collect_cycles();

        static::assertNull($reference->get());
    }

    /** @return array{Transport, IO\MemoryHandle} */
    private function transport(string $capability, Security $security = Security::None): array
    {
        $transaction = "250 OK\r\n250 OK\r\n354 Go ahead\r\n250 OK\r\n250 OK\r\n";
        $input = new IO\MemoryHandle(
            "220 mail.example.com ESMTP\r\n250-mail.example.com\r\n250-{$capability}\r\n250 OK\r\n"
            . $transaction
            . $transaction,
        );
        $output = new IO\MemoryHandle();
        $stream = $this->createStub(TCP\StreamInterface::class);
        $stream->method('read')->willReturnCallback(static fn(): string => $input->read(1));
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
            new TransportConfiguration(security: $security),
        );
        new ReflectionProperty(Transport::class, 'authenticator')->setValue($transport, null);
        new ReflectionProperty(Transport::class, 'pool')->setValue($transport, $pool);
        new ReflectionProperty(Transport::class, 'initialized')->setValue($transport, new WeakMap());

        return [$transport, $output];
    }
}
