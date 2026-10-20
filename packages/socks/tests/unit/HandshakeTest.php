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
}
