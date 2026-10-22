<?php

declare(strict_types=1);

namespace Psl\UDP\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Network;
use Psl\UDP;
use ReflectionClass;
use Revolt\EventLoop;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function dirname;
use function extension_loaded;
use function fwrite;
use function getrusage;
use function is_resource;
use function proc_close;
use function proc_open;
use function socket_connect;
use function socket_import_stream;
use function sort;
use function stream_get_contents;
use function strval;
use function var_export;

use const PHP_BINARY;

/**
 * Closing a UDP socket ends every call waiting on it, and a receive that finds no datagram after its socket became
 * readable waits for the next one, without spinning while an error keeps the socket readable.
 */
final class WaitingCallsTest extends TestCase
{
    /**
     * The most bytes a receive in these tests takes.
     */
    private const int MAX_BYTES = 100;

    /**
     * How many calls wait at once in the close tests.
     */
    private const int WAITING_CALLS = 3;

    /**
     * How long a wait in the spin tests may last before its token ends it.
     */
    private const int DEADLINE_MILLISECONDS = 400;

    /**
     * The processor time above which a wait of {@see DEADLINE_MILLISECONDS} counts as spinning: half of it, since a
     * wait that sleeps uses next to none.
     */
    private const int SPIN_MICROSECONDS = 200_000;

    /**
     * Microseconds in a second, to add up `getrusage()`'s seconds and microseconds.
     */
    private const int MICROSECONDS_PER_SECOND = 1_000_000;

    /**
     * Closing an unconnected socket fails every waiting receive and peek with `AlreadyClosedException`, and leaves no
     * watcher behind.
     */
    public function testCloseFailsEveryWaitingCall(): void
    {
        $socket = UDP\Socket::bind('127.0.0.1');
        $callbacks = self::enabledCallbacks();

        $calls = [];
        for ($i = 0; $i < self::WAITING_CALLS; $i++) {
            $calls[] = Async\run(static fn(): array => $socket->receiveFrom(self::MAX_BYTES));
        }

        $calls[] = Async\run(static fn(): array => $socket->peekFrom(self::MAX_BYTES));

        Async\later();
        $socket->close();

        foreach ($calls as $call) {
            static::assertInstanceOf(IO\Exception\AlreadyClosedException::class, self::failure($call));
        }

        static::assertSame($callbacks, self::enabledCallbacks());
    }

    /**
     * Closing a connected socket fails every waiting receive and peek with `AlreadyClosedException`.
     */
    public function testCloseFailsEveryWaitingCallOfAConnectedSocket(): void
    {
        $server = UDP\Socket::bind('127.0.0.1');
        $client = UDP\connect('127.0.0.1', $server->getLocalAddress()->port);
        $callbacks = self::enabledCallbacks();

        $calls = [
            Async\run(static fn(): string => $client->receive(self::MAX_BYTES)),
            Async\run(static fn(): string => $client->receive(self::MAX_BYTES)),
            Async\run(static fn(): string => $client->peek(self::MAX_BYTES)),
        ];

        Async\later();
        $client->close();

        foreach ($calls as $call) {
            static::assertInstanceOf(IO\Exception\AlreadyClosedException::class, self::failure($call));
        }

        static::assertSame($callbacks, self::enabledCallbacks());
        $server->close();
    }

    /**
     * A receive that closes the socket once it has its datagram ends the other waiting receive, which the same
     * datagram had woken up.
     */
    public function testAReceiveThatClosesTheSocketEndsTheOtherWaitingReceive(): void
    {
        $socket = UDP\Socket::bind('127.0.0.1');
        $sender = UDP\Socket::bind('127.0.0.1');

        $calls = [];
        for ($i = 0; $i < 2; $i++) {
            $calls[] = Async\run(static function () use ($socket): string {
                [$data] = $socket->receiveFrom(self::MAX_BYTES);
                $socket->close();

                return $data;
            });
        }

        Async\later();
        $sender->sendTo('only', $socket->getLocalAddress());

        $outcomes = [];
        foreach ($calls as $call) {
            try {
                $outcomes[] = $call->await();
            } catch (IO\Exception\AlreadyClosedException) {
                $outcomes[] = 'closed';
            }
        }

        sort($outcomes);
        static::assertSame(['closed', 'only'], $outcomes);
        $sender->close();
    }

    /**
     * Connecting fails every call waiting on the unconnected socket with `AlreadyClosedException`, since that socket is
     * closed.
     */
    public function testConnectFailsTheCallsWaitingOnTheUnconnectedSocket(): void
    {
        $server = UDP\Socket::bind('127.0.0.1');
        $socket = UDP\Socket::bind('127.0.0.1');

        $call = Async\run(static fn(): array => $socket->receiveFrom(self::MAX_BYTES));
        Async\later();

        $connected = $socket->connect('127.0.0.1', $server->getLocalAddress()->port);

        static::assertInstanceOf(IO\Exception\AlreadyClosedException::class, self::failure($call));

        $connected->send('hello');
        static::assertSame('hello', $server->receiveFrom(self::MAX_BYTES)[0]);

        $connected->close();
        $server->close();
    }

    /**
     * Of two receives waiting for one datagram, one gets it, and the other waits for the next one instead of failing.
     */
    public function testASecondReceiveWaitsForTheNextDatagram(): void
    {
        $socket = UDP\Socket::bind('127.0.0.1');
        $sender = UDP\Socket::bind('127.0.0.1');

        $first = Async\run(static fn(): string => $socket->receiveFrom(self::MAX_BYTES)[0]);
        $second = Async\run(static fn(): string => $socket->receiveFrom(self::MAX_BYTES)[0]);
        Async\later();

        $sender->sendTo('one', $socket->getLocalAddress());
        $done = Async\first([$first, $second]);
        Async\later();

        static::assertSame('one', $done);
        static::assertFalse($first->isComplete() && $second->isComplete(), 'Both receives ended on one datagram.');

        $sender->sendTo('two', $socket->getLocalAddress());
        $outcomes = [$first->await(), $second->await()];
        sort($outcomes);

        static::assertSame(['one', 'two'], $outcomes);

        $socket->close();
        $sender->close();
    }

    /**
     * Without ext-sockets, a connected socket's receive waits again where it finds no datagram: after another
     * receive took it, and after an ICMP port-unreachable message, which it cannot report.
     */
    public function testConnectedReceiveWithoutTheSocketsExtensionWaitsAgain(): void
    {
        $script = <<<'PHP'
        use Psl\Async;
        use Psl\DateTime\Duration;
        use Psl\UDP;

        $closed = UDP\Socket::bind('127.0.0.1');
        $port = $closed->getLocalAddress()->port;
        $closed->close();

        $client = UDP\connect('127.0.0.1', $port);
        $client->send('ping');
        try {
            $client->receive(100, new Async\TimeoutCancellationToken(Duration::milliseconds(200)));
            echo "unreachable: received\n";
        } catch (Async\Exception\CancelledException) {
            echo "unreachable: waited\n";
        } catch (Psl\Network\Exception\RuntimeException $e) {
            echo 'unreachable: failed ', $e->getMessage(), "\n";
        }

        $server = UDP\Socket::bind('127.0.0.1', $port);
        $first = Async\run(static fn(): string => $client->receive(100));
        $second = Async\run(static fn(): string => $client->receive(100));
        Async\later();
        $server->sendTo('one', $client->getLocalAddress());
        Async\first([$first, $second]);
        Async\later();
        $server->sendTo('two', $client->getLocalAddress());
        try {
            $outcomes = [$first->await(), $second->await()];
            sort($outcomes);
            echo 'receives: ', implode(' ', $outcomes), "\n";
        } catch (Psl\Network\Exception\RuntimeException $e) {
            echo 'receives: failed ', $e->getMessage(), "\n";
        }
        PHP;

        [$output, $errors] = self::runWithoutSocketRecv($script);

        static::assertSame("unreachable: waited\nreceives: one two\n", $output, $errors);
    }

    /**
     * Without ext-sockets, a connected socket's peek after an ICMP port-unreachable message does not spin: on BSD
     * systems the peek leaves the error pending, so the socket stays readable and the peek fails, and elsewhere it
     * waits again. Either way the socket stays usable.
     */
    public function testConnectedPeekWithoutTheSocketsExtensionDoesNotSpin(): void
    {
        $script = <<<'PHP'
        use Psl\Async;
        use Psl\DateTime\Duration;
        use Psl\UDP;

        [, $maxBytes, $deadline, $spin, $perSecond] = array_map(intval(...), $argv);

        $closed = UDP\Socket::bind('127.0.0.1');
        $port = $closed->getLocalAddress()->port;
        $closed->close();

        $client = UDP\connect('127.0.0.1', $port);
        $client->send('ping');
        $before = getrusage();
        try {
            $client->peek($maxBytes, new Async\TimeoutCancellationToken(Duration::milliseconds($deadline)));
            echo "unreachable: peeked\n";
        } catch (Async\Exception\CancelledException) {
            echo "unreachable: waited\n";
        } catch (Psl\Network\Exception\RuntimeException $e) {
            echo 'unreachable: failed ', $e->getCode(), "\n";
        }

        $after = getrusage();
        $cpu = static fn(array $usage): int => ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * $perSecond
            + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'];
        echo $cpu($after) - $cpu($before) < $spin ? "cpu: idle\n" : "cpu: spun\n";

        $server = UDP\Socket::bind('127.0.0.1', $port);
        $server->sendTo('one', $client->getLocalAddress());
        $token = new Async\TimeoutCancellationToken(Duration::milliseconds($deadline));
        echo 'received: ', $client->receive($maxBytes, $token), "\n";
        PHP;

        [$output, $errors] = self::runWithoutSocketRecv($script, [
            self::MAX_BYTES,
            self::DEADLINE_MILLISECONDS,
            self::SPIN_MICROSECONDS,
            self::MICROSECONDS_PER_SECOND,
        ]);

        static::assertContains(
            $output,
            ["unreachable: failed 0\ncpu: idle\nreceived: one\n", "unreachable: waited\ncpu: idle\nreceived: one\n"],
            $errors,
        );
    }

    /**
     * An unconnected socket's peek that finds an error pending does not spin, although the socket stays readable:
     * it fails, or waits again where the peek consumes the error. Connecting the OS socket behind the wrapper's back
     * lets an ICMP port-unreachable message reach it, as WSAECONNRESET reaches an unconnected socket on Windows.
     */
    public function testUnconnectedPeekWithAnErrorPendingDoesNotSpin(): void
    {
        if (!extension_loaded('sockets')) {
            static::markTestSkipped('The sockets extension is required.');
        }

        $closed = UDP\Socket::bind('127.0.0.1');
        $port = $closed->getLocalAddress()->port;
        $closed->close();

        $socket = UDP\Socket::bind('127.0.0.1');
        $stream = $socket->getStream();
        static::assertTrue(is_resource($stream));
        $imported = socket_import_stream($stream);
        static::assertNotFalse($imported);
        static::assertTrue(socket_connect($imported, '127.0.0.1', $port));
        static::assertNotFalse(fwrite($stream, 'ping'));

        $before = self::cpuMicroseconds();
        $outcome = 'peeked';
        try {
            $socket->peekFrom(self::MAX_BYTES, self::deadline());
        } catch (Async\Exception\CancelledException) {
            $outcome = 'waited';
        } catch (Network\Exception\RuntimeException) {
            $outcome = 'failed';
        }

        static::assertContains($outcome, ['failed', 'waited']);
        static::assertLessThan(self::SPIN_MICROSECONDS, self::cpuMicroseconds() - $before, 'The peek spun.');

        $socket->close();
    }

    /**
     * Run a script in a PHP process whose `socket_recv()` is disabled, so that a connected socket reads through its
     * stream, and return what it printed to its standard output and error.
     *
     * @param list<int> $arguments The values the script reads from `$argv`, after the script's own name.
     *
     * @return array{string, string}
     */
    private static function runWithoutSocketRecv(string $script, array $arguments = []): array
    {
        $loader = new ReflectionClass(ClassLoader::class)->getFileName();
        static::assertIsString($loader);

        $process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'disable_functions=socket_recv',
                '-r',
                'require ' . var_export(dirname($loader, 2) . '/autoload.php', true) . ';' . $script,
                '--',
                ...array_map(strval(...), $arguments),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        static::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        proc_close($process);

        return [(string) $output, (string) $errors];
    }

    /**
     * A token that ends a wait after {@see DEADLINE_MILLISECONDS}.
     */
    private static function deadline(): Async\CancellationTokenInterface
    {
        return new Async\TimeoutCancellationToken(Duration::milliseconds(self::DEADLINE_MILLISECONDS));
    }

    /**
     * The processor time this process has used, in user and system mode, in microseconds.
     */
    private static function cpuMicroseconds(): int
    {
        $usage = getrusage();

        return (
            (((int) $usage['ru_utime.tv_sec'] + (int) $usage['ru_stime.tv_sec']) * self::MICROSECONDS_PER_SECOND)
            + (int) $usage['ru_utime.tv_usec']
            + (int) $usage['ru_stime.tv_usec']
        );
    }

    /**
     * Await the given call, and return the exception it failed with.
     *
     * @param Async\Awaitable<mixed> $call
     */
    private static function failure(Async\Awaitable $call): Throwable
    {
        try {
            $call->await();
        } catch (Throwable $e) {
            return $e;
        }

        static::fail('The call did not fail.');
    }

    /**
     * The event loop callbacks that are enabled.
     *
     * @return list<string>
     */
    private static function enabledCallbacks(): array
    {
        return array_values(array_filter(EventLoop::getIdentifiers(), EventLoop::isEnabled(...)));
    }
}
