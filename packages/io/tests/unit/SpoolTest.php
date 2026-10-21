<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\IO;

use function str_repeat;
use function stream_wrapper_restore;
use function stream_wrapper_unregister;

final class SpoolTest extends TestCase
{
    public function testReturnsCloseSeekReadWriteStreamHandle(): void
    {
        $handle = IO\spool();

        static::assertInstanceOf(IO\CloseSeekReadWriteStreamHandle::class, $handle);

        $handle->close();
    }

    public function testWriteAndReadBack(): void
    {
        $handle = IO\spool();

        $handle->writeAll('hello, world!');
        $handle->seek(0);

        static::assertSame('hello, world!', $handle->readAll());

        $handle->close();
    }

    public function testSeekAndTell(): void
    {
        $handle = IO\spool();

        $handle->writeAll('abcdef');

        static::assertSame(6, $handle->tell());

        $handle->seek(3);

        static::assertSame(3, $handle->tell());
        static::assertSame('def', $handle->readAll());

        $handle->close();
    }

    public function testReadEmpty(): void
    {
        $handle = IO\spool();

        static::assertSame('', $handle->readAll());

        $handle->close();
    }

    public function testMultipleWrites(): void
    {
        $handle = IO\spool();

        $handle->writeAll('foo');
        $handle->writeAll('bar');
        $handle->writeAll('baz');

        $handle->seek(0);

        static::assertSame('foobarbaz', $handle->readAll());

        $handle->close();
    }

    public function testOverwrite(): void
    {
        $handle = IO\spool();

        $handle->writeAll('hello');
        $handle->seek(0);
        $handle->writeAll('world');
        $handle->seek(0);

        static::assertSame('world', $handle->readAll());

        $handle->close();
    }

    public function testPartialRead(): void
    {
        $handle = IO\spool();

        $handle->writeAll('hello, world!');
        $handle->seek(0);

        static::assertSame('hello', $handle->read(5));
        static::assertSame(', world!', $handle->readAll());

        $handle->close();
    }

    public function testLargeDataSpoolsToDisk(): void
    {
        $handle = IO\spool(maxMemory: 64);
        $data = str_repeat('x', 256);

        $handle->writeAll($data);
        $handle->seek(0);

        static::assertSame($data, $handle->readAll());

        $handle->close();
    }

    public function testDefaultMaxMemory(): void
    {
        $handle = IO\spool();

        $data = str_repeat('a', 1024);
        $handle->writeAll($data);
        $handle->seek(0);

        static::assertSame($data, $handle->readAll());

        $handle->close();
    }

    public function testZeroMaxMemorySpoolsImmediately(): void
    {
        $handle = IO\spool(maxMemory: 0);

        $handle->writeAll('test');
        $handle->seek(0);

        static::assertSame('test', $handle->readAll());

        $handle->close();
    }

    public function testEofAfterReadAll(): void
    {
        $handle = IO\spool();

        $handle->writeAll('data');
        $handle->seek(0);
        $handle->readAll();

        static::assertTrue($handle->reachedEndOfDataSource());

        $handle->close();
    }

    public function testNotEofBeforeRead(): void
    {
        $handle = IO\spool();

        $handle->writeAll('data');
        $handle->seek(0);

        static::assertFalse($handle->reachedEndOfDataSource());

        $handle->close();
    }

    public function testCloseThrowsOnSubsequentOperations(): void
    {
        $handle = IO\spool();
        $handle->writeAll('test');
        $handle->close();

        $this->expectException(IO\Exception\AlreadyClosedException::class);

        $handle->read();
    }

    public function testSeekToBeginningAfterWrite(): void
    {
        $handle = IO\spool();

        $handle->writeAll('first');
        $handle->seek(0);
        $handle->writeAll('FIRST');
        $handle->seek(0);

        static::assertSame('FIRST', $handle->readAll());

        $handle->close();
    }

    public function testGetStream(): void
    {
        $handle = IO\spool();

        static::assertIsResource($handle->getStream());

        $handle->close();
    }

    public function testTryReadAndTryWrite(): void
    {
        $handle = IO\spool();

        $written = $handle->tryWrite('hello');
        static::assertSame(5, $written);

        $handle->seek(0);

        $data = $handle->tryRead(5);
        static::assertSame('hello', $data);

        $handle->close();
    }

    public function testThrowsWhenTheTemporaryStreamCannotBeOpened(): void
    {
        // php://temp cannot be opened while the php:// wrapper is unregistered.
        static::assertTrue(stream_wrapper_unregister('php'));

        try {
            IO\spool();

            static::fail('Expected spool() to fail without the php:// wrapper.');
        } catch (IO\Exception\RuntimeException $exception) {
            static::assertStringContainsString('Failed to open stream', $exception->getMessage());
        } finally {
            stream_wrapper_restore('php');
        }
    }
}
