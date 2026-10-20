<?php

declare(strict_types=1);

namespace Psl\Socks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Network;
use Psl\Socks;

use function inet_pton;
use function pack;
use function strlen;

final class HandshakeTest extends TestCase
{
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
