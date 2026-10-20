<?php

declare(strict_types=1);

namespace Psl\Network\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Network;

use function extension_loaded;
use function fclose;
use function fopen;
use function socket_create;
use function socket_export_stream;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

final class GetPeerNameTest extends TestCase
{
    public function testCapturedPeerAddressDoesNotQueryTheSocket(): void
    {
        $stream = fopen('php://memory', 'r+');
        static::assertIsResource($stream);

        try {
            $address = Network\Internal\get_peer_name($stream, '127.0.0.1:1234');
            static::assertSame('127.0.0.1', $address->host);
            static::assertSame(1234, $address->port);
        } finally {
            fclose($stream);
        }
    }

    public function testUnconnectedSocketHasNoPeerAddress(): void
    {
        if (!extension_loaded('sockets')) {
            static::markTestSkipped('The sockets extension is required.');
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        static::assertNotFalse($socket);
        $stream = socket_export_stream($socket);
        static::assertIsResource($stream);

        try {
            $this->expectException(Network\Exception\RuntimeException::class);
            Network\Internal\get_peer_name($stream);
        } finally {
            fclose($stream);
        }
    }
}
