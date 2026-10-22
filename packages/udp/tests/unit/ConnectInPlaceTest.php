<?php

declare(strict_types=1);

namespace Psl\UDP\Tests\Unit;

use Composer\Autoload\ClassLoader;
use Override;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\UDP;
use ReflectionClass;

use function dirname;
use function extension_loaded;
use function get_resource_id;
use function is_resource;
use function proc_close;
use function proc_open;
use function socket_addrinfo_explain;
use function socket_addrinfo_lookup;
use function socket_get_option;
use function socket_import_stream;
use function stream_get_contents;
use function var_export;

use const IPPROTO_IPV6;
use const IPV6_V6ONLY;
use const PHP_BINARY;
use const SO_BROADCAST;
use const SOCK_DGRAM;
use const SOL_SOCKET;

/**
 * With ext-sockets, connecting an unconnected UDP socket connects that same socket: it keeps its descriptor, its
 * options and the datagrams already queued on it. It connects to the address a new socket bound to its local address
 * would take, and where it cannot reach that address in place, a new socket replaces it, as without the extension.
 */
final class ConnectInPlaceTest extends TestCase
{
    /**
     * The most bytes a receive in these tests takes.
     */
    private const int MAX_BYTES = 100;

    /**
     * How long a receive may wait before the test counts it as stuck.
     */
    private const int DEADLINE_MILLISECONDS = 1_000;

    /**
     * Skip the test unless ext-sockets is loaded.
     */
    #[Override]
    protected function setUp(): void
    {
        if (!extension_loaded('sockets')) {
            static::markTestSkipped('The sockets extension is required.');
        }
    }

    /**
     * The connected socket wraps the stream of the unconnected one.
     */
    public function testConnectKeepsTheDescriptor(): void
    {
        $server = UDP\Socket::bind('127.0.0.1');
        $socket = UDP\Socket::bind('127.0.0.1');
        $stream = $socket->getStream();
        static::assertTrue(is_resource($stream));
        $local = $socket->getLocalAddress();

        $connected = $socket->connect('127.0.0.1', $server->getLocalAddress()->port);
        $connectedStream = $connected->getStream();

        static::assertTrue(is_resource($connectedStream));
        static::assertSame(get_resource_id($stream), get_resource_id($connectedStream));
        static::assertTrue($socket->isClosed());
        static::assertEquals($local, $connected->getLocalAddress());

        $connected->send('hello');
        [$data, $from] = $server->receiveFrom(self::MAX_BYTES);
        static::assertSame('hello', $data);
        static::assertSame($local->port, $from->port);

        $connected->close();
        $server->close();
    }

    /**
     * An IPv6 socket connects in place too.
     */
    public function testConnectKeepsTheDescriptorOfAnIpv6Socket(): void
    {
        try {
            $server = UDP\Socket::bind('::1');
        } catch (Network\Exception\RuntimeException) {
            static::markTestSkipped('IPv6 loopback is not available.');
        }

        $socket = UDP\Socket::bind('::1');
        $stream = $socket->getStream();
        static::assertTrue(is_resource($stream));

        $connected = $socket->connect('::1', $server->getLocalAddress()->port);
        $connectedStream = $connected->getStream();

        static::assertTrue(is_resource($connectedStream));
        static::assertSame(get_resource_id($stream), get_resource_id($connectedStream));

        $connected->send('hello');
        static::assertSame('hello', $server->receiveFrom(self::MAX_BYTES)[0]);

        $connected->close();
        $server->close();
    }

    /**
     * A dual-stack IPv6 socket connects to an IPv4 address in place, through the v4-mapped address; an IPv6-only one
     * cannot, and a new IPv4 socket replaces it, as without the extension. Either way the datagram goes through.
     */
    public function testAnIpv6SocketConnectsToAnIpv4Address(): void
    {
        try {
            $socket = UDP\Socket::bind('::');
        } catch (Network\Exception\RuntimeException) {
            static::markTestSkipped('IPv6 is not available.');
        }

        $server = UDP\Socket::bind('127.0.0.1');
        $stream = $socket->getStream();
        static::assertTrue(is_resource($stream));
        $imported = socket_import_stream($stream);
        static::assertNotFalse($imported);
        $ipv6Only = 0 !== socket_get_option($imported, IPPROTO_IPV6, IPV6_V6ONLY);
        $local = $socket->getLocalAddress();

        $connected = $socket->connect('127.0.0.1', $server->getLocalAddress()->port);
        $connectedStream = $connected->getStream();
        static::assertTrue(is_resource($connectedStream));
        static::assertTrue($socket->isClosed());

        $connected->send('hello');
        [$data, $from] = $server->receiveFrom(self::MAX_BYTES, self::deadline());
        static::assertSame('hello', $data);
        static::assertSame($connected->getLocalAddress()->port, $from->port);

        if ($ipv6Only) {
            static::assertNotSame(get_resource_id($stream), get_resource_id($connectedStream));
        } else {
            static::assertSame(get_resource_id($stream), get_resource_id($connectedStream));
            static::assertSame($local->port, $connected->getLocalAddress()->port);
            static::assertSame('::ffff:127.0.0.1', $connected->getLocalAddress()->host);
        }

        $connected->close();
        $server->close();
    }

    /**
     * An IPv6 socket bound to an IPv6 address other than `::` never reaches an IPv4 address in place (Linux makes it
     * IPv6-only when it binds, and macOS refuses the connect), so a new IPv4 socket replaces it, as without the
     * extension, and the old socket is closed.
     */
    public function testAnIpv6SocketThatCannotReachAnIpv4AddressIsReplaced(): void
    {
        try {
            $socket = UDP\Socket::bind('::1');
        } catch (Network\Exception\RuntimeException) {
            static::markTestSkipped('IPv6 loopback is not available.');
        }

        $server = UDP\Socket::bind('127.0.0.1');
        $stream = $socket->getStream();
        static::assertTrue(is_resource($stream));
        $local = $socket->getLocalAddress();

        $connected = $socket->connect('127.0.0.1', $server->getLocalAddress()->port);
        $connectedStream = $connected->getStream();

        static::assertTrue(is_resource($connectedStream));
        static::assertNotSame(get_resource_id($stream), get_resource_id($connectedStream));
        static::assertFalse(is_resource($stream));
        static::assertSame('127.0.0.1', $connected->getLocalAddress()->host);

        $connected->send('hello');
        static::assertSame('hello', $server->receiveFrom(self::MAX_BYTES, self::deadline())[0]);

        // The old socket let go of its port.
        UDP\Socket::bind('::1', $local->port)->close();

        $connected->close();
        $server->close();
    }

    /**
     * An IPv6 socket bound to an IPv4-mapped address does not connect to an IPv4 address in place: a new IPv4 socket
     * replaces it, from the source address and port the system picks, as without the extension.
     */
    public function testAnIpv4MappedSocketIsReplacedForAnIpv4Address(): void
    {
        try {
            $socket = UDP\Socket::bind('::ffff:127.0.0.1');
        } catch (Network\Exception\RuntimeException) {
            static::markTestSkipped('An IPv4-mapped address cannot be bound.');
        }

        $server = UDP\Socket::bind('127.0.0.1');
        $stream = $socket->getStream();
        static::assertTrue(is_resource($stream));

        $connected = $socket->connect('127.0.0.1', $server->getLocalAddress()->port);
        $connectedStream = $connected->getStream();

        static::assertTrue(is_resource($connectedStream));
        static::assertNotSame(get_resource_id($stream), get_resource_id($connectedStream));
        static::assertFalse(is_resource($stream));
        static::assertSame('127.0.0.1', $connected->getLocalAddress()->host);

        $connected->send('hello');
        static::assertSame('hello', $server->receiveFrom(self::MAX_BYTES, self::deadline())[0]);

        $connected->close();
        $server->close();
    }

    /**
     * An IPv4 socket connects in place to the first IPv4 address of a host name, here `localhost`, skipping its IPv6
     * addresses as a new socket bound to the IPv4 address does.
     */
    public function testAnIpv4SocketConnectsToAHostNameInPlace(): void
    {
        $server = UDP\Socket::bind('127.0.0.1');
        $socket = UDP\Socket::bind('127.0.0.1');
        $stream = $socket->getStream();
        static::assertTrue(is_resource($stream));
        $local = $socket->getLocalAddress();

        $connected = $socket->connect('localhost', $server->getLocalAddress()->port);
        $connectedStream = $connected->getStream();

        static::assertTrue(is_resource($connectedStream));
        static::assertSame(get_resource_id($stream), get_resource_id($connectedStream));
        static::assertEquals($local, $connected->getLocalAddress());

        $connected->send('hello');
        [$data, $from] = $server->receiveFrom(self::MAX_BYTES, self::deadline());
        static::assertSame('hello', $data);
        static::assertSame($local->port, $from->port);

        $connected->close();
        $server->close();
    }

    /**
     * An IPv6 socket bound to `::` connects in place to the first address of a host name in the system's order, as a
     * new socket bound to `::` does, whichever its family: here `localhost`, whose first address is `::1` on most
     * systems. An IPv6-only socket given an IPv4 first address is replaced by a new IPv4 socket instead.
     */
    public function testAnIpv6SocketConnectsToTheFirstAddressOfAHostName(): void
    {
        $results = socket_addrinfo_lookup('localhost', null, ['ai_socktype' => SOCK_DGRAM]);
        if (false === $results || [] === $results) {
            static::markTestSkipped('localhost does not resolve.');
        }

        $first = socket_addrinfo_explain($results[0])['ai_addr'];
        static::assertIsArray($first);
        $firstIpv6 = isset($first['sin6_addr']);

        try {
            $socket = UDP\Socket::bind('::');
            $server = UDP\Socket::bind($firstIpv6 ? '::1' : '127.0.0.1');
        } catch (Network\Exception\RuntimeException) {
            static::markTestSkipped('IPv6 is not available.');
        }

        $stream = $socket->getStream();
        static::assertTrue(is_resource($stream));
        $imported = socket_import_stream($stream);
        static::assertNotFalse($imported);
        $ipv6Only = 0 !== socket_get_option($imported, IPPROTO_IPV6, IPV6_V6ONLY);
        $local = $socket->getLocalAddress();

        $connected = $socket->connect('localhost', $server->getLocalAddress()->port);
        $connectedStream = $connected->getStream();
        static::assertTrue(is_resource($connectedStream));

        $connected->send('hello');
        [$data, $from] = $server->receiveFrom(self::MAX_BYTES, self::deadline());
        static::assertSame('hello', $data);
        static::assertSame($connected->getLocalAddress()->port, $from->port);

        if (!$firstIpv6 && $ipv6Only) {
            static::assertNotSame(get_resource_id($stream), get_resource_id($connectedStream));
        } else {
            static::assertSame(get_resource_id($stream), get_resource_id($connectedStream));
            static::assertSame($local->port, $connected->getLocalAddress()->port);
            static::assertSame($firstIpv6 ? '::1' : '::ffff:127.0.0.1', $connected->getLocalAddress()->host);
        }

        $connected->close();
        $server->close();
    }

    /**
     * A datagram that the peer sent before the connect is still there after it.
     */
    public function testConnectKeepsTheQueuedDatagrams(): void
    {
        $peer = UDP\Socket::bind('127.0.0.1');
        $socket = UDP\Socket::bind('127.0.0.1');

        $peer->sendTo('queued', $socket->getLocalAddress());
        static::assertSame('queued', $socket->peekFrom(self::MAX_BYTES)[0]);

        $connected = $socket->connect('127.0.0.1', $peer->getLocalAddress()->port);

        static::assertSame('queued', $connected->receive(self::MAX_BYTES, self::deadline()));

        $connected->close();
        $peer->close();
    }

    /**
     * An option set when binding, here SO_BROADCAST, still holds once connected.
     */
    public function testConnectKeepsTheSocketOptions(): void
    {
        $server = UDP\Socket::bind('127.0.0.1');
        $socket = UDP\Socket::bind('127.0.0.1', configuration: new UDP\BindConfiguration(broadcast: true));

        $connected = $socket->connect('127.0.0.1', $server->getLocalAddress()->port);
        $stream = $connected->getStream();
        static::assertTrue(is_resource($stream));
        $imported = socket_import_stream($stream);
        static::assertNotFalse($imported);

        static::assertNotSame(0, socket_get_option($imported, SOL_SOCKET, SO_BROADCAST));

        $connected->close();
        $server->close();
    }

    /**
     * A connect of an IPv4 socket to an IPv6 address fails as it does without the extension, since a new socket bound
     * to the IPv4 address skips that address too, and closes the socket.
     */
    public function testFailedConnectClosesTheSocket(): void
    {
        $socket = UDP\Socket::bind('127.0.0.1');

        $failure = null;
        try {
            $socket->connect('::1', 1);
        } catch (Network\Exception\RuntimeException $e) {
            $failure = $e;
        }

        static::assertNotNull($failure, 'The connect did not fail.');
        static::assertSame(0, $failure->getCode());
        static::assertStringContainsString('Failed to connect UDP socket to udp://[::1]:1', $failure->getMessage());
        static::assertTrue($socket->isClosed());
    }

    /**
     * Without ext-sockets, connecting still works, through a new socket bound to the same local address.
     */
    public function testConnectWithoutTheSocketsExtensionStillConnects(): void
    {
        $loader = new ReflectionClass(ClassLoader::class)->getFileName();
        static::assertIsString($loader);

        $script = 'require ' . var_export(dirname($loader, 2) . '/autoload.php', true) . ';' . <<<'PHP'
        use Psl\UDP;

        $server = UDP\Socket::bind('127.0.0.1');
        $socket = UDP\Socket::bind('127.0.0.1');
        $port = $socket->getLocalAddress()->port;
        $connected = $socket->connect('127.0.0.1', $server->getLocalAddress()->port);
        $connected->send('hello');
        [$data, $from] = $server->receiveFrom(100);
        $server->sendTo('world', $from);
        echo $data, ' ', $connected->receive(100), ' ', $from->port === $port ? 'same port' : 'other port', "\n";
        PHP;

        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=socket_connect', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        static::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        proc_close($process);

        static::assertSame("hello world same port\n", $output, (string) $errors);
    }

    /**
     * A token that ends a receive stuck for longer than the test allows.
     */
    private static function deadline(): Async\CancellationTokenInterface
    {
        return new Async\TimeoutCancellationToken(Duration::milliseconds(self::DEADLINE_MILLISECONDS));
    }
}
