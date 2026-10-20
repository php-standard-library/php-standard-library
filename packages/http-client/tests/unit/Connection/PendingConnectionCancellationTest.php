<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\ConnectionInterface;
use Psl\HTTP\Client\Connection\Origin;
use Psl\HTTP\Client\Connection\PooledConnector;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\HTTP\Message\Request;
use Psl\Network;
use Psl\TCP;
use Psl\URL;

final class PendingConnectionCancellationTest extends TestCase
{
    public function testWaitingRequestCanCancelWithoutCancellingSharedConnection(): void
    {
        $connecting = new Async\Deferred();
        $release = new Async\Deferred();
        $stream = $this->createStub(TCP\StreamInterface::class);
        $stream->method('getLocalAddress')->willReturn(Network\Address::tcp());
        $stream->method('getPeerAddress')->willReturn(Network\Address::tcp('192.0.2.1', 80));
        $tcp = $this->createMock(TCP\ConnectorInterface::class);
        $tcp
            ->expects(static::once())
            ->method('connect')
            ->willReturnCallback(static function () use ($connecting, $release, $stream): TCP\StreamInterface {
                $connecting->complete(null);
                $release->getAwaitable()->await();

                return $stream;
            });

        $pool = new PooledConnector($tcp);
        $origin = new Origin('http', '192.0.2.1', 80);
        $request = new Request(method: 'GET', url: URL\parse('http://example.com/'));
        $configuration = new ClientConfiguration(protocolVersions: [ProtocolVersion::V20]);
        $first = Async\run(static fn(): ConnectionInterface => $pool->connect($origin, $request, $configuration));
        $connecting->getAwaitable()->await();

        $cancellation = new Async\SignalCancellationToken();
        $waiting = Async\run(static function () use (
            $pool,
            $origin,
            $request,
            $configuration,
            $cancellation,
        ): null|Async\Exception\CancelledException {
            try {
                $pool->connect($origin, $request, $configuration, $cancellation);
            } catch (Async\Exception\CancelledException $exception) {
                return $exception;
            }

            return null;
        });
        Async\later();
        $cancellation->cancel();
        Async\later();

        $cancelledBeforeConnect = $waiting->isComplete();
        $firstStillPending = !$first->isComplete();
        $release->complete(null);
        $first->await();
        $exception = $waiting->await();

        static::assertTrue($cancelledBeforeConnect, 'Cancellation must not wait for the pending connection.');
        static::assertTrue($firstStillPending, 'Cancelling a waiter must not cancel the shared connection.');
        static::assertInstanceOf(Async\Exception\CancelledException::class, $exception);
    }
}
