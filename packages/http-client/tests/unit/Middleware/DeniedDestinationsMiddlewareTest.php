<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\CIDR;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\ConnectionInterface;
use Psl\HTTP\Client\Connection\ConnectionMetadata;
use Psl\HTTP\Client\Exception\RuntimeException;
use Psl\HTTP\Client\Handler\HandlerInterface;
use Psl\HTTP\Client\Middleware\DeniedDestinationsMiddleware;
use Psl\HTTP\Message\FieldMap;
use Psl\HTTP\Message\Request;
use Psl\HTTP\Message\Response;
use Psl\HTTP\Message\Transaction;
use Psl\IO;
use Psl\IP;
use Psl\Network;
use Psl\Network\Address;
use Psl\URL;

final class DeniedDestinationsMiddlewareTest extends TestCase
{
    #[DataProvider('specialPurposeDestinations')]
    public function testBlocksSpecialPurposeDestination(string $peerHost): void
    {
        $middleware = DeniedDestinationsMiddleware::forPrivateNetworkRanges();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is denied by client configuration');

        $middleware->process(
            self::createConnection($peerHost),
            new Request(method: 'GET', url: URL\parse('http://example.com/')),
            new ClientConfiguration(),
            self::createHandler(),
        );
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function specialPurposeDestinations(): iterable
    {
        foreach ([
            '0.0.0.1',
            '0.255.255.255',
            '100.64.0.0',
            '100.127.255.255',
            '192.0.0.1',
            '192.0.0.255',
            '198.18.0.0',
            '198.19.255.255',
            '224.0.0.1',
            '239.255.255.255',
            '240.0.0.0',
            '255.255.255.255',
            '169.254.169.254',
        ] as $address) {
            yield $address => [$address];
            yield 'mapped ' . $address => ['::ffff:' . $address];
        }

        yield 'translation prefix' => ['64:ff9b::a9fe:a9fe'];
        yield '6to4' => ['2002:7f00:1::'];
        yield 'IPv6 multicast' => ['ff02::1'];
        yield 'bracketed IPv6 loopback' => ['[::1]'];
        yield 'bracketed mapped metadata' => ['[::ffff:169.254.169.254]'];
    }

    #[DataProvider('equivalentAddresses')]
    public function testExactAddressMatchesMappedIPv6(string $denied, string $peerHost): void
    {
        $middleware = new DeniedDestinationsMiddleware([IP\Address::parse($denied)]);
        $this->expectException(RuntimeException::class);

        $middleware->process(
            self::createConnection($peerHost),
            new Request(method: 'GET', url: URL\parse('http://example.com/')),
            new ClientConfiguration(),
            self::createHandler(),
        );
    }

    /** @return iterable<string, array{non-empty-string, non-empty-string}> */
    public static function equivalentAddresses(): iterable
    {
        yield 'mapped peer' => ['169.254.169.254', '::ffff:169.254.169.254'];
        yield 'hex mapped peer' => ['169.254.169.254', '::ffff:a9fe:a9fe'];
        yield 'mapped deny entry' => ['::ffff:169.254.169.254', '169.254.169.254'];
    }

    #[DataProvider('publicDestinations')]
    public function testAllowsPublicDestinations(string $peerHost): void
    {
        $transaction = DeniedDestinationsMiddleware::forPrivateNetworkRanges()->process(
            self::createConnection($peerHost),
            new Request(method: 'GET', url: URL\parse('http://example.com/')),
            new ClientConfiguration(),
            self::createHandler(),
        );

        static::assertSame(200, $transaction->response->status);
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function publicDestinations(): iterable
    {
        yield 'IPv4' => ['8.8.8.8'];
        yield 'mapped IPv4' => ['::ffff:8.8.8.8'];
        yield 'IPv6' => ['2001:4860:4860::8888'];
        yield 'bracketed IPv6' => ['[2001:4860:4860::8888]'];
    }

    public function testUnixPeerDoesNotUndergoIPParsing(): void
    {
        $connection = self::createConnection('127.0.0.1');
        $connection->metadata = new ConnectionMetadata(
            Address::unix('/tmp/client.sock'),
            Address::unix('/tmp/server.sock'),
        );

        $transaction = DeniedDestinationsMiddleware::forPrivateNetworkRanges()->process(
            $connection,
            new Request(method: 'GET', url: URL\parse('http://example.com/')),
            new ClientConfiguration(),
            self::createHandler(),
        );

        static::assertSame(200, $transaction->response->status);
    }

    private static function createConnection(string $peerHost): ConnectionInterface
    {
        return new class($peerHost, 80) implements ConnectionInterface {
            public ConnectionMetadata $metadata;

            public function __construct(string $peerHost, int $peerPort)
            {
                $this->metadata = new ConnectionMetadata(
                    Address::tcp('127.0.0.1', 12_345),
                    Address::tcp($peerHost, $peerPort),
                );
            }

            public function exchange(
                Request $request,
                ClientConfiguration $configuration,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): Transaction {
                return new Transaction(
                    [],
                    null,
                    new Response(status: 200, headers: new FieldMap(), body: new IO\MemoryHandle('ok')),
                );
            }

            public function finalize(Transaction $transaction): Transaction
            {
                return $transaction;
            }
        };
    }

    private static function createHandler(): HandlerInterface
    {
        return new class() implements HandlerInterface {
            public function handle(
                ConnectionInterface $connection,
                Request $request,
                ClientConfiguration $configuration,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): Transaction {
                return $connection->exchange($request, $configuration, $cancellation);
            }
        };
    }

    public function testBlocksPrivateIPv4(): void
    {
        $middleware = DeniedDestinationsMiddleware::forPrivateNetworkRanges();
        $connection = self::createConnection('192.168.1.1');
        $request = new Request(method: 'GET', url: URL\parse('http://example.com/'));
        $handler = self::createHandler();

        $this->expectException(RuntimeException::class);

        $middleware->process($connection, $request, new ClientConfiguration(), $handler);
    }

    public function testBlocksLoopback(): void
    {
        $middleware = DeniedDestinationsMiddleware::forPrivateNetworkRanges();
        $connection = self::createConnection('127.0.0.1');
        $request = new Request(method: 'GET', url: URL\parse('http://example.com/'));
        $handler = self::createHandler();

        $this->expectException(RuntimeException::class);

        $middleware->process($connection, $request, new ClientConfiguration(), $handler);
    }

    public function testAllowsPublicIP(): void
    {
        $middleware = DeniedDestinationsMiddleware::forPrivateNetworkRanges();
        $connection = self::createConnection('93.184.216.34');
        $request = new Request(method: 'GET', url: URL\parse('http://example.com/'));
        $handler = self::createHandler();

        $transaction = $middleware->process($connection, $request, new ClientConfiguration(), $handler);

        static::assertSame(200, $transaction->response->status);
    }

    public function testBlocksExactIP(): void
    {
        $middleware = new DeniedDestinationsMiddleware([
            IP\Address::v4('10.0.0.5'),
        ]);
        $connection = self::createConnection('10.0.0.5');
        $request = new Request(method: 'GET', url: URL\parse('http://example.com/'));
        $handler = self::createHandler();

        $this->expectException(RuntimeException::class);

        $middleware->process($connection, $request, new ClientConfiguration(), $handler);
    }

    public function testAllowsNonMatchingIP(): void
    {
        $middleware = new DeniedDestinationsMiddleware([
            IP\Address::v4('10.0.0.5'),
        ]);
        $connection = self::createConnection('93.184.216.34');
        $request = new Request(method: 'GET', url: URL\parse('http://example.com/'));
        $handler = self::createHandler();

        $transaction = $middleware->process($connection, $request, new ClientConfiguration(), $handler);

        static::assertSame(200, $transaction->response->status);
    }

    public function testBlocksCIDRRange(): void
    {
        $middleware = new DeniedDestinationsMiddleware([
            new CIDR\Block('172.16.0.0/12'),
        ]);
        $connection = self::createConnection('172.20.1.1');
        $request = new Request(method: 'GET', url: URL\parse('http://example.com/'));
        $handler = self::createHandler();

        $this->expectException(RuntimeException::class);

        $middleware->process($connection, $request, new ClientConfiguration(), $handler);
    }
}
