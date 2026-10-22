<?php

declare(strict_types=1);

namespace Psl\UDP\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Psl\UDP;
use ReflectionClass;

use function dirname;
use function proc_close;
use function proc_open;
use function str_repeat;
use function stream_get_contents;
use function var_export;

use const PHP_BINARY;

/**
 * A receive or peek with a buffer shorter than the datagram returns the datagram's prefix on every platform: the
 * receive discards the rest, and the peek keeps the whole datagram queued.
 */
final class TruncationTest extends TestCase
{
    /**
     * The length of the long datagram.
     */
    private const int DATAGRAM_LENGTH = 50;

    /**
     * The length of the short buffer.
     */
    private const int SHORT_BUFFER = 10;

    /**
     * The most bytes a receive of a whole datagram takes.
     */
    private const int MAX_BYTES = 100;

    /**
     * A connected receive returns the prefix of a long datagram, and the next receive gets the next datagram.
     */
    public function testConnectedReceiveReturnsThePrefixOfALongDatagram(): void
    {
        $server = UDP\Socket::bind('127.0.0.1');
        $client = UDP\connect('127.0.0.1', $server->getLocalAddress()->port);
        $server->sendTo(str_repeat('A', self::DATAGRAM_LENGTH), $client->getLocalAddress());
        $server->sendTo('next', $client->getLocalAddress());

        static::assertSame(str_repeat('A', self::SHORT_BUFFER), $client->receive(self::SHORT_BUFFER));
        static::assertSame('next', $client->receive(self::MAX_BYTES));

        $client->close();
        $server->close();
    }

    /**
     * A connected peek returns the prefix of a long datagram, and leaves the whole datagram queued.
     */
    public function testConnectedPeekReturnsThePrefixAndKeepsTheDatagram(): void
    {
        $server = UDP\Socket::bind('127.0.0.1');
        $client = UDP\connect('127.0.0.1', $server->getLocalAddress()->port);
        $server->sendTo(str_repeat('A', self::DATAGRAM_LENGTH), $client->getLocalAddress());

        static::assertSame(str_repeat('A', self::SHORT_BUFFER), $client->peek(self::SHORT_BUFFER));
        static::assertSame(str_repeat('A', self::DATAGRAM_LENGTH), $client->receive(self::MAX_BYTES));

        $client->close();
        $server->close();
    }

    /**
     * Without ext-sockets, where a connected socket receives through its stream, as on Windows, a short receive and
     * a short peek return the prefix of a long datagram too.
     */
    public function testConnectedReceiveThroughTheStreamReturnsThePrefixOfALongDatagram(): void
    {
        $loader = new ReflectionClass(ClassLoader::class)->getFileName();
        static::assertIsString($loader);

        $script = 'require ' . var_export(dirname($loader, 2) . '/autoload.php', true) . ';' . <<<'PHP'
        use Psl\UDP;

        $server = UDP\Socket::bind('127.0.0.1');
        $client = UDP\connect('127.0.0.1', $server->getLocalAddress()->port);
        $server->sendTo(str_repeat('A', 50), $client->getLocalAddress());
        $server->sendTo('next', $client->getLocalAddress());
        echo $client->peek(10), ' ', $client->receive(10), ' ', $client->receive(100), "\n";
        PHP;

        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=socket_recv', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        static::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        proc_close($process);

        static::assertSame('AAAAAAAAAA AAAAAAAAAA next' . "\n", $output, (string) $errors);
    }
}
