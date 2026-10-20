<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\IO;

use function fopen;
use function str_repeat;

final class ReaderTest extends TestCase
{
    public function testReadByteOnAnEmptyBufferFillsTheInternalBufferAndMarksTheReaderAsEOF(): void
    {
        $handle = new IO\MemoryHandle('a');
        $reader = new IO\Reader($handle);

        static::assertSame('a', $reader->readByte());
        static::assertTrue($reader->reachedEndOfDataSource());
    }

    public function testReadByteOnAnEmptyBufferThrows(): void
    {
        $handle = new IO\MemoryHandle();
        $reader = new IO\Reader($handle);

        $this->expectException(IO\Exception\RuntimeException::class);

        $reader->readByte();
    }

    public function testReadEmptyHandle(): void
    {
        $handle = new IO\MemoryHandle();
        $reader = new IO\Reader($handle);

        static::assertEmpty($reader->tryRead());
        static::assertTrue($reader->reachedEndOfDataSource());
    }

    public function testReadingFile(): void
    {
        $resource = fopen(__FILE__, 'r');
        static::assertIsResource($resource);
        $handle = new IO\ReadStreamHandle($resource);
        $reader = new IO\Reader($handle);

        static::assertSame($handle, $reader->getHandle());

        static::assertSame('<?php', $reader->readLine());
        static::assertSame('', $reader->readLine());
        static::assertSame('declare(strict_types=1);', $reader->readLine());
        static::assertSame('', $reader->readLine());
        static::assertSame('namespace Psl\\IO\\Tests\\Unit;', $reader->readLine());
        static::assertSame('', $reader->readLine());
        static::assertSame('use PHPUnit\\Framework\\TestCase;', $reader->readLine());

        static::assertSame('use Psl', $reader->readUntil('\\'));
        static::assertSame('IO;', $reader->readLine());
        static::assertSame('', $reader->readLine());
        static::assertSame('use function fopen;', $reader->readLine());
        static::assertSame('use function str_repeat;', $reader->readLine());
        static::assertSame('', $reader->readLine());
        static::assertSame('final class', $reader->readFixedSize(11));

        $handle->readAll();

        static::assertEmpty($handle->read());

        /**
         * Handle has reached EOL, but the buffer still contains content.
         */
        static::assertFalse($reader->reachedEndOfDataSource());
        static::assertSame(' ', $reader->readByte());
        static::assertSame('ReaderTest', $reader->readFixedSize(10));
    }

    public function testReadEof(): void
    {
        $handle = new IO\MemoryHandle('hello');
        $reader = new IO\Reader($handle);
        static::assertSame('hello', $reader->read());
        static::assertSame('', $reader->read());
        static::assertTrue($reader->reachedEndOfDataSource());
        static::assertSame('', $reader->read());
    }

    public function testBufferedDataRemainsReadableAfterAFailedFixedSizeRead(): void
    {
        $reader = new IO\Reader(new IO\MemoryHandle('abc'));

        try {
            $reader->readFixedSize(5);
            static::fail('Expected RuntimeException');
        } catch (IO\Exception\RuntimeException $e) {
            static::assertSame('Reached end of file before requested size.', $e->getMessage());
        }

        // EOF has been observed on the inner handle, but "abc" is still buffered.
        static::assertFalse($reader->reachedEndOfDataSource());
        static::assertSame('ab', $reader->readFixedSize(2));
        static::assertSame('c', $reader->read());
        static::assertTrue($reader->reachedEndOfDataSource());
        static::assertSame('', $reader->read());
    }

    public function testBufferedDataIsReturnedByAllReadMethodsAfterEof(): void
    {
        $reader = new IO\Reader(new IO\MemoryHandle('line'));

        try {
            $reader->readFixedSize(10);
            static::fail('Expected RuntimeException');
        } catch (IO\Exception\RuntimeException) {
            static::addToAssertionCount(1);
        }

        static::assertSame('line', $reader->readLine());
        static::assertNull($reader->readLine());

        $reader = new IO\Reader(new IO\MemoryHandle('data'));

        try {
            $reader->readFixedSize(10);
            static::fail('Expected RuntimeException');
        } catch (IO\Exception\RuntimeException) {
            static::addToAssertionCount(1);
        }

        static::assertSame('da', $reader->tryRead(2));
        static::assertSame('ta', $reader->readAll());
        static::assertSame('', $reader->tryRead());
    }

    public function testReadSome(): void
    {
        $handle = new IO\MemoryHandle('hello, world!');
        $reader = new IO\Reader($handle);

        static::assertSame('he', $reader->read(2));
        static::assertSame('ll', $reader->read(2));
        static::assertSame('o,', $reader->read(2));
        static::assertSame(' world!', $reader->read(10));
        static::assertTrue($reader->reachedEndOfDataSource());
        static::assertSame('', $reader->read());
    }

    public function testReadUntilBoundedFindsPrefix(): void
    {
        $handle = new IO\MemoryHandle('hello\r\nworld');
        $reader = new IO\Reader($handle);

        static::assertSame('hello', $reader->readUntilBounded('\r\n', 100));
        static::assertSame('world', $reader->read());
    }

    public function testReadUntilBoundedReturnsNullOnEof(): void
    {
        $handle = new IO\MemoryHandle('hello');
        $reader = new IO\Reader($handle);

        static::assertNull($reader->readUntilBounded('@', 100));
    }

    public function testReadUntilBoundedThrowsOverflowWhenBufferExceedsMaxBeforeRead(): void
    {
        $handle = new IO\MemoryHandle('aaaaaaaaaa\r\n');
        $reader = new IO\Reader($handle);

        $this->expectException(IO\Exception\OverflowException::class);
        $this->expectExceptionMessage('Exceeded maximum byte limit (5) before encountering the suffix ("\r\n").');

        $reader->readUntilBounded('\r\n', 5);
    }

    public function testReadUntilBoundedThrowsOverflowWhenSuffixFoundBeyondMax(): void
    {
        $handle = new IO\MemoryHandle('abcdefghij:end');
        $reader = new IO\Reader($handle);

        $this->expectException(IO\Exception\OverflowException::class);
        $this->expectExceptionMessage('Exceeded maximum byte limit (3) before encountering the suffix (":end").');

        $reader->readUntilBounded(':end', 3);
    }

    public function testReadUntilBoundedExactMaxBytes(): void
    {
        $handle = new IO\MemoryHandle('abcde:end');
        $reader = new IO\Reader($handle);

        static::assertSame('abcde', $reader->readUntilBounded(':end', 5));
    }

    public function testReadUntilBoundedSuffixAtStart(): void
    {
        $handle = new IO\MemoryHandle(':endrest');
        $reader = new IO\Reader($handle);

        static::assertSame('', $reader->readUntilBounded(':end', 10));
        static::assertSame('rest', $reader->read());
    }

    public function testReadUntilBoundedMultipleSuffixes(): void
    {
        $handle = new IO\MemoryHandle('ab|cd|ef');
        $reader = new IO\Reader($handle);

        static::assertSame('ab', $reader->readUntilBounded('|', 100));
        static::assertSame('cd', $reader->readUntilBounded('|', 100));
        static::assertNull($reader->readUntilBounded('|', 100));
    }

    public function testReadUntilBoundedPreservesBufferAfterOverflow(): void
    {
        $handle = new IO\MemoryHandle('toolongcontent:endfoo');
        $reader = new IO\Reader($handle);

        try {
            $reader->readUntilBounded(':end', 3);
            static::fail('Expected OverflowException');
        } catch (IO\Exception\OverflowException) {
            static::addToAssertionCount(1);
        }

        static::assertSame('toolongcontent:endfoo', $reader->read());
    }

    public function testReadUntilBoundedSingleByteSuffix(): void
    {
        $handle = new IO\MemoryHandle('abc\ndef');
        $reader = new IO\Reader($handle);

        static::assertSame('abc', $reader->readUntilBounded('\n', 10));
        static::assertSame('def', $reader->read());
    }

    public function testReadUntilBoundedEmptyHandle(): void
    {
        $handle = new IO\MemoryHandle('');
        $reader = new IO\Reader($handle);

        static::assertNull($reader->readUntilBounded(':end', 100));
    }

    public function testReadUntilBoundedMaxBytesExactlyAtSuffix(): void
    {
        $handle = new IO\MemoryHandle('abc|rest');
        $reader = new IO\Reader($handle);

        static::assertSame('abc', $reader->readUntilBounded('|', 3));
        static::assertSame('rest', $reader->read());
    }

    public function testReadUntilInvalidSuffix(): void
    {
        $handle = new IO\MemoryHandle('hello');
        $reader = new IO\Reader($handle);

        static::assertNull($reader->readUntil('@'));
    }

    public function testReadLineEol(): void
    {
        $handle = new IO\MemoryHandle();
        $handle->write('hello');
        $reader = new IO\Reader($handle);

        static::assertNull($reader->readLine());
    }

    public function testReadLineNoNewLine(): void
    {
        $handle = new IO\MemoryHandle();
        $handle->write('hello, world!');
        $handle->seek(5);

        $reader = new IO\Reader($handle);

        static::assertSame(', world!', $reader->readLine());
        static::assertNull($reader->readLine());
    }

    public function testIsEndOfLineWithEofHandle(): void
    {
        $handle = new IO\MemoryHandle();
        $handle->write('hello, world!');

        $reader = new IO\Reader($handle);

        static::assertTrue($reader->reachedEndOfDataSource());
    }

    public function testIsEndOfLineWithEmptyHandle(): void
    {
        $handle = new IO\MemoryHandle();
        $reader = new IO\Reader($handle);

        static::assertTrue($reader->reachedEndOfDataSource());
        static::assertTrue($reader->reachedEndOfDataSource());
    }

    public function testIsEndOfLineWithNonEmptyHandle(): void
    {
        $handle = new IO\MemoryHandle('hello');
        $reader = new IO\Reader($handle);

        static::assertFalse($reader->reachedEndOfDataSource());
        static::assertSame('hello', $reader->readLine());
    }

    public function testReadLineSplitsOnLF(): void
    {
        $handle = new IO\MemoryHandle("line1\nline2\nline3");
        $reader = new IO\Reader($handle);

        static::assertSame('line1', $reader->readLine());
        static::assertSame('line2', $reader->readLine());
        static::assertSame('line3', $reader->readLine());
        static::assertNull($reader->readLine());
    }

    public function testReadLineStripsCRFromCRLF(): void
    {
        $handle = new IO\MemoryHandle("line1\r\nline2\r\nline3");
        $reader = new IO\Reader($handle);

        static::assertSame('line1', $reader->readLine());
        static::assertSame('line2', $reader->readLine());
        static::assertSame('line3', $reader->readLine());
        static::assertNull($reader->readLine());
    }

    public function testReadLineMixedLineEndings(): void
    {
        $handle = new IO\MemoryHandle("unix\nwindows\r\nunix again\n");
        $reader = new IO\Reader($handle);

        static::assertSame('unix', $reader->readLine());
        static::assertSame('windows', $reader->readLine());
        static::assertSame('unix again', $reader->readLine());
        static::assertNull($reader->readLine());
    }

    public function testReadLineEmptyLines(): void
    {
        $handle = new IO\MemoryHandle("\n\n\n");
        $reader = new IO\Reader($handle);

        static::assertSame('', $reader->readLine());
        static::assertSame('', $reader->readLine());
        static::assertSame('', $reader->readLine());
        static::assertNull($reader->readLine());
    }

    public function testReadUntilBoundedOverflowInInitialBuffer(): void
    {
        $handle = new IO\MemoryHandle('abcdefghij:end rest');
        $reader = new IO\Reader($handle);
        $reader->readByte();

        $this->expectException(IO\Exception\OverflowException::class);
        $reader->readUntilBounded(':end', 3);
    }

    public function testReadUntilBoundedSuffixFoundBeyondMaxInBuffer(): void
    {
        $handle = new IO\MemoryHandle('abcdefghij:end');
        $reader = new IO\Reader($handle);
        $reader->readByte();

        $this->expectException(IO\Exception\OverflowException::class);
        $reader->readUntilBounded(':end', 3);
    }

    public function testReadUntilBoundedBufferExceedsMaxBeforeLoop(): void
    {
        $handle = new IO\MemoryHandle('abcdefghij');
        $reader = new IO\Reader($handle);
        $reader->readByte();

        $this->expectException(IO\Exception\OverflowException::class);
        $reader->readUntilBounded(':end', 3);
    }

    public function testReadUntilBoundedOverflowDuringFill(): void
    {
        $handle = new IO\MemoryHandle(str_repeat('x', 100));
        $reader = new IO\Reader($handle);

        $this->expectException(IO\Exception\OverflowException::class);
        $reader->readUntilBounded('NOTFOUND', 10);
    }

    public function testReadLineEmptyLinesCRLF(): void
    {
        $handle = new IO\MemoryHandle("\r\n\r\n");
        $reader = new IO\Reader($handle);

        static::assertSame('', $reader->readLine());
        static::assertSame('', $reader->readLine());
        static::assertNull($reader->readLine());
    }

    public function testReadLineWithDelayedHandle(): void
    {
        $handle = new DelayedHandle("line1\nline2\nline3");
        $reader = new IO\Reader($handle);

        static::assertSame('line1', $reader->readLine());
        static::assertSame('line2', $reader->readLine());
        static::assertSame('line3', $reader->readLine());
        static::assertNull($reader->readLine());
    }

    public function testReadLineWithDelayedHandleCRLF(): void
    {
        $handle = new DelayedHandle("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello");
        $reader = new IO\Reader($handle);

        static::assertSame('HTTP/1.1 200 OK', $reader->readLine());
        static::assertSame('Content-Length: 5', $reader->readLine());
        static::assertSame('', $reader->readLine());
        static::assertSame('hello', $reader->readLine());
        static::assertNull($reader->readLine());
    }

    public function testReadLineWithMultipleDelayedReads(): void
    {
        $handle = new DelayedHandle("first\nsecond\n", 3);
        $reader = new IO\Reader($handle);

        static::assertSame('first', $reader->readLine());
        static::assertSame('second', $reader->readLine());
        static::assertNull($reader->readLine());
    }

    public function testReadUntilWithDelayedHandle(): void
    {
        $handle = new DelayedHandle("header\r\n\r\nbody");
        $reader = new IO\Reader($handle);

        static::assertSame('header', $reader->readUntil("\r\n\r\n"));
        static::assertSame('body', $reader->readAll());
    }

    public function testReadUntilBoundedWithDelayedHandle(): void
    {
        $handle = new DelayedHandle('short:end rest');
        $reader = new IO\Reader($handle);

        static::assertSame('short', $reader->readUntilBounded(':end', 100));
        static::assertSame(' rest', $reader->readAll());
    }
}
