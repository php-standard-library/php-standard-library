<?php

declare(strict_types=1);

namespace Psl\Socks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\Network;
use Psl\Socks;

use function inet_pton;
use function pack;
use function strlen;
use function substr;

final class HandshakeTest extends TestCase
{
    public function testHandshakeWritesEveryByte(): void
    {
        foreach ([false, true] as $authenticate) {
            $reply = $authenticate ? "\x05\x02\x01\x00" : "\x05\x00";
            $stream = new class($reply . "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00") implements
                IO\ReadHandleInterface,
                IO\WriteHandleInterface {
                use IO\ReadHandleConvenienceMethodsTrait;
                use IO\WriteHandleConvenienceMethodsTrait;

                public string $written = '';
                private IO\MemoryHandle $input;

                public function __construct(string $reply)
                {
                    $this->input = new IO\MemoryHandle($reply);
                }

                public function reachedEndOfDataSource(): bool
                {
                    return $this->input->reachedEndOfDataSource();
                }

                public function tryRead(null|int $maxBytes = null): string
                {
                    return $this->input->tryRead($maxBytes);
                }

                public function read(
                    null|int $maxBytes = null,
                    CancellationTokenInterface $cancellation = new NullCancellationToken(),
                ): string {
                    return $this->input->read($maxBytes, $cancellation);
                }

                public function tryWrite(string $bytes): int
                {
                    $byte = substr($bytes, 0, 1);
                    $this->written .= $byte;

                    return strlen($byte);
                }

                public function write(
                    string $bytes,
                    CancellationTokenInterface $cancellation = new NullCancellationToken(),
                ): int {
                    $cancellation->throwIfCancelled();

                    return $this->tryWrite($bytes);
                }
            };
            $configuration = $authenticate
                ? new Socks\Configuration('localhost', 1080, 'user', 'pass')
                : new Socks\Configuration('localhost', 1080);

            Socks\Internal\socks5_handshake($stream, '127.0.0.1', 443, $configuration);

            $greeting = $authenticate ? "\x05\x02\x00\x02\x01\x04user\x04pass" : "\x05\x01\x00";
            static::assertSame($greeting . "\x05\x01\x00\x01\x7f\x00\x00\x01" . pack('n', 443), $stream->written);
        }
    }

    /**
     * An authentication reply must carry version 1 of the RFC 1929 sub-negotiation: any other version is a protocol
     * error, not a rejection of the credentials.
     */
    public function testAuthenticationReplyOfAnotherVersionIsRejected(): void
    {
        $stream = new ScriptedStream("\x05\x02" . "\x99\x00" . "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

        $failure = self::handshakeFailure($stream, new Socks\Configuration('localhost', 1080, 'user', 'pass'));

        static::assertSame(Socks\Exception\SocksException::class, $failure::class);
        static::assertSame('Invalid SOCKS5 authentication version in server response.', $failure->getMessage());
    }

    /**
     * A connection reply whose reserved byte is not 0 is rejected.
     */
    public function testConnectionReplyWithANonzeroReservedByteIsRejected(): void
    {
        $stream = new ScriptedStream("\x05\x00" . "\x05\x00\x99\x01\x00\x00\x00\x00\x00\x00");

        $failure = self::handshakeFailure($stream, new Socks\Configuration('localhost', 1080));

        static::assertSame(Socks\Exception\SocksException::class, $failure::class);
        static::assertSame('Invalid reserved byte in SOCKS5 connection response.', $failure->getMessage());
    }

    /**
     * A method that the client did not offer is rejected: username and password without credentials, and any
     * other method at all.
     */
    public function testUnofferedAuthenticationMethodIsRejected(): void
    {
        $failure = self::handshakeFailure(new ScriptedStream("\x05\x02"), new Socks\Configuration('localhost', 1080));
        static::assertInstanceOf(Socks\Exception\AuthenticationException::class, $failure);

        $failure = self::handshakeFailure(
            new ScriptedStream("\x05\x01"),
            new Socks\Configuration('localhost', 1080, 'user', 'pass'),
        );
        static::assertSame(Socks\Exception\SocksException::class, $failure::class);
        static::assertSame('SOCKS5 server selected unsupported authentication method: 1.', $failure->getMessage());
    }

    public function testBracketedIpv6UsesAddressType(): void
    {
        $stream = $this->createStub(Network\StreamInterface::class);
        $stream->method('readFixedSize')->willReturnOnConsecutiveCalls(
            "\x05\x00",
            "\x05\x00\x00\x01",
            "\x00\x00\x00\x00",
            "\x00\x00",
        );
        $written = '';
        $stream
            ->method('write')
            ->willReturnCallback(static function (string $bytes) use (&$written): int {
                $written .= $bytes;

                return strlen($bytes);
            });
        $stream
            ->method('writeAll')
            ->willReturnCallback(static function (string $bytes) use (&$written): void {
                $written .= $bytes;
            });

        Socks\Internal\socks5_handshake($stream, '[::1]', 443, new Socks\Configuration('localhost', 1080));

        static::assertSame("\x05\x01\x00\x05\x01\x00\x04" . inet_pton('::1') . pack('n', 443), $written);
    }

    /**
     * Run a handshake that must fail, and return the exception it failed with.
     */
    private static function handshakeFailure(
        ScriptedStream $stream,
        Socks\Configuration $configuration,
    ): Socks\Exception\SocksException {
        try {
            Socks\Internal\socks5_handshake($stream, '127.0.0.1', 443, $configuration);
        } catch (Socks\Exception\SocksException $e) {
            return $e;
        }

        static::fail('The handshake did not fail.');
    }
}
