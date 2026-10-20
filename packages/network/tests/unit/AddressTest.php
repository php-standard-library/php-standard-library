<?php

declare(strict_types=1);

namespace Psl\Network\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Network\Address;
use Psl\Network\SocketScheme;

final class AddressTest extends TestCase
{
    public function testIpv6HostsAreUnbracketed(): void
    {
        foreach (['::1', '[::1]'] as $host) {
            foreach ([Address::tcp($host, 80), Address::udp($host, 80)] as $address) {
                static::assertSame('::1', $address->host);
                static::assertSame($address->scheme->value . '://[::1]:80', $address->toString());
            }
        }

        static::assertSame('[socket]', Address::unix('[socket]')->host);
        static::assertSame('unix://[socket]', Address::unix('[socket]')->toString());
    }

    public function testAddress(): void
    {
        $address = Address::create(SocketScheme::Tcp, '127.0.0.2', 8080);

        static::assertSame(SocketScheme::Tcp, $address->scheme);
        static::assertSame('127.0.0.2', $address->host);
        static::assertSame(8080, $address->port);

        $address = Address::tcp('127.0.0.2', 8080);

        static::assertSame(SocketScheme::Tcp, $address->scheme);
        static::assertSame('127.0.0.2', $address->host);
        static::assertSame(8080, $address->port);

        $address = Address::tcp('127.0.0.2');

        static::assertSame(SocketScheme::Tcp, $address->scheme);
        static::assertSame('127.0.0.2', $address->host);
        static::assertSame(Address::DEFAULT_PORT, $address->port);

        $address = Address::tcp();

        static::assertSame(SocketScheme::Tcp, $address->scheme);
        static::assertSame(Address::DEFAULT_HOST, $address->host);
        static::assertSame(Address::DEFAULT_PORT, $address->port);

        $address = Address::create(SocketScheme::Unix, '/etc/foo');

        static::assertSame(SocketScheme::Unix, $address->scheme);
        static::assertSame('/etc/foo', $address->host);
        static::assertNull($address->port);

        $address = Address::unix('/etc/foo');

        static::assertSame(SocketScheme::Unix, $address->scheme);
        static::assertSame('/etc/foo', $address->host);
        static::assertNull($address->port);

        $address = Address::udp('127.0.0.1', 5353);

        static::assertSame(SocketScheme::Udp, $address->scheme);
        static::assertSame('127.0.0.1', $address->host);
        static::assertSame(5353, $address->port);
        static::assertSame('udp://127.0.0.1:5353', $address->toString());
    }
}
