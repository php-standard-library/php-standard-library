<?php

declare(strict_types=1);

namespace Psl\Unix\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Network;
use Psl\OS;
use Psl\Unix;

use function bin2hex;
use function file_exists;
use function random_bytes;
use function str_pad;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function unlink;

/**
 * A socket path longer than the system can hold is rejected before it reaches PHP, which would cut it short and use
 * the rest; a path of exactly the longest length works.
 */
final class PathLengthTest extends TestCase
{
    /**
     * The longest filesystem socket path on macOS and the BSDs: `sun_path` holds 104 bytes, the NUL byte included.
     */
    private const int BSD_MAXIMUM = 103;

    /**
     * The longest filesystem socket path on Linux and Solaris: `sun_path` holds 108 bytes, the NUL byte included.
     */
    private const int LINUX_MAXIMUM = 107;

    /**
     * The longest name in the Linux abstract namespace: the whole of `sun_path`, the leading NUL byte included.
     */
    private const int LINUX_ABSTRACT_MAXIMUM = 108;

    /**
     * The length of the random part of each socket path.
     */
    private const int RANDOM_BYTES = 4;

    /**
     * A path of the longest length the system holds can be listened on and connected to.
     */
    public function testThePathOfTheLongestLengthWorks(): void
    {
        $path = self::path(self::maximum());
        $listener = Unix\listen($path);

        try {
            static::assertSame($path, $listener->getLocalAddress()->host);

            [$server, $client] = Async\concurrently([
                $listener->accept(...),
                static fn(): Unix\StreamInterface => Unix\connect($path),
            ]);

            $client->writeAll('ping');
            static::assertSame('ping', $server->readFixedSize(4));
            static::assertSame($path, $client->getPeerAddress()->host);

            $client->close();
            $server->close();
        } finally {
            $listener->close();
            @unlink($path);
        }
    }

    /**
     * Each entry point rejects a path one byte too long, and binds nothing at the path PHP would have cut it to.
     *
     * @param (Closure(non-empty-string): mixed) $use
     */
    #[DataProvider('provideEntryPoints')]
    public function testAPathOneByteTooLongIsRejected(Closure $use): void
    {
        $path = self::path(self::maximum() + 1);
        $truncated = substr($path, 0, self::maximum());

        $failure = null;
        try {
            $use($path);
        } catch (Network\Exception\InvalidArgumentException $e) {
            $failure = $e;
        } finally {
            $bound = file_exists($truncated);
            if ($bound) {
                unlink($truncated);
            }
        }

        static::assertNotNull($failure, 'The path was not rejected.');
        static::assertSame(
            'Unix socket path must not be longer than ' . self::maximum() . ' bytes on this system.',
            $failure->getMessage(),
        );
        static::assertFalse($bound);
    }

    /**
     * The entry points that take a socket path.
     *
     * @return iterable<string, array{(Closure(non-empty-string): mixed)}>
     */
    public static function provideEntryPoints(): iterable
    {
        yield 'listen()' => [static fn(string $path): mixed => Unix\listen($path)];
        yield 'connect()' => [static fn(string $path): mixed => Unix\connect($path)];
        yield 'Socket::bind()' => [static fn(string $path): mixed => Unix\Socket::create()->bind($path)];
        yield 'Socket::connect()' => [static fn(string $path): mixed => Unix\Socket::create()->connect($path)];
    }

    /**
     * On Linux, a name in the abstract namespace may fill the whole of `sun_path`, and no more.
     */
    public function testAnAbstractNameMayFillTheWholeField(): void
    {
        if (OS\OperatingSystemFamily::Linux !== OS\family()) {
            static::markTestSkipped('The abstract socket namespace is only supported on Linux.');
        }

        $name =
            "\0" . str_pad('psl-' . bin2hex(random_bytes(self::RANDOM_BYTES)), self::LINUX_ABSTRACT_MAXIMUM - 1, 'x');
        $listener = Unix\listen($name);
        static::assertSame($name, $listener->getLocalAddress()->host);
        $listener->close();

        $this->expectException(Network\Exception\InvalidArgumentException::class);

        Unix\listen($name . 'x');
    }

    /**
     * The longest filesystem socket path on this system, or skip the test if PSL does not know it.
     *
     * @return positive-int
     */
    private static function maximum(): int
    {
        return match (OS\family()) {
            OS\OperatingSystemFamily::Linux, OS\OperatingSystemFamily::Solaris => self::LINUX_MAXIMUM,
            OS\OperatingSystemFamily::Darwin, OS\OperatingSystemFamily::BSD => self::BSD_MAXIMUM,
            default => static::markTestSkipped('The socket path limit of this system is not known.'),
        };
    }

    /**
     * A fresh path of exactly the given length in the temporary directory.
     *
     * @param positive-int $length
     *
     * @return non-empty-string
     */
    private static function path(int $length): string
    {
        $prefix = sys_get_temp_dir() . '/psl-' . bin2hex(random_bytes(self::RANDOM_BYTES)) . '-';
        if (strlen($prefix) >= $length) {
            static::markTestSkipped('The temporary directory\'s path is too long for this test.');
        }

        return str_pad($prefix, $length, 'x');
    }
}
