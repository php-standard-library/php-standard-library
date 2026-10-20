<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Binary\Reader;
use Psl\Binary\Writer;
use Psl\DateTime\Duration;
use Psl\DNS\Exception\ProtocolException;
use Psl\DNS\Record\RecordType;
use Psl\DNS\UDPResolver;
use Psl\UDP;

final class TruncationBeforeTxidTest extends TestCase
{
    public function testTruncatedResponseWithWrongTxidThrowsInvalidResponseNotTruncated(): void
    {
        $server = UDP\Socket::bind('127.0.0.1', 0);
        $localAddress = $server->getLocalAddress();

        Async\run(static function () use ($server): void {
            [$query, $peer] = $server->receiveFrom(512);
            $reader = new Reader($query);
            $id = $reader->u16();

            $wrongId = $id ^ 0xFFFF;
            $responsePacket = new Writer()
                ->u16($wrongId)
                ->u16(0x8380)
                ->u16(0)
                ->u16(0)
                ->u16(0)
                ->u16(0)
                ->toString();

            $server->sendTo($responsePacket, $peer);
            $server->close();
        });

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('does not match query ID');

        $resolver = new UDPResolver(host: $localAddress->host, port: $localAddress->port ?? 0);
        $resolver->query('test.example', RecordType::A, new Async\TimeoutCancellationToken(Duration::seconds(2)));
    }
}
