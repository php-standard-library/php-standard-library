<?php

declare(strict_types=1);

namespace Psl\Unix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\OS;
use Psl\Unix;

use function bin2hex;
use function extension_loaded;
use function random_bytes;
use function socket_bind;
use function socket_close;
use function socket_connect;
use function socket_create;
use function str_starts_with;
use function sys_get_temp_dir;
use function unlink;

use const AF_UNIX;
use const SOCK_STREAM;

/**
 * A Unix stream reports the names its two sockets really have: the name a socket is bound to, or the unnamed address
 * `unix://<anonymous>` for one that is not bound, never the name of the other end.
 */
final class AddressTest extends TestCase
{
    /**
     * The number of random bytes in each socket path, which appear hex-encoded.
     */
    private const int NAME_BYTES = 8;

    /**
     * The address that stands for an unnamed socket.
     */
    private const string UNNAMED = 'unix://<anonymous>';

    /**
     * A client that did not bind is unnamed on both ends of the connection: its own local address, and the peer
     * address of the stream the listener accepted.
     */
    public function testAnUnboundClientIsUnnamed(): void
    {
        self::requireUnixSockets();

        $path = self::path();
        $listener = Unix\listen($path);

        try {
            [$server, $client] = Async\concurrently([
                $listener->accept(...),
                static fn(): Unix\StreamInterface => Unix\connect($path),
            ]);

            static::assertSame(self::UNNAMED, $client->getLocalAddress()->toString());
            static::assertSame('unix://' . $path, $client->getPeerAddress()->toString());
            static::assertSame('unix://' . $path, $server->getLocalAddress()->toString());
            static::assertSame(self::UNNAMED, $server->getPeerAddress()->toString());
            static::assertFalse(str_starts_with($server->getPeerAddress()->host, "\0"));

            $client->close();
            $server->close();
        } finally {
            $listener->close();
            @unlink($path);
        }
    }

    /**
     * The peer address of an accepted stream is the name the client's socket is bound to.
     */
    public function testTheListenerReportsTheNameABoundClientHas(): void
    {
        self::requireUnixSockets();
        if (!extension_loaded('sockets')) {
            static::markTestSkipped('The sockets extension is required to bind a client.');
        }

        $path = self::path();
        $clientPath = self::path();
        $listener = Unix\listen($path);
        $client = socket_create(AF_UNIX, SOCK_STREAM, 0);
        static::assertNotFalse($client);

        try {
            static::assertTrue(socket_bind($client, $clientPath));
            static::assertTrue(socket_connect($client, $path));

            $server = $listener->accept();

            static::assertSame('unix://' . $clientPath, $server->getPeerAddress()->toString());
            static::assertSame('unix://' . $path, $server->getLocalAddress()->toString());

            $server->close();
        } finally {
            socket_close($client);
            $listener->close();
            @unlink($path);
            @unlink($clientPath);
        }
    }

    /**
     * Skip the test on Windows, which has no Unix domain sockets here.
     */
    private static function requireUnixSockets(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Unix sockets are not supported on Windows platform.');
        }
    }

    /**
     * A fresh socket path in the temporary directory.
     *
     * @return non-empty-string
     */
    private static function path(): string
    {
        return sys_get_temp_dir() . '/psl-' . bin2hex(random_bytes(self::NAME_BYTES)) . '.sock';
    }
}
