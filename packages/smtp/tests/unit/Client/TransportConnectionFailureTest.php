<?php

declare(strict_types=1);

namespace Psl\SMTP\Tests\Unit\Client;

use Closure;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Message\Address\Mailbox;
use Psl\Message\Envelope;
use Psl\Message\Message;
use Psl\MIME\Part;
use Psl\Network;
use Psl\SMTP\Client\Transport;
use Psl\SMTP\Client\TransportConfiguration;
use Psl\SMTP\Exception;
use Psl\SMTP\Security;
use Psl\Str\Byte;
use Psl\TCP;
use Throwable;

use function fclose;
use function str_repeat;

use const PHP_OS_FAMILY;

/**
 * A connection that fails in the middle of the exchange makes send() throw the SMTP failure that
 * TransportInterface::send() promises: a ConnectionException, whose previous exception is the IO exception.
 */
final class TransportConnectionFailureTest extends TestCase
{
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('SMTP transport tests are not supported on Windows.');
        }
    }

    public function testConnectionResetWhileReadingAReplyThrowsConnectionException(): void
    {
        $exception = self::sendThroughServer(
            static function (Network\StreamInterface $client): void {
                $client->writeAll("220 mail.example.com ESMTP\r\n");
                self::readCommand($client);
                $client->writeAll("250 mail.example.com\r\n");

                // MAIL FROM arrives and is never read: closing with unread bytes resets the connection.
                $client->peek(1);
                fclose($client->getStream());
            },
            'Hello',
        );

        static::assertInstanceOf(Exception\ConnectionException::class, $exception);
        static::assertSame('Lost the connection to the SMTP server.', $exception->getMessage());
        static::assertInstanceOf(IO\Exception\RuntimeException::class, $exception->getPrevious());
    }

    public function testConnectionResetWhileWritingTheMessageThrowsConnectionException(): void
    {
        $exception = self::sendThroughServer(
            static function (Network\StreamInterface $client): void {
                $client->writeAll("220 mail.example.com ESMTP\r\n");
                self::readCommand($client);
                $client->writeAll("250 mail.example.com\r\n");
                self::readCommand($client);
                $client->writeAll("250 OK\r\n");
                self::readCommand($client);
                $client->writeAll("250 OK\r\n");
                self::readCommand($client);
                $client->writeAll("354 Go ahead\r\n");

                // Take a little of the message, then reset the connection while the client still writes it.
                $client->read();
                fclose($client->getStream());
            },
            // More than the socket buffers hold, so that the client is still writing when the reset arrives.
            str_repeat("0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef\r\n", 65_536),
        );

        static::assertInstanceOf(Exception\ConnectionException::class, $exception);
        static::assertSame('Lost the connection to the SMTP server.', $exception->getMessage());
        static::assertInstanceOf(IO\Exception\RuntimeException::class, $exception->getPrevious());
    }

    /**
     * Send a message to a server that runs `$server` on the accepted connection, and return what send() threw.
     *
     * @param Closure(Network\StreamInterface): void $server
     */
    private static function sendThroughServer(Closure $server, string $body): Throwable
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $address = $listener->getLocalAddress();
        $session = Async\run(static function () use ($listener, $server): void {
            $client = $listener->accept();

            try {
                $server($client);
            } finally {
                $client->close();
            }
        });

        $transport = new Transport(new TransportConfiguration(
            host: $address->host,
            port: $address->port,
            security: Security::None,
            pipelining: false,
            chunking: false,
        ));

        $sender = new Mailbox('sender', 'example.com');
        $recipient = new Mailbox('recipient', 'example.com');
        $message = new Message()
            ->withFrom($sender)
            ->withTo($recipient)
            ->withSubject('Test')
            ->withContent(new Part\Text(new IO\MemoryHandle($body)));

        try {
            $transport->send(
                new Envelope($sender, [$recipient]),
                $message,
                cancellation: new Async\TimeoutCancellationToken(Duration::seconds(10)),
            );
        } catch (Exception\ExceptionInterface|IO\Exception\ExceptionInterface|Async\Exception\CancelledException $e) {
            return $e;
        } finally {
            $session->await();
            $transport->close();
            $listener->close();
        }

        static::fail('send() succeeded although the connection was reset.');
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
