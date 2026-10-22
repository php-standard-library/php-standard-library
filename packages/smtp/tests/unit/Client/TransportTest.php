<?php

declare(strict_types=1);

namespace Psl\SMTP\Tests\Unit\Client;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Iter;
use Psl\Message\Address\Mailbox;
use Psl\Message\Envelope;
use Psl\Message\Message;
use Psl\MIME\Part;
use Psl\Network;
use Psl\SMTP\Client\Authentication\PlainAuthenticator;
use Psl\SMTP\Client\SendConfiguration;
use Psl\SMTP\Client\Transport;
use Psl\SMTP\Client\TransportConfiguration;
use Psl\SMTP\DeliverBy;
use Psl\SMTP\DeliverByMode;
use Psl\SMTP\Exception\AuthenticationException;
use Psl\SMTP\Exception\ConnectionException;
use Psl\SMTP\Exception\ExceptionInterface;
use Psl\SMTP\Exception\ProtocolException;
use Psl\SMTP\Exception\TransmissionException;
use Psl\SMTP\Priority;
use Psl\SMTP\Security;
use Psl\Str\Byte;
use Psl\TCP;
use Throwable;
use UnhandledMatchError;

use function base64_decode;

use const PHP_OS_FAMILY;

/**
 * @mago-expect lint:kan-defect
 * @mago-expect lint:cyclomatic-complexity
 */
final class TransportTest extends TestCase
{
    /**
     * How long a test waits for something that should already have happened, in milliseconds.
     */
    private const int SETTLE_TIMEOUT_MILLISECONDS = 1000;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('SMTP transport tests are not supported on Windows.');
        }
    }

    public function testSendOverPlainConnection(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            $ehlo = self::readCommand($client);
            static::assertStringContainsString('EHLO', $ehlo);
            $client->writeAll("250-mail.example.com\r\n250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            $rset = self::readCommand($client);
            static::assertStringContainsString('RSET', $rset);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello World')));

        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithAuthentication(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 AUTH PLAIN LOGIN\r\n");

            $authCommand = self::readCommand($client);
            static::assertStringStartsWith('AUTH PLAIN ', Byte\trim($authCommand));

            $parts = Byte\split(Byte\trim($authCommand), ' ', 3);
            $decoded = base64_decode($parts[2], true);
            static::assertSame("\0testuser\0testpass", $decoded);

            $client->writeAll("235 Authentication successful\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $authenticator = new PlainAuthenticator('testuser', 'testpass');
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration, $authenticator);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Auth Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testMultipleSendsReuseConnection(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $ehloCount = 0;

        $future = Async\run(static function () use ($server, &$ehloCount): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            $ehlo = self::readCommand($client);
            if (Byte\contains($ehlo, 'EHLO')) {
                $ehloCount++;
            }

            $client->writeAll("250 mail.example.com\r\n");

            for ($i = 0; $i < 2; $i++) {
                self::readCommand($client);
                $client->writeAll("250 OK\r\n");

                self::readCommand($client);
                $client->writeAll("250 OK\r\n");

                self::readCommand($client);
                $client->writeAll("354 Go ahead\r\n");

                $data = '';
                while (!Byte\contains($data, "\r\n.\r\n")) {
                    $data .= $client->read();
                }

                $client->writeAll("250 OK\r\n");

                self::readCommand($client);
                $client->writeAll("250 OK\r\n");
            }

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');

        for ($i = 0; $i < 2; $i++) {
            $envelope = new Envelope($sender, [$recipient]);
            $message = new Message()
                ->withFrom($sender)
                ->withTo($recipient)
                ->withSubject('Message ' . $i)
                ->withContent(new Part\Text(new IO\MemoryHandle('Hello ' . $i)));

            $transport->send($envelope, $message);
        }

        $future->await();
        $transport->close();
        $server->close();

        static::assertSame(1, $ehloCount);
    }

    public function testConnectionRefused(): void
    {
        $configuration = new TransportConfiguration(host: '127.0.0.1', port: 1, security: Security::None);
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello World')));

        $this->expectException(ExceptionInterface::class);

        $transport->send($envelope, $message);
    }

    public function testBadGreeting(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("554 Service unavailable\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(ConnectionException::class);

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testEhloRejected(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("502 EHLO not supported\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(ProtocolException::class);

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testAuthenticationFailedClearsPooledConnection(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $connectCount = 0;

        $future = Async\run(static function () use ($server, &$connectCount): void {
            for ($i = 0; $i < 2; $i++) {
                $client = $server->accept();
                $connectCount++;

                $client->writeAll("220 mail.example.com ESMTP\r\n");

                self::readCommand($client);
                $client->writeAll("250-mail.example.com\r\n250 AUTH PLAIN\r\n");

                self::readCommand($client);
                $client->writeAll("535 Authentication failed\r\n");
                $client->close();
            }
        });

        $authenticator = new PlainAuthenticator('user', 'wrong');
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration, $authenticator);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        try {
            $transport->send($envelope, $message);
            static::fail('Expected AuthenticationException');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }

        try {
            $transport->send($envelope, $message);
            static::fail('Expected AuthenticationException');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }

        $future->await();
        $transport->close();
        $server->close();

        static::assertSame(2, $connectCount);
    }

    public function testTransmissionErrorClearsPooledConnection(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $connectCount = 0;

        $future = Async\run(static function () use ($server, &$connectCount): void {
            for ($i = 0; $i < 2; $i++) {
                $client = $server->accept();
                $connectCount++;

                $client->writeAll("220 mail.example.com ESMTP\r\n");

                self::readCommand($client);
                $client->writeAll("250 mail.example.com\r\n");

                self::readCommand($client);
                $client->writeAll("550 Sender rejected\r\n");
                $client->close();
            }
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        try {
            $transport->send($envelope, $message);
            static::fail('Expected TransmissionException');
        } catch (TransmissionException) {
            $this->addToAssertionCount(1);
        }

        try {
            $transport->send($envelope, $message);
            static::fail('Expected TransmissionException');
        } catch (TransmissionException) {
            $this->addToAssertionCount(1);
        }

        $future->await();
        $transport->close();
        $server->close();

        static::assertSame(2, $connectCount);
    }

    public function testMalformedGreeting(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("GARBAGE\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(ExceptionInterface::class);

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testServerClosesConnectionImmediately(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(ExceptionInterface::class);

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testSendWithPipelining(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 PIPELINING\r\n");

            $pipelined = '';
            $commandCount = 0;
            while ($commandCount < 3) {
                $pipelined .= $client->read();
                $commandCount = Iter\count(Byte\split($pipelined, "\r\n")) - 1;
            }

            static::assertStringContainsString('MAIL FROM:<sender@example.com>', $pipelined);
            static::assertStringContainsString('RCPT TO:<recipient@example.com>', $pipelined);
            static::assertStringContainsString('DATA', $pipelined);

            $client->writeAll("250 OK\r\n");
            $client->writeAll("250 OK\r\n");
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Pipeline Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithDSN(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 DSN\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('RET=FULL', $mailFrom);
            $client->writeAll("250 OK\r\n");

            $rcptTo = self::readCommand($client);
            static::assertStringContainsString('NOTIFY=SUCCESS', $rcptTo);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('DSN Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(dsnReturn: 'FULL', dsnNotify: 'SUCCESS');
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithMultipleRecipients(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $rcpt1 = self::readCommand($client);
            static::assertStringContainsString('RCPT TO:<alice@example.com>', $rcpt1);
            $client->writeAll("250 OK\r\n");

            $rcpt2 = self::readCommand($client);
            static::assertStringContainsString('RCPT TO:<bob@example.com>', $rcpt2);
            $client->writeAll("250 OK\r\n");

            $rcpt3 = self::readCommand($client);
            static::assertStringContainsString('RCPT TO:<carol@example.com>', $rcpt3);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipients = [
            new Mailbox('alice', 'example.com'),
            new Mailbox('bob', 'example.com'),
            new Mailbox('carol', 'example.com'),
        ];
        $envelope = new Envelope($sender, $recipients);

        $message = new Message()
            ->withFrom($sender)
            ->withTo(...$recipients)
            ->withSubject('Multi-recipient Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSenderRejectedThrowsTransmissionException(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");

            self::readCommand($client);
            $client->writeAll("550 Sender rejected\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(TransmissionException::class);

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testRecipientRejectedThrowsTransmissionException(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("550 User not found\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('unknown', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(TransmissionException::class);

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testDataRejectedThrowsTransmissionException(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("452 Insufficient storage\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(TransmissionException::class);

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testFinalDataResponseRejected(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("554 Message rejected\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(TransmissionException::class);

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testEhloFallbackToHelo(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com SMTP\r\n");

            $ehlo = self::readCommand($client);
            static::assertStringContainsString('EHLO', $ehlo);
            $client->writeAll("502 EHLO not implemented\r\n");

            $helo = self::readCommand($client);
            static::assertStringContainsString('HELO', $helo);
            $client->writeAll("250 mail.example.com\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('HELO Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWith8BitMIME(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 8BITMIME\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('BODY=8BITMIME', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('8BITMIME Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithDSNEnvelopeId(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 OK\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('ENVID=msg-001', $mailFrom);
            static::assertStringContainsString('RET=HDRS', $mailFrom);
            $client->writeAll("250 OK\r\n");

            $rcptTo = self::readCommand($client);
            static::assertStringContainsString('NOTIFY=FAILURE,DELAY', $rcptTo);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('DSN ENVID Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(dsnReturn: 'HDRS', dsnEnvelopeId: 'msg-001', dsnNotify: 'FAILURE,DELAY');
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithNullSender(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('MAIL FROM:<>', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope(null, [$recipient]);

        $message = new Message()
            ->withTo($recipient)
            ->withSubject('Bounce')
            ->withContent(new Part\Text(new IO\MemoryHandle('Bounce message')));

        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testPipeliningSenderRejected(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 PIPELINING\r\n");

            $pipelined = '';
            $commandCount = 0;
            while ($commandCount < 3) {
                $pipelined .= $client->read();
                $commandCount = Iter\count(Byte\split($pipelined, "\r\n")) - 1;
            }

            $client->writeAll("550 Sender rejected\r\n");
            $client->writeAll("503 Bad sequence\r\n");
            $client->writeAll("503 Bad sequence\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('badsender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Pipeline Reject')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(TransmissionException::class);

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testPipeliningRecipientRejected(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 PIPELINING\r\n");

            $pipelined = '';
            $commandCount = 0;
            while ($commandCount < 3) {
                $pipelined .= $client->read();
                $commandCount = Iter\count(Byte\split($pipelined, "\r\n")) - 1;
            }

            $client->writeAll("250 OK\r\n");
            $client->writeAll("550 User unknown\r\n");
            $client->writeAll("503 Bad sequence\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('unknown', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Pipeline Reject RCPT')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(TransmissionException::class);

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testRsetFailedThrowsProtocolException(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("421 Service not available\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(ProtocolException::class);

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testSendWithPipeliningMultipleRecipients(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 PIPELINING\r\n");

            $pipelined = '';
            $commandCount = 0;
            while ($commandCount < 4) {
                $pipelined .= $client->read();
                $commandCount = Iter\count(Byte\split($pipelined, "\r\n")) - 1;
            }

            static::assertStringContainsString('MAIL FROM:<sender@example.com>', $pipelined);
            static::assertStringContainsString('RCPT TO:<alice@example.com>', $pipelined);
            static::assertStringContainsString('RCPT TO:<bob@example.com>', $pipelined);
            static::assertStringContainsString('DATA', $pipelined);

            $client->writeAll("250 OK\r\n");
            $client->writeAll("250 OK\r\n");
            $client->writeAll("250 OK\r\n");
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $alice = new Mailbox('alice', 'example.com');
        $bob = new Mailbox('bob', 'example.com');
        $envelope = new Envelope($sender, [$alice, $bob]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($alice, $bob)
            ->withSubject('Pipeline Multi')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testCloseMultipleTimes(): void
    {
        $configuration = new TransportConfiguration(host: '127.0.0.1', port: 1, security: Security::None);
        $transport = new Transport($configuration);

        $transport->close();
        $transport->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithDSNNullValues(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringNotContainsString('RET=', $mailFrom);
            static::assertStringNotContainsString('ENVID=', $mailFrom);
            $client->writeAll("250 OK\r\n");

            $rcptTo = self::readCommand($client);
            static::assertStringNotContainsString('NOTIFY=', $rcptTo);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('DSN Null')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration();
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testBothEhloAndHeloFail(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com SMTP\r\n");

            self::readCommand($client);
            $client->writeAll("502 Not implemented\r\n");

            self::readCommand($client);
            $client->writeAll("550 Not allowed\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(ProtocolException::class);

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testSenderRejectedThrowsInNonPipeliningMode(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            self::readCommand($client);
            $client->writeAll("550 Sender denied\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('badsender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(TransmissionException::class);
        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testNullSenderAddressUsesEmptyAngleBrackets(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('MAIL FROM:<>', $mailFrom);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope(null, [$recipient]);
        $message = new Message()
            ->withTo($recipient)
            ->withSubject('Bounce')
            ->withContent(new Part\Text(new IO\MemoryHandle('Bounce message')));

        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testRecipientRejectedNonPartialThrows(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("550 User unknown\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('baduser', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(TransmissionException::class);
        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testPartialSuccessAllRejectedThrows(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("550 User unknown\r\n");
            self::readCommand($client);
            $client->writeAll("550 User unknown\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
            allowPartialSuccess: true,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipients = [
            new Mailbox('bad1', 'example.com'),
            new Mailbox('bad2', 'example.com'),
        ];
        $envelope = new Envelope($sender, $recipients);
        $message = new Message()
            ->withFrom($sender)
            ->withTo(...$recipients)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(TransmissionException::class);
        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testPartialSuccessSomeAccepted(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("550 User unknown\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
            allowPartialSuccess: true,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipients = [
            new Mailbox('good', 'example.com'),
            new Mailbox('bad', 'example.com'),
        ];
        $envelope = new Envelope($sender, $recipients);
        $message = new Message()
            ->withFrom($sender)
            ->withTo(...$recipients)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $report = $transport->send($envelope, $message);

        static::assertCount(1, $report->rejectedRecipients);

        $future->await();
        $transport->close();
        $server->close();
    }

    public function testRsetResponseChecksCode250(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("421 Service closing\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(ProtocolException::class);
        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testSendWithSMTPUTF8(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 SMTPUTF8\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('SMTPUTF8', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('SMTPUTF8 Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithoutSMTPUTF8WhenNotSupported(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 OK\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringNotContainsString('SMTPUTF8', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('No SMTPUTF8 Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithRETAndEightBitMIME(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 8BITMIME\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('BODY=8BITMIME', $mailFrom);
            static::assertStringContainsString('RET=FULL', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('RET+8BITMIME Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(dsnReturn: 'FULL');
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithoutRequireTlsWhenFalse(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 REQUIRETLS\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringNotContainsString(' REQUIRETLS', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('No REQUIRETLS Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(requireTls: false);
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithRequireTlsButNotSupported(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            try {
                $client->writeAll("220 mail.example.com ESMTP\r\n");
                self::readCommand($client);
                $client->writeAll("250-mail.example.com\r\n250 OK\r\n");
                static::assertSame('', $client->read());
            } finally {
                $client->close();
            }
        });
        $transport = new Transport(new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        ));
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        try {
            $transport->send($envelope, new Message(), new SendConfiguration(requireTls: true));
            static::fail('Sending must fail when REQUIRETLS is unavailable.');
        } catch (TransmissionException $e) {
            static::assertStringContainsString('REQUIRETLS', $e->getMessage());
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testSendWithMTPriority(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 MT-PRIORITY\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('MT-PRIORITY=0', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('MT-PRIORITY Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(priority: Priority::Normal);
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithNullPriorityNoPriorityParam(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 MT-PRIORITY\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringNotContainsString('MT-PRIORITY=', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('No Priority Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(priority: null);
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithPriorityButNotSupported(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 OK\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringNotContainsString('MT-PRIORITY', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Priority not supported')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(priority: Priority::Urgent);
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithDeliverBy(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 DELIVERBY\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('BY=', $mailFrom);
            static::assertStringContainsString(';R', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('DELIVERBY Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(deliverBy: new DeliverBy(Duration::hours(1), DeliverByMode::Return));
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithNullDeliverByNoByParam(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 DELIVERBY\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringNotContainsString('BY=', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('No DeliverBy Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(deliverBy: null);
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithDeliverByButNotSupported(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 OK\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringNotContainsString('BY=', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('DeliverBy not supported')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(deliverBy: new DeliverBy(Duration::hours(1)));
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithFutureReleaseHoldFor(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 FUTURERELEASE\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('HOLDFOR=', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('FUTURERELEASE Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(futureRelease: Duration::hours(2));
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithNullFutureReleaseNoHoldForParam(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 FUTURERELEASE\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringNotContainsString('HOLDFOR=', $mailFrom);
            static::assertStringNotContainsString('HOLDUNTIL=', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('No FutureRelease Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(futureRelease: null);
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testSendWithFutureReleaseButNotSupported(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 OK\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringNotContainsString('HOLDFOR=', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('FutureRelease not supported')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(futureRelease: Duration::hours(1));
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testMailFromParamsOutsideBrackets(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 8BITMIME\r\n");

            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('FROM:<sender@example.com>', $mailFrom);
            static::assertStringNotContainsString('8BITMIME>', $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Bracket Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testRcptToParamsOutsideBrackets(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $rcptTo = self::readCommand($client);
            static::assertStringContainsString('TO:<recipient@example.com>', $rcptTo);
            static::assertStringNotContainsString('SUCCESS>', $rcptTo);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('RCPT Bracket Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $config = new SendConfiguration(dsnNotify: 'SUCCESS');
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testDefaultPortForNoneSecurity(): void
    {
        $configuration = new TransportConfiguration(host: '127.0.0.1', port: null, security: Security::None);
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        try {
            $transport->send($envelope, $message);
        } catch (UnhandledMatchError) {
            static::fail('Match arm for Security::None was removed');
        } catch (ExceptionInterface) {
            $this->addToAssertionCount(1);
        } finally {
            $transport->close();
        }
    }

    public function testDefaultPortForStartTLS(): void
    {
        $configuration = new TransportConfiguration(host: '127.0.0.1', port: null, security: Security::StartTLS);
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        try {
            $transport->send($envelope, $message);
        } catch (UnhandledMatchError) {
            static::fail('Match arm for Security::StartTLS was removed');
        } catch (ExceptionInterface) {
            $this->addToAssertionCount(1);
        } finally {
            $transport->close();
        }
    }

    public function testDefaultPortForTLS(): void
    {
        $configuration = new TransportConfiguration(host: '127.0.0.1', port: null, security: Security::TLS);
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        try {
            $transport->send($envelope, $message);
        } catch (UnhandledMatchError) {
            static::fail('Match arm for Security::TLS was removed');
        } catch (ExceptionInterface) {
            $this->addToAssertionCount(1);
        } finally {
            $transport->close();
        }
    }

    public function testBothEhloAndHeloFailThrowsWithExpectedCode250(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com SMTP\r\n");

            self::readCommand($client);
            $client->writeAll("502 Not implemented\r\n");

            self::readCommand($client);
            $client->writeAll("550 Not allowed\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/250/');

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testSendWithIDNDomainEncodesAddress(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com ESMTP\r\n");

            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");

            $mailFrom = self::readCommand($client);
            static::assertSame("MAIL FROM:<sender@xn--mnchen-3ya.de>\r\n", $mailFrom);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");

            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");

            self::readCommand($client);
            $client->writeAll("250 OK\r\n");

            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', "m\u{00FC}nchen.de");
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('IDN Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();

        $this->addToAssertionCount(1);
    }

    public function testDoEhloFailureMustThrow(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $client->writeAll("220 mail.example.com SMTP\r\n");

            self::readCommand($client);
            $client->writeAll("502 Not implemented\r\n");

            self::readCommand($client);
            $client->writeAll("503 Bad sequence\r\n");
            $client->close();
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);

        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $this->expectException(ProtocolException::class);

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testPipeliningSenderRejectedActuallyThrows(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 PIPELINING\r\n");
            $pipelined = '';
            $commandCount = 0;
            while ($commandCount < 3) {
                $pipelined .= $client->read();
                $commandCount = Iter\count(Byte\split($pipelined, "\r\n")) - 1;
            }

            $client->writeAll("553 Sender not allowed\r\n");
            $client->writeAll("503 Bad sequence\r\n");
            $client->writeAll("503 Bad sequence\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('bad', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessageMatches('/bad@example\.com/');

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testPipeliningSenderRejectedExceptionContainsSenderAddress(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 PIPELINING\r\n");
            $pipelined = '';
            $commandCount = 0;
            while ($commandCount < 3) {
                $pipelined .= $client->read();
                $commandCount = Iter\count(Byte\split($pipelined, "\r\n")) - 1;
            }

            $client->writeAll("550 Sender rejected\r\n");
            $client->writeAll("503 Bad sequence\r\n");
            $client->writeAll("503 Bad sequence\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('someone', 'test.org');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Content')));
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessageMatches('/someone@test\.org/');

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testPipelineNullSenderRejectedUsesAngleBrackets(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 PIPELINING\r\n");
            $pipelined = '';
            $commandCount = 0;
            while ($commandCount < 3) {
                $pipelined .= $client->read();
                $commandCount = Iter\count(Byte\split($pipelined, "\r\n")) - 1;
            }

            $client->writeAll("550 Sender rejected\r\n");
            $client->writeAll("503 Bad sequence\r\n");
            $client->writeAll("503 Bad sequence\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope(null, [$recipient]);
        $message = new Message()
            ->withTo($recipient)
            ->withSubject('Bounce')
            ->withContent(new Part\Text(new IO\MemoryHandle('Bounce message')));
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessageMatches('/<>/');

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testPipelineRecipientRejectedThrowsContainsAddress(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 PIPELINING\r\n");
            $pipelined = '';
            $commandCount = 0;
            while ($commandCount < 3) {
                $pipelined .= $client->read();
                $commandCount = Iter\count(Byte\split($pipelined, "\r\n")) - 1;
            }

            $client->writeAll("250 OK\r\n");
            $client->writeAll("550 User unknown\r\n");
            $client->writeAll("503 Bad sequence\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('baduser', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessageMatches('/baduser@example\.com/');

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testPartialSuccessWithOneAcceptedDoesNotThrow(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("550 User unknown\r\n");
            self::readCommand($client);
            $client->writeAll("550 User unknown\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
            allowPartialSuccess: true,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipients = [
            new Mailbox('good', 'example.com'),
            new Mailbox('bad1', 'example.com'),
            new Mailbox('bad2', 'example.com'),
        ];
        $envelope = new Envelope($sender, $recipients);
        $message = new Message()
            ->withFrom($sender)
            ->withTo(...$recipients)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $report = $transport->send($envelope, $message);
        static::assertCount(2, $report->rejectedRecipients);
        $future->await();
        $transport->close();
        $server->close();
    }

    public function testPartialSuccessReportContainsRecipientMailbox(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("550 User unknown\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
            allowPartialSuccess: true,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $goodRecipient = new Mailbox('good', 'example.com');
        $badRecipient = new Mailbox('rejected', 'example.com');
        $envelope = new Envelope($sender, [$goodRecipient, $badRecipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($goodRecipient, $badRecipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $report = $transport->send($envelope, $message);
        static::assertCount(1, $report->rejectedRecipients);
        static::assertSame('rejected@example.com', $report->rejectedRecipients[0][0]->address);
        $future->await();
        $transport->close();
        $server->close();
    }

    public function testNonPipelineSenderRejectedThrowsWithAddress(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            self::readCommand($client);
            $client->writeAll("550 Sender rejected\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('blocked', 'test.org');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessageMatches('/blocked@test\.org/');

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testNonPipelineNullSenderRejectedUsesEmptyPath(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            self::readCommand($client);
            $client->writeAll("550 Sender rejected\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope(null, [$recipient]);
        $message = new Message()
            ->withTo($recipient)
            ->withSubject('Bounce')
            ->withContent(new Part\Text(new IO\MemoryHandle('Bounce message')));
        $this->expectException(TransmissionException::class);
        $this->expectExceptionMessageMatches('/<>/');

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testNonPipelinePartialSuccessReportIncludesMailbox(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("550 User unknown\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
            allowPartialSuccess: true,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $goodRecipient = new Mailbox('good', 'example.com');
        $badRecipient = new Mailbox('baduser', 'example.com');
        $envelope = new Envelope($sender, [$goodRecipient, $badRecipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($goodRecipient, $badRecipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $report = $transport->send($envelope, $message);
        static::assertCount(1, $report->rejectedRecipients);
        static::assertSame('baduser@example.com', $report->rejectedRecipients[0][0]->address);
        static::assertInstanceOf(Mailbox::class, $report->rejectedRecipients[0][0]);
        $future->await();
        $transport->close();
        $server->close();
    }

    public function testRsetFailureClearsPool(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $connectCount = 0;
        $future = Async\run(static function () use ($server, &$connectCount): void {
            for ($i = 0; $i < 2; $i++) {
                $client = $server->accept();
                $connectCount++;
                $client->writeAll("220 mail.example.com ESMTP\r\n");
                self::readCommand($client);
                $client->writeAll("250 mail.example.com\r\n");
                self::readCommand($client);
                $client->writeAll("250 OK\r\n");
                self::readCommand($client);
                $client->writeAll("250 OK\r\n");
                self::readCommand($client);
                $client->writeAll("354 Go ahead\r\n");
                $data = '';
                while (!Byte\contains($data, "\r\n.\r\n")) {
                    $data .= $client->read();
                }

                $client->writeAll("250 OK\r\n");
                self::readCommand($client);
                if ($i === 0) {
                    $client->writeAll("421 Service unavailable\r\n");
                } else {
                    $client->writeAll("250 OK\r\n");
                }

                $client->close();
            }
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        try {
            $transport->send($envelope, $message);
            static::fail('Expected ProtocolException');
        } catch (ProtocolException) {
            $this->addToAssertionCount(1);
        }

        $message2 = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test 2')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello 2')));
        $transport->send($envelope, $message2);
        $future->await();
        $transport->close();
        $server->close();
        static::assertSame(2, $connectCount);
    }

    public function testChunkingNotUsedWhenServerDoesNotAdvertise(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $dataCmd = self::readCommand($client);
            static::assertStringContainsString('DATA', $dataCmd);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
            chunking: true,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();
        $this->addToAssertionCount(1);
    }

    public function testPipelinePartialSuccessReportHasTwoElements(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 PIPELINING\r\n");
            $pipelined = '';
            $commandCount = 0;
            while ($commandCount < 4) {
                $pipelined .= $client->read();
                $commandCount = Iter\count(Byte\split($pipelined, "\r\n")) - 1;
            }

            $client->writeAll("250 OK\r\n");
            $client->writeAll("250 OK\r\n");
            $client->writeAll("550 User unknown\r\n");
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
            allowPartialSuccess: true,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $goodRecipient = new Mailbox('good', 'example.com');
        $badRecipient = new Mailbox('rejected', 'example.com');
        $envelope = new Envelope($sender, [$goodRecipient, $badRecipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($goodRecipient, $badRecipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $report = $transport->send($envelope, $message);
        static::assertCount(1, $report->rejectedRecipients);
        static::assertInstanceOf(Mailbox::class, $report->rejectedRecipients[0][0]);
        static::assertSame('rejected@example.com', $report->rejectedRecipients[0][0]->address);
        static::assertCount(2, $report->rejectedRecipients[0]);
        $future->await();
        $transport->close();
        $server->close();
    }

    public function testRsetFailureWithSpecificCodeThrowsProtocolException(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("500 Syntax error\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/250/');

        try {
            $transport->send($envelope, $message);
        } finally {
            $future->await();
            $transport->close();
            $server->close();
        }
    }

    public function testClosePoolAndResetInitialized(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $connectCount = 0;

        $future = Async\run(static function () use ($server, &$connectCount): void {
            for ($i = 0; $i < 2; $i++) {
                $client = $server->accept();
                $connectCount++;
                $client->writeAll("220 mail.example.com ESMTP\r\n");
                self::readCommand($client);
                $client->writeAll("250 mail.example.com\r\n");
                self::readCommand($client);
                $client->writeAll("250 OK\r\n");
                self::readCommand($client);
                $client->writeAll("250 OK\r\n");
                self::readCommand($client);
                $client->writeAll("354 Go ahead\r\n");
                $data = '';
                while (!Byte\contains($data, "\r\n.\r\n")) {
                    $data .= $client->read();
                }

                $client->writeAll("250 OK\r\n");
                self::readCommand($client);
                $client->writeAll("250 OK\r\n");
                $client->close();
            }
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');

        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test 1')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $transport->send($envelope, $message);
        $transport->close();

        $envelope2 = new Envelope($sender, [$recipient]);
        $message2 = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test 2')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello again')));
        $transport->send($envelope2, $message2);
        $future->await();
        $transport->close();
        $server->close();

        static::assertSame(2, $connectCount);
    }

    /**
     * A send still in progress when the transport closes gives its connection back to the pool it came from, which
     * has closed and so closes the connection, instead of leaving it open with nobody to close it.
     */
    public function testSendInProgressWhenTheTransportClosesClosesItsConnection(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $rsetReceived = new Async\Deferred();
        $rsetReply = new Async\Deferred();

        $serverSide = Async\run(static function () use ($server, $rsetReceived, $rsetReply): string {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);

            // The transport now waits for the reply to RSET, and closes meanwhile.
            $rsetReceived->complete(null);
            $rsetReply->getAwaitable()->await();
            $client->writeAll("250 OK\r\n");

            try {
                return $client->readAll(
                    null,
                    new Async\TimeoutCancellationToken(Duration::milliseconds(self::SETTLE_TIMEOUT_MILLISECONDS)),
                );
            } finally {
                $client->close();
            }
        });

        $transport = new Transport(new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        ));
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

        $sending = Async\run(static fn() => $transport->send(new Envelope($sender, [$recipient]), $message));

        $rsetReceived->getAwaitable()->await();
        $transport->close();
        $rsetReply->complete(null);

        $sending->await();
        static::assertSame('', $serverSide->await());

        $server->close();
    }

    public function testCloseResetsEhloState(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $ehloCount = 0;

        $future = Async\run(static function () use ($server, &$ehloCount): void {
            for ($i = 0; $i < 2; $i++) {
                $client = $server->accept();
                $client->writeAll("220 mail.example.com ESMTP\r\n");
                $cmd = self::readCommand($client);
                if (Byte\contains($cmd, 'EHLO')) {
                    $ehloCount++;
                }

                $client->writeAll("250 mail.example.com\r\n");
                self::readCommand($client);
                $client->writeAll("250 OK\r\n");
                self::readCommand($client);
                $client->writeAll("250 OK\r\n");
                self::readCommand($client);
                $client->writeAll("354 Go ahead\r\n");
                $data = '';
                while (!Byte\contains($data, "\r\n.\r\n")) {
                    $data .= $client->read();
                }

                $client->writeAll("250 OK\r\n");
                self::readCommand($client);
                $client->writeAll("250 OK\r\n");
                $client->close();
            }
        });

        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');

        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $transport->send($envelope, $message);
        $transport->close();

        $envelope2 = new Envelope($sender, [$recipient]);
        $message2 = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test 2')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $transport->send($envelope2, $message2);
        $future->await();
        $transport->close();
        $server->close();

        static::assertSame(2, $ehloCount);
    }

    public function testEightBitAndSmtpUtf8BothAppended(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250-8BITMIME\r\n250 SMTPUTF8\r\n");
            $mailFrom = self::readCommand($client);
            static::assertStringContainsString(' BODY=8BITMIME SMTPUTF8', $mailFrom);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Both Ext')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();
        $this->addToAssertionCount(1);
    }

    public function testDeliverByExactSeconds(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 DELIVERBY\r\n");
            $mailFrom = self::readCommand($client);
            static::assertMatchesRegularExpression('/ BY=3600;R/', $mailFrom);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('BY Exact')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $config = new SendConfiguration(deliverBy: new DeliverBy(Duration::hours(1), DeliverByMode::Return));
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();
        $this->addToAssertionCount(1);
    }

    public function testDeliverByAppendedToOtherParams(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250-8BITMIME\r\n250 DELIVERBY\r\n");
            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('BODY=8BITMIME', $mailFrom);
            static::assertStringContainsString(' BY=3600;R', $mailFrom);
            $paramsPart = Byte\after($mailFrom, '>');
            static::assertNotNull($paramsPart);
            $trimmed = Byte\trim($paramsPart);
            static::assertStringStartsWith('BODY=8BITMIME', $trimmed);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('BY+8BIT')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $config = new SendConfiguration(deliverBy: new DeliverBy(Duration::hours(1), DeliverByMode::Return));
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();
        $this->addToAssertionCount(1);
    }

    public function testHoldForExactSeconds(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 FUTURERELEASE\r\n");
            $mailFrom = self::readCommand($client);
            static::assertMatchesRegularExpression('/ HOLDFOR=7200\r?\n/', $mailFrom);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('HOLDFOR Exact')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $config = new SendConfiguration(futureRelease: Duration::hours(2));
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();
        $this->addToAssertionCount(1);
    }

    public function testHoldForAppendedToOtherParams(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250-8BITMIME\r\n250 FUTURERELEASE\r\n");
            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('BODY=8BITMIME', $mailFrom);
            static::assertStringContainsString(' HOLDFOR=7200', $mailFrom);
            $paramsPart = Byte\after($mailFrom, '>');
            static::assertNotNull($paramsPart);
            $trimmed = Byte\trim($paramsPart);
            static::assertStringStartsWith('BODY=8BITMIME', $trimmed);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('HOLDFOR+8BIT')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $config = new SendConfiguration(futureRelease: Duration::hours(2));
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();
        $this->addToAssertionCount(1);
    }

    public function testAddressWithMultipleAtSigns(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250 mail.example.com\r\n");
            $mailFrom = self::readCommand($client);
            static::assertStringContainsString('FROM:<"user@name"@example.com>', $mailFrom);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('"user@name"', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('MultiAt')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $transport->send($envelope, $message);
        $future->await();
        $transport->close();
        $server->close();
        $this->addToAssertionCount(1);
    }

    public function testCancelledConnectionWrappedAsTimeout(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            try {
                $client = $server->accept();
                $client->close();
            } catch (Throwable $e) {
                unset($e);
            }
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $token = new Async\TimeoutCancellationToken(Duration::nanoseconds(1));
        Async\sleep(Duration::milliseconds(5));
        try {
            $transport->send($envelope, $message, cancellation: $token);
            static::fail('Expected exception');
        } catch (Async\Exception\CancelledException) {
            static::fail('CancelledException should be wrapped');
        } catch (ExceptionInterface) {
            $this->addToAssertionCount(1);
        } finally {
            $transport->close();
            $server->close();
            $future->await();
        }
    }

    public function testCancellingStalledStartTlsHandshakePassesThrough(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $token = new Async\SignalCancellationToken();
        $future = Async\run(static function () use ($server, $token): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 STARTTLS\r\n");
            static::assertStringContainsString('STARTTLS', self::readCommand($client));
            $client->writeAll("220 Ready to start TLS\r\n");

            // The ClientHello arrives: the client is in the handshake. Cancel it there, and never answer.
            $client->read();
            $token->cancel();

            // If the cancellation does not reach the handshake, close the stream after a while so that the handshake
            // fails instead of hanging the test.
            $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));
            try {
                while ('' !== $client->read(cancellation: $deadline)) {
                    // Discard the rest of the ClientHello until the client closes the connection.
                }
            } catch (Async\Exception\CancelledException) {
                $client->close();
            }
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::StartTLS,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        try {
            $transport->send($envelope, $message, cancellation: $token);
            static::fail('Expected the stalled STARTTLS handshake to be cancelled.');
        } catch (Async\Exception\CancelledException $e) {
            static::assertSame($token, $e->getToken());
        } finally {
            $transport->close();
            $server->close();
            $future->await();
        }
    }

    public function testDeliverByNotifyMode(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();
        $future = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->writeAll("220 mail.example.com ESMTP\r\n");
            self::readCommand($client);
            $client->writeAll("250-mail.example.com\r\n250 DELIVERBY\r\n");
            $mailFrom = self::readCommand($client);
            static::assertMatchesRegularExpression('/ BY=7200;N/', $mailFrom);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("354 Go ahead\r\n");
            $data = '';
            while (!Byte\contains($data, "\r\n.\r\n")) {
                $data .= $client->read();
            }

            $client->writeAll("250 OK\r\n");
            self::readCommand($client);
            $client->writeAll("250 OK\r\n");
            $client->close();
        });
        $configuration = new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
        );
        $transport = new Transport($configuration);
        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $envelope = new Envelope($sender, [$recipient]);
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('BY Notify')
            ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));
        $config = new SendConfiguration(deliverBy: new DeliverBy(Duration::hours(2), DeliverByMode::Notify));
        $transport->send($envelope, $message, $config);
        $future->await();
        $transport->close();
        $server->close();
        $this->addToAssertionCount(1);
    }

    private static function readCommand(Network\StreamInterface $stream): string
    {
        $command = '';
        while (!Byte\contains($command, "\r\n")) {
            $command .= $stream->read();
        }

        return $command;
    }
}
