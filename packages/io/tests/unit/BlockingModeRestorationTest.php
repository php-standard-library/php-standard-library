<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Psl\IO;
use ReflectionClass;

use function dirname;
use function fclose;
use function file_put_contents;
use function fopen;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function stream_get_meta_data;
use function stream_set_blocking;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use function var_export;

use const PHP_BINARY;
use const PHP_OS_FAMILY;

/**
 * A handle that makes a stream it does not own nonblocking, or a duplicate of a standard stream, puts back the mode
 * the stream had once the last handle over it is released, or at the end of an orderly shutdown.
 *
 * `O_NONBLOCK` belongs to the open file description, which other processes may share: a process that left its
 * standard input nonblocking made the shell that started it fail with EAGAIN. The tests use regular files, whose
 * `blocked` metadata reads the description's flags, as plain-file streams do.
 */
final class BlockingModeRestorationTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('Plain-file streams cannot be made nonblocking on Windows.');
        }

        $file = tempnam(sys_get_temp_dir(), 'psl-blocking-');
        static::assertIsString($file);
        file_put_contents($file, "hello\n");
        $this->file = $file;
    }

    protected function tearDown(): void
    {
        if ('' !== $this->file) {
            unlink($this->file);
        }
    }

    public function testDroppingABorrowingHandleRestoresTheBlockingMode(): void
    {
        $stream = $this->open();

        $handle = new IO\ReadStreamHandle($stream);
        static::assertFalse(stream_get_meta_data($stream)['blocked']);
        static::assertSame("hello\n", $handle->read());

        unset($handle);

        // The stream stayed nonblocking for whoever used it next.
        static::assertTrue(stream_get_meta_data($stream)['blocked']);
        fclose($stream);
    }

    public function testEveryBorrowingHandleRestoresTheBlockingMode(): void
    {
        foreach ([
            static fn(mixed $stream): object => new IO\ReadStreamHandle($stream),
            static fn(mixed $stream): object => new IO\WriteStreamHandle($stream),
            static fn(mixed $stream): object => new IO\ReadWriteStreamHandle($stream),
            static fn(mixed $stream): object => new IO\SeekStreamHandle($stream),
            static fn(mixed $stream): object => new IO\SeekReadStreamHandle($stream),
            static fn(mixed $stream): object => new IO\SeekWriteStreamHandle($stream),
            static fn(mixed $stream): object => new IO\SeekReadWriteStreamHandle($stream),
        ] as $index => $create) {
            $stream = fopen($this->file, 'r+');
            static::assertIsResource($stream);

            $handle = $create($stream);
            static::assertFalse(stream_get_meta_data($stream)['blocked'], (string) $index);
            unset($handle);

            static::assertTrue(stream_get_meta_data($stream)['blocked'], (string) $index);
            fclose($stream);
        }
    }

    public function testAStreamThatWasNonblockingStaysNonblocking(): void
    {
        $stream = $this->open();
        stream_set_blocking($stream, false);

        $handle = new IO\ReadStreamHandle($stream);
        unset($handle);

        static::assertFalse(stream_get_meta_data($stream)['blocked']);
        fclose($stream);
    }

    public function testTheModeIsRestoredOnlyOnceTheLastHandleIsReleased(): void
    {
        $stream = $this->open();

        $first = new IO\ReadStreamHandle($stream);
        // The second handle finds the stream nonblocking already: it must not record that as the mode to put back.
        $second = new IO\ReadStreamHandle($stream);

        unset($first);
        static::assertFalse(stream_get_meta_data($stream)['blocked']);

        unset($second);
        static::assertTrue(stream_get_meta_data($stream)['blocked']);
        fclose($stream);
    }

    public function testAnOwningHandleLeavesTheModeAlone(): void
    {
        $memberships = IO\Internal\BlockingModeRegistry::count();
        $stream = $this->open();

        // The handle closes the stream it owns, so there is no stream to put the mode back on.
        $owned = new IO\CloseReadStreamHandle($stream);
        static::assertSame($memberships, IO\Internal\BlockingModeRegistry::count());

        $owned->close();
        static::assertSame($memberships, IO\Internal\BlockingModeRegistry::count());
    }

    public function testReleasedHandlesLeaveNothingInTheRegistry(): void
    {
        $memberships = IO\Internal\BlockingModeRegistry::count();
        $stream = fopen($this->file, 'r+');
        static::assertIsResource($stream);

        for ($i = 0; $i < 100; $i++) {
            $handle = new IO\ReadWriteStreamHandle($stream);
            static::assertSame($memberships + 1, IO\Internal\BlockingModeRegistry::count());
            unset($handle);
        }

        static::assertSame($memberships, IO\Internal\BlockingModeRegistry::count());
        fclose($stream);
    }

    public function testAnExitingProcessRestoresItsStandardInput(): void
    {
        $stream = $this->open();

        // The child inherits the parent's description as its standard input.
        $output = $this->runChild($stream, <<<'PHP'
        $handle = Psl\IO\input_handle();
        echo json_encode(stream_get_meta_data($handle->getStream())['blocked']), "\n";
        PHP);

        static::assertSame('false', $output);
        static::assertTrue(stream_get_meta_data($stream)['blocked']);
        fclose($stream);
    }

    public function testAnExitingProcessRestoresAStandardStreamItBorrowed(): void
    {
        $stream = $this->open();

        // A shutdown function registered after the handle still finds the stream nonblocking.
        $output = $this->runChild($stream, <<<'PHP'
        $GLOBALS['handle'] = new Psl\IO\ReadStreamHandle(STDIN);
        register_shutdown_function(static function (): void {
            echo json_encode(stream_get_meta_data(STDIN)['blocked']), "\n";
        });
        PHP);

        static::assertSame('false', $output);
        static::assertTrue(stream_get_meta_data($stream)['blocked']);
        fclose($stream);
    }

    public function testReleasingTheBorrowingHandleFirstLeavesTheDuplicateNonblocking(): void
    {
        $stream = $this->open();

        // A per-handle restore made the live duplicate blocking here, then left the description nonblocking.
        $output = $this->runChild($stream, <<<'PHP'
        $borrowed = new Psl\IO\ReadStreamHandle(STDIN);
        $duplicate = Psl\IO\input_handle();
        unset($borrowed);
        echo json_encode(stream_get_meta_data($duplicate->getStream())['blocked']), ' ';
        $duplicate->close();
        echo json_encode(stream_get_meta_data(STDIN)['blocked']), "\n";
        PHP);

        static::assertSame('false true', $output);
        static::assertTrue(stream_get_meta_data($stream)['blocked']);
        fclose($stream);
    }

    public function testClosingTheDuplicateFirstLeavesTheBorrowedStreamNonblocking(): void
    {
        $stream = $this->open();

        $output = $this->runChild($stream, <<<'PHP'
        $borrowed = new Psl\IO\ReadStreamHandle(STDIN);
        $duplicate = Psl\IO\input_handle();
        $duplicate->close();
        echo json_encode(stream_get_meta_data(STDIN)['blocked']), ' ';
        unset($borrowed);
        echo json_encode(stream_get_meta_data(STDIN)['blocked']), "\n";
        PHP);

        static::assertSame('false true', $output);
        static::assertTrue(stream_get_meta_data($stream)['blocked']);
        fclose($stream);
    }

    /**
     * @return resource
     */
    private function open(): mixed
    {
        $stream = fopen($this->file, 'r');
        static::assertIsResource($stream);
        static::assertTrue(stream_get_meta_data($stream)['blocked']);

        return $stream;
    }

    /**
     * Run `$code` in a PHP process whose standard input is `$stdin`, and return what it printed.
     *
     * @param resource $stdin
     */
    private function runChild(mixed $stdin, string $code): string
    {
        $loader = new ReflectionClass(ClassLoader::class)->getFileName();
        static::assertIsString($loader);

        $script = 'require ' . var_export(dirname($loader, 2) . '/autoload.php', true) . ";\n" . $code;
        $process = proc_open(
            [PHP_BINARY, '-dopcache.enable=0', '-r', $script],
            [0 => $stdin, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        static::assertNotFalse($process);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        static::assertSame(0, proc_close($process), (string) $errors);
        static::assertSame('', $errors);
        static::assertIsString($output);

        return trim($output);
    }
}
