<?php

declare(strict_types=1);

namespace Psl\Unix\Tests\Unit;

use Composer\Autoload\ClassLoader;
use Override;
use PHPUnit\Framework\TestCase;
use Psl\Network;
use Psl\OS;
use Psl\Unix;
use ReflectionClass;

use function bin2hex;
use function dirname;
use function extension_loaded;
use function file_exists;
use function proc_close;
use function proc_open;
use function random_bytes;
use function str_repeat;
use function stream_get_contents;
use function sys_get_temp_dir;
use function touch;
use function unlink;
use function var_export;

use const PHP_BINARY;
use const SOCKET_EADDRINUSE;

/**
 * A Unix socket that `bind()` named is bound to that path before it connects, so its peer sees the path.
 */
final class BoundConnectTest extends TestCase
{
    /**
     * The number of random bytes in each socket path, which appear hex-encoded.
     */
    private const int NAME_BYTES = 8;

    /**
     * The size of `sun_path` on Linux.
     */
    private const int LINUX_SUN_PATH_SIZE = 108;

    /**
     * The path of the listener the test uses.
     *
     * @var non-empty-string
     */
    private string $path;

    /**
     * The path the test binds the client to.
     *
     * @var non-empty-string
     */
    private string $clientPath;

    /**
     * The listener the test uses.
     */
    private Unix\ListenerInterface $listener;

    /**
     * Listen on a fresh path, and pick a fresh path for the client.
     */
    #[Override]
    protected function setUp(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Unix sockets are not supported on Windows platform.');
        }

        $this->path = self::path();
        $this->clientPath = self::path();
        $this->listener = Unix\listen($this->path);
    }

    /**
     * Close the listener, and remove both paths.
     */
    #[Override]
    protected function tearDown(): void
    {
        if (OS\is_windows()) {
            return;
        }

        $this->listener->close();
        @unlink($this->path);
        @unlink($this->clientPath);
    }

    /**
     * The client connects from the path it was bound to: the accepted stream's peer address and the client's local
     * address are that path.
     */
    public function testABoundClientConnectsFromItsPath(): void
    {
        self::requireTheSocketsExtension();

        $socket = Unix\Socket::create();
        $socket->bind($this->clientPath);
        $client = $socket->connect($this->path);
        $server = $this->listener->accept();

        static::assertSame($this->clientPath, $client->getLocalAddress()->host);
        static::assertSame($this->path, $client->getPeerAddress()->host);
        static::assertSame($this->clientPath, $server->getPeerAddress()->host);
        static::assertTrue(file_exists($this->clientPath));

        $client->writeAll('ping');
        static::assertSame('ping', $server->readFixedSize(4));
        $server->writeAll('pong');
        static::assertSame('pong', $client->readFixedSize(4));

        $client->close();
        $server->close();
    }

    /**
     * Binding to a path that is taken fails the connect with EADDRINUSE.
     */
    public function testBindingAPathThatIsTakenFails(): void
    {
        self::requireTheSocketsExtension();

        touch($this->clientPath);
        $socket = Unix\Socket::create();
        $socket->bind($this->clientPath);

        $failure = null;
        try {
            $socket->connect($this->path);
        } catch (Network\Exception\RuntimeException $e) {
            $failure = $e;
        }

        static::assertNotNull($failure, 'The connect did not fail.');
        static::assertSame(SOCKET_EADDRINUSE, $failure->getCode());
        static::assertStringContainsString(
            'Failed to bind Unix socket to "' . $this->clientPath,
            $failure->getMessage(),
        );
    }

    /**
     * On Linux, an abstract name that fills the whole of `sun_path` can name a listener, but not a bound client:
     * ext-sockets keeps one byte of the field free.
     */
    public function testAnAbstractNameThatFillsTheFieldCannotNameABoundClient(): void
    {
        self::requireTheSocketsExtension();
        if (OS\OperatingSystemFamily::Linux !== OS\family()) {
            static::markTestSkipped('The abstract socket namespace is only supported on Linux.');
        }

        $socket = Unix\Socket::create();
        $socket->bind("\0" . str_repeat('x', self::LINUX_SUN_PATH_SIZE - 1));

        $this->expectException(Network\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('to bind a client on this system');

        $socket->connect($this->path);
    }

    /**
     * Without ext-sockets, a bound socket's connect fails, naming the extension, before it creates a socket; an
     * unbound socket still connects.
     */
    public function testWithoutTheSocketsExtensionABoundConnectFails(): void
    {
        $loader = new ReflectionClass(ClassLoader::class)->getFileName();
        static::assertIsString($loader);

        $script = 'require ' . var_export(dirname($loader, 2) . '/autoload.php', true) . ';' . <<<PHP
        \$path = {$this->export($this->path)};
        \$clientPath = {$this->export($this->clientPath)};
        PHP . <<<'PHP'

        use Psl\Unix;

        $socket = Unix\Socket::create();
        $socket->bind($clientPath);
        try {
            $socket->connect($path);
            echo "bound: connected\n";
        } catch (Psl\Network\Exception\RuntimeException $e) {
            echo 'bound: ', $e->getMessage(), ' ', file_exists($clientPath) ? 'bound' : 'not bound', "\n";
        }

        $client = Unix\Socket::create()->connect($path);
        echo 'unbound: ', $client->getPeerAddress()->host === $path ? 'connected' : 'wrong peer', "\n";
        PHP;

        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=socket_bind', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        static::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        proc_close($process);

        static::assertSame(
            'bound: Connecting a Unix socket bound to "'
            . $this->clientPath
            . "\" needs ext-sockets. not bound\nunbound: connected\n",
            $output,
            (string) $errors,
        );
    }

    /**
     * Skip the test unless ext-sockets is loaded.
     */
    private static function requireTheSocketsExtension(): void
    {
        if (!extension_loaded('sockets')) {
            static::markTestSkipped('The sockets extension is required to bind a Unix client.');
        }
    }

    /**
     * The PHP source of the given string.
     */
    private function export(string $value): string
    {
        return var_export($value, true);
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
