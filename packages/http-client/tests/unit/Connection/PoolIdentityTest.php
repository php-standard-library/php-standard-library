<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\Origin;
use Psl\HTTP\Client\Connection\PooledConnector;
use Psl\HTTP\Client\H2ClientConfiguration;
use Psl\HTTP\Client\Internal\H1\H1Connection;
use Psl\HTTP\Client\ProxyConfiguration;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\HTTP\Message\Request;
use Psl\Network;
use Psl\TCP;
use Psl\TLS;
use Psl\URL;

final class PoolIdentityTest extends TestCase
{
    /** @return iterable<string, array{ClientConfiguration, ClientConfiguration}> */
    public static function configurations(): iterable
    {
        $base = new ClientConfiguration(protocolVersions: [ProtocolVersion::V20]);
        yield 'peer verification' => [
            $base->withTlsConfiguration(new TLS\ClientConfiguration(peerVerification: false)),
            $base,
        ];
        yield 'CA file' => [
            $base,
            $base->withTlsConfiguration(new TLS\ClientConfiguration(certificateAuthority: '/ca.pem')),
        ];
        yield 'client certificate' => [
            $base,
            $base->withTlsConfiguration(
                new TLS\ClientConfiguration(certificate: new TLS\Certificate('/client.pem', '/key.pem')),
            ),
        ];
        yield 'ALPN' => [$base, $base->withTlsConfiguration(new TLS\ClientConfiguration(alpnProtocols: ['h2']))];
        yield 'H2 settings' => [
            $base,
            $base->withH2ClientConfiguration(new H2ClientConfiguration(initialWindowSize: 65_535)),
        ];
        yield 'proxy route' => [
            $base,
            $base->withProxyConfiguration(
                new ProxyConfiguration(URL\parse('http://proxy.test:8080'), skipProxyFor: ['*']),
            ),
        ];
    }

    #[DataProvider('configurations')]
    public function testTransportSettingsDoNotShareSessions(
        ClientConfiguration $first,
        ClientConfiguration $second,
    ): void {
        $tcp = $this->createMock(TCP\ConnectorInterface::class);
        $tcp
            ->expects(static::exactly(2))
            ->method('connect')
            ->willReturnCallback($this->stream(...));
        $pool = new PooledConnector($tcp);
        $origin = new Origin('http', '192.0.2.1', 80);
        $request = new Request(method: 'GET', url: URL\parse('http://example.com/'));

        $pool->connect($origin, $request, $first);
        $pool->connect($origin, $request, $second);
    }

    public function testTwoSniHostsOnOneIpDoNotShareSessions(): void
    {
        $tcp = $this->createMock(TCP\ConnectorInterface::class);
        $tcp
            ->expects(static::exactly(2))
            ->method('connect')
            ->willReturnCallback($this->stream(...));
        $pool = new PooledConnector($tcp);
        $configuration = new ClientConfiguration(protocolVersions: [ProtocolVersion::V20]);
        $request = new Request(method: 'GET', url: URL\parse('http://example.com/'));

        $pool->connect(new Origin('http', '192.0.2.1', 80, 'one.example'), $request, $configuration);
        $pool->connect(new Origin('http', '192.0.2.1', 80, 'two.example'), $request, $configuration);
    }

    public function testEqualSettingsReuseSessionAcrossConfigurationInstances(): void
    {
        $tcp = $this->createMock(TCP\ConnectorInterface::class);
        $tcp->expects(static::once())->method('connect')->willReturn($this->stream());
        $pool = new PooledConnector($tcp);
        $origin = new Origin('http', '192.0.2.1', 80);
        $request = new Request(method: 'GET', url: URL\parse('http://example.com/'));

        $first = $pool->connect($origin, $request, new ClientConfiguration(protocolVersions: [ProtocolVersion::V20]));
        $second = $pool->connect($origin, $request, new ClientConfiguration(protocolVersions: [ProtocolVersion::V20]));

        static::assertSame($first->metadata, $second->metadata);
    }

    public function testProtocolOverrideDoesNotReuseH2(): void
    {
        $tcp = $this->createMock(TCP\ConnectorInterface::class);
        $tcp
            ->expects(static::exactly(2))
            ->method('connect')
            ->willReturnCallback($this->stream(...));
        $pool = new PooledConnector($tcp);
        $origin = new Origin('http', '192.0.2.1', 80);
        $configuration = new ClientConfiguration(protocolVersions: [ProtocolVersion::V20, ProtocolVersion::V10]);
        $url = URL\parse('http://example.com/');

        $pool->connect(
            $origin,
            new Request(method: 'GET', url: $url, protocolVersion: ProtocolVersion::V20),
            $configuration,
        );
        $connection = $pool->connect(
            $origin,
            new Request(method: 'GET', url: $url, protocolVersion: ProtocolVersion::V10),
            $configuration,
        );

        static::assertInstanceOf(H1Connection::class, $connection);
    }

    private function stream(): TCP\StreamInterface
    {
        $stream = $this->createStub(TCP\StreamInterface::class);
        $stream->method('getLocalAddress')->willReturn(Network\Address::tcp());
        $stream->method('getPeerAddress')->willReturn(Network\Address::tcp('192.0.2.1', 80));

        return $stream;
    }
}
