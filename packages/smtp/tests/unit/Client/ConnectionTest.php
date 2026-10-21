<?php

declare(strict_types=1);

namespace Psl\SMTP\Tests\Unit\Client;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\IO;
use Psl\IO\Exception\AlreadyClosedException;
use Psl\Network;
use Psl\SMTP\Capability;
use Psl\SMTP\Client\Connection;
use Psl\SMTP\Command;
use Psl\SMTP\Exception\ConnectionException;
use Psl\SMTP\Exception\ProtocolException;
use Psl\Str\Byte;
use Psl\TCP;
use Psl\TLS\Connector;

use function extension_loaded;
use function socket_import_stream;
use function socket_set_option;

use const PHP_OS_FAMILY;
use const SO_LINGER;
use const SOL_SOCKET;

final class ConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('SMTP connection tests are not supported on Windows.');
        }
    }

    public function testReadGreeting(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $serverStream->writeAll("220 mail.example.com ESMTP\r\n");
        });

        $response = $connection->readGreeting();

        static::assertSame(220, $response->code);
        static::assertSame('mail.example.com ESMTP', $response->message);
        static::assertTrue($response->isPositiveCompletion());

        $connection->close();
        $serverStream->close();
    }

    public function testReadGreetingMultiline(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $serverStream->writeAll("220-mail.example.com ESMTP\r\n220 Ready\r\n");
        });

        $response = $connection->readGreeting();

        static::assertSame(220, $response->code);
        static::assertStringContainsString('mail.example.com ESMTP', $response->message);
        static::assertStringContainsString('Ready', $response->message);

        $connection->close();
        $serverStream->close();
    }

    public function testReadGreetingRejectsNon220(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $serverStream->writeAll("554 Go away\r\n");
        });

        $this->expectException(ConnectionException::class);

        try {
            $connection->readGreeting();
        } finally {
            $connection->close();
            $serverStream->close();
        }
    }

    public function testReadGreetingOnClosedStream(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $serverStream->close();
        });

        Async\later();

        $this->expectException(ProtocolException::class);

        try {
            $connection->readGreeting();
        } finally {
            $connection->close();
        }
    }

    public function testEhlo(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $command = self::readCommand($serverStream);
            static::assertStringContainsString('EHLO client.example.com', $command);

            $serverStream->writeAll(
                "250-mail.example.com\r\n"
                . "250-8BITMIME\r\n"
                . "250-SIZE 10485760\r\n"
                . "250-STARTTLS\r\n"
                . "250-PIPELINING\r\n"
                . "250-DSN\r\n"
                . "250-AUTH PLAIN LOGIN\r\n"
                . "250 SMTPUTF8\r\n",
            );
        });

        $response = $connection->ehlo('client.example.com');

        static::assertSame(250, $response->code);
        static::assertTrue($response->isPositiveCompletion());
        static::assertTrue($connection->supportsCapability(Capability::EightBitMIME));
        static::assertTrue($connection->supportsCapability(Capability::Size));
        static::assertTrue($connection->supportsCapability(Capability::StartTLS));
        static::assertTrue($connection->supportsCapability(Capability::Pipelining));
        static::assertTrue($connection->supportsCapability(Capability::DSN));
        static::assertTrue($connection->supportsCapability(Capability::SMTPUTF8));
        static::assertSame(10_485_760, $connection->maxSize);

        $connection->close();
        $serverStream->close();
    }

    public function testEhloWithoutSize(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250 8BITMIME\r\n");
        });

        $connection->ehlo('client.example.com');

        static::assertTrue($connection->supportsCapability(Capability::EightBitMIME));
        static::assertFalse($connection->supportsCapability(Capability::Size));
        static::assertNull($connection->maxSize);

        $connection->close();
        $serverStream->close();
    }

    public function testEhloDoesNotStripControlCharactersFromCapabilityLines(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll(
                "250-mail.example.com\r\n"
                . "250-PIPELINING\r\n"
                . "250-STARTTLS\x0C\r\n"
                . "250-DSN\x0B\r\n"
                . "250-8BITMIME\0\r\n"
                . "250 SIZE 1000\r\n",
            );
        });

        $connection->ehlo('client.example.com');

        static::assertTrue($connection->supportsCapability(Capability::Pipelining));
        static::assertFalse($connection->supportsCapability(Capability::StartTLS));
        static::assertFalse($connection->supportsCapability(Capability::DSN));
        static::assertFalse($connection->supportsCapability(Capability::EightBitMIME));
        static::assertSame('1000', $connection->getCapabilityValue(Capability::Size));

        $connection->close();
        $serverStream->close();
    }

    /**
     * RFC 5321 does not allow whitespace around an ehlo-line; SP / HTAB are tolerated for leniency.
     */
    public function testEhloToleratesSpacesAndTabsAroundCapabilityLines(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250- \tPIPELINING \t\r\n250 SIZE 1000 \t\r\n");
        });

        $connection->ehlo('client.example.com');

        static::assertTrue($connection->supportsCapability(Capability::Pipelining));
        static::assertSame('1000', $connection->getCapabilityValue(Capability::Size));
        static::assertSame(1000, $connection->maxSize);

        $connection->close();
        $serverStream->close();
    }

    public function testEhloResetsCapabilities(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250-PIPELINING\r\n250 SIZE 1000\r\n");

            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250 8BITMIME\r\n");
        });

        $connection->ehlo('client.example.com');
        static::assertTrue($connection->supportsCapability(Capability::Pipelining));
        static::assertSame(1000, $connection->maxSize);

        $connection->ehlo('client.example.com');
        static::assertFalse($connection->supportsCapability(Capability::Pipelining));
        static::assertTrue($connection->supportsCapability(Capability::EightBitMIME));
        static::assertNull($connection->maxSize);

        $connection->close();
        $serverStream->close();
    }

    public function testEhloNegativeResponse(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll("502 Command not recognized\r\n");
        });

        $response = $connection->ehlo('client.example.com');

        static::assertSame(502, $response->code);
        static::assertFalse($response->isPositiveCompletion());
        static::assertFalse($connection->supportsCapability(Capability::EightBitMIME));

        $connection->close();
        $serverStream->close();
    }

    public function testSendCommand(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $command = self::readCommand($serverStream);
            static::assertSame("NOOP\r\n", $command);
            $serverStream->writeAll("250 OK\r\n");
        });

        $response = $connection->sendCommand('NOOP');

        static::assertSame(250, $response->code);
        static::assertSame('OK', $response->message);

        $connection->close();
        $serverStream->close();
    }

    public function testSendCommandOnClosedStream(): void
    {
        [$connection, $serverStream] = $this->createPair();

        $serverStream->close();
        Async\later();

        // The server's kernel answers the command with a reset. If the read sees the end of the stream first, the
        // reply is cut short (ProtocolException); if it sees the reset first, the read fails (IO's
        // RuntimeException). sendCommand() documents both, and which one comes first is up to the kernel.
        try {
            $connection->sendCommand('NOOP');
            static::fail('Expected sendCommand() to fail once the server closed the connection.');
        } catch (ProtocolException|IO\Exception\RuntimeException) {
            static::addToAssertionCount(1);
        } finally {
            $connection->close();
        }
    }

    public function testSendCommandOnResetStream(): void
    {
        if (!extension_loaded('sockets')) {
            static::markTestSkipped('Resetting a connection on close requires ext-sockets.');
        }

        [$connection, $serverStream] = $this->createPair();

        // A zero linger time makes closing the socket reset the connection instead of ending it.
        $socket = socket_import_stream($serverStream->getStream());
        static::assertNotFalse($socket);
        static::assertTrue(socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]));
        unset($socket);
        $serverStream->close();
        Async\later();

        $this->expectException(IO\Exception\RuntimeException::class);

        try {
            $connection->sendCommand('NOOP');
        } finally {
            $connection->close();
        }
    }

    public function testReadResponseOnClosedStream(): void
    {
        [$connection, $serverStream] = $this->createPair();

        $serverStream->close();
        Async\later();

        $this->expectException(ProtocolException::class);

        try {
            $connection->readReply();
        } finally {
            $connection->close();
        }
    }

    public function testMalformedResponse(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $serverStream->writeAll("XYZ Invalid\r\n");
        });

        $this->expectException(ProtocolException::class);

        try {
            $connection->readReply();
        } finally {
            $connection->close();
            $serverStream->close();
        }
    }

    public function testMalformedResponseTooShort(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $serverStream->writeAll("AB\r\n");
        });

        $this->expectException(ProtocolException::class);

        try {
            $connection->readReply();
        } finally {
            $connection->close();
            $serverStream->close();
        }
    }

    public function testMalformedResponseInvalidSeparator(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $serverStream->writeAll("250|OK\r\n");
        });

        $this->expectException(ProtocolException::class);

        try {
            $connection->readReply();
        } finally {
            $connection->close();
            $serverStream->close();
        }
    }

    public function testMultilineResponseInconsistentCodes(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $serverStream->writeAll("250-First\r\n251 Second\r\n");
        });

        $this->expectException(ProtocolException::class);

        try {
            $connection->readReply();
        } finally {
            $connection->close();
            $serverStream->close();
        }
    }

    public function testMultilineResponse(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $serverStream->writeAll("250-First line\r\n250-Second line\r\n250 Third line\r\n");
        });

        $response = $connection->readReply();

        static::assertSame(250, $response->code);
        static::assertStringContainsString('First line', $response->message);
        static::assertStringContainsString('Second line', $response->message);
        static::assertStringContainsString('Third line', $response->message);

        $connection->close();
        $serverStream->close();
    }

    public function testResponseCodeOnly(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $serverStream->writeAll("250\r\n");
        });

        $response = $connection->readReply();

        static::assertSame(250, $response->code);
        static::assertSame('', $response->message);

        $connection->close();
        $serverStream->close();
    }

    public function testStartTlsRejected(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $command = self::readCommand($serverStream);
            static::assertStringContainsString('STARTTLS', $command);
            $serverStream->writeAll("502 Command not implemented\r\n");
        });

        $this->expectException(ConnectionException::class);

        try {
            $connection->startTls(new Connector());
        } finally {
            $connection->close();
            $serverStream->close();
        }
    }

    public function testClose(): void
    {
        [$connection, $serverStream] = $this->createPair();

        $connection->close();
        $serverStream->close();

        $this->expectException(AlreadyClosedException::class);

        $connection->sendCommand('NOOP');
    }

    public function testHeloCommand(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $command = self::readCommand($serverStream);
            static::assertStringContainsString('HELO client.example.com', $command);

            $serverStream->writeAll("250 mail.example.com\r\n");
        });

        $response = $connection->helo('client.example.com');

        static::assertSame(250, $response->code);
        static::assertTrue($response->isPositiveCompletion());

        $connection->close();
        $serverStream->close();
    }

    public function testHeloClearsCapabilities(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            // First EHLO
            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250-PIPELINING\r\n250 SIZE 5000\r\n");

            // Then HELO
            self::readCommand($serverStream);
            $serverStream->writeAll("250 mail.example.com\r\n");
        });

        $connection->ehlo('client.example.com');
        static::assertTrue($connection->supportsCapability(Capability::Pipelining));
        static::assertSame(5000, $connection->maxSize);

        $connection->helo('client.example.com');
        static::assertFalse($connection->supportsCapability(Capability::Pipelining));
        static::assertNull($connection->maxSize);

        $connection->close();
        $serverStream->close();
    }

    public function testHeloNegativeResponseDoesNotClearCapabilities(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            // EHLO
            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250 PIPELINING\r\n");

            // HELO fails
            self::readCommand($serverStream);
            $serverStream->writeAll("502 Not supported\r\n");
        });

        $connection->ehlo('client.example.com');
        static::assertTrue($connection->supportsCapability(Capability::Pipelining));

        $response = $connection->helo('client.example.com');
        static::assertSame(502, $response->code);
        static::assertTrue($connection->supportsCapability(Capability::Pipelining));

        $connection->close();
        $serverStream->close();
    }

    public function testSupportsCapabilityWithStringUppercase(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250 PIPELINING\r\n");
        });

        $connection->ehlo('client.example.com');

        static::assertTrue($connection->supportsCapability('PIPELINING'));
        static::assertTrue($connection->supportsCapability('pipelining'));
        static::assertFalse($connection->supportsCapability('CHUNKING'));

        $connection->close();
        $serverStream->close();
    }

    public function testSupportsCapabilityWithEnum(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250 8BITMIME\r\n");
        });

        $connection->ehlo('client.example.com');

        static::assertTrue($connection->supportsCapability(Capability::EightBitMIME));
        static::assertFalse($connection->supportsCapability(Capability::DSN));

        $connection->close();
        $serverStream->close();
    }

    public function testGetCapabilityValue(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250-AUTH PLAIN LOGIN XOAUTH2\r\n250 SIZE 10485760\r\n");
        });

        $connection->ehlo('client.example.com');

        static::assertSame('PLAIN LOGIN XOAUTH2', $connection->getCapabilityValue('AUTH'));
        static::assertSame('10485760', $connection->getCapabilityValue('SIZE'));
        static::assertSame('10485760', $connection->getCapabilityValue(Capability::Size));

        $connection->close();
        $serverStream->close();
    }

    public function testGetCapabilityValueReturnsNullForMissing(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll("250 mail.example.com\r\n");
        });

        $connection->ehlo('client.example.com');

        static::assertNull($connection->getCapabilityValue('AUTH'));
        static::assertNull($connection->getCapabilityValue(Capability::DSN));

        $connection->close();
        $serverStream->close();
    }

    public function testGetCapabilityValueEmptyString(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250 PIPELINING\r\n");
        });

        $connection->ehlo('client.example.com');

        static::assertSame('', $connection->getCapabilityValue('PIPELINING'));
        static::assertSame('', $connection->getCapabilityValue(Capability::Pipelining));

        $connection->close();
        $serverStream->close();
    }

    public function testSendCommandWithCommandObject(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $command = self::readCommand($serverStream);
            static::assertSame("MAIL FROM:<user@example.com>\r\n", $command);
            $serverStream->writeAll("250 OK\r\n");
        });

        $cmd = new Command('MAIL', 'FROM:<user@example.com>');
        $response = $connection->sendCommand($cmd);

        static::assertSame(250, $response->code);

        $connection->close();
        $serverStream->close();
    }

    public function testWriteCommandAndReadReplySeparately(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $command = self::readCommand($serverStream);
            static::assertStringContainsString('NOOP', $command);
            $serverStream->writeAll("250 OK\r\n");
        });

        $connection->writeCommand('NOOP');
        $response = $connection->readReply();

        static::assertSame(250, $response->code);
        static::assertSame('OK', $response->message);

        $connection->close();
        $serverStream->close();
    }

    public function testWriteCommandWithCommandObject(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            $command = self::readCommand($serverStream);
            static::assertSame("QUIT\r\n", $command);
            $serverStream->writeAll("221 Bye\r\n");
        });

        $cmd = new Command('QUIT');
        $connection->writeCommand($cmd);
        $response = $connection->readReply();

        static::assertSame(221, $response->code);

        $connection->close();
        $serverStream->close();
    }

    public function testIsClosedFalseByDefault(): void
    {
        [$connection, $serverStream] = $this->createPair();

        static::assertFalse($connection->isClosed());

        $connection->close();
        $serverStream->close();
    }

    public function testIsClosedTrueAfterClose(): void
    {
        [$connection, $serverStream] = $this->createPair();

        $connection->close();

        static::assertTrue($connection->isClosed());

        $serverStream->close();
    }

    public function testMaxSizeNullByDefault(): void
    {
        [$connection, $serverStream] = $this->createPair();

        static::assertNull($connection->maxSize);

        $connection->close();
        $serverStream->close();
    }

    public function testMaxSizeParsedFromEhlo(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250 SIZE 52428800\r\n");
        });

        $connection->ehlo('test');

        static::assertSame(52_428_800, $connection->maxSize);

        $connection->close();
        $serverStream->close();
    }

    public function testMaxSizeResetAfterSecondEhlo(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250 SIZE 1000\r\n");

            self::readCommand($serverStream);
            $serverStream->writeAll("250 mail.example.com\r\n");
        });

        $connection->ehlo('test');
        static::assertSame(1000, $connection->maxSize);

        $connection->ehlo('test');
        static::assertNull($connection->maxSize);

        $connection->close();
        $serverStream->close();
    }

    public function testEhloWithSizeKeywordButNoValue(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250 SIZE\r\n");
        });

        $connection->ehlo('test');

        static::assertNull($connection->maxSize);
        static::assertTrue($connection->supportsCapability(Capability::Size));

        $connection->close();
        $serverStream->close();
    }

    public function testEhloCapabilityCaseInsensitive(): void
    {
        [$connection, $serverStream] = $this->createPair();

        Async\run(static function () use ($serverStream): void {
            self::readCommand($serverStream);
            $serverStream->writeAll("250-mail.example.com\r\n250 pipelining\r\n");
        });

        $connection->ehlo('test');

        static::assertTrue($connection->supportsCapability('PIPELINING'));
        static::assertTrue($connection->supportsCapability('pipelining'));
        static::assertTrue($connection->supportsCapability(Capability::Pipelining));

        $connection->close();
        $serverStream->close();
    }

    /**
     * @return array{Connection, Network\StreamInterface}
     */
    private function createPair(): array
    {
        $server = TCP\listen('127.0.0.1', 0);
        $address = $server->getLocalAddress();

        $clientStream = null;
        $serverStream = null;

        Async\concurrently([
            static function () use ($server, &$serverStream): void {
                $serverStream = $server->accept();
            },
            static function () use ($address, &$clientStream): void {
                $clientStream = TCP\connect($address->host, $address->port);
            },
        ]);

        $server->close();

        return [new Connection($clientStream), $serverStream];
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
