<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit\QuotedPrintable;

use PHPUnit\Framework\TestCase;
use Psl\Encoding\QuotedPrintable;
use Psl\IO;

use function explode;
use function str_repeat;
use function strlen;

final class EncodingReadHandleTest extends TestCase
{
    public function testTryReadBufferNonEmptyNotEofDoesNotRefill(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('ABCDE'));
        $first = $handle->tryRead(2);
        static::assertSame(2, strlen($first));
        $second = $handle->tryRead(2);
        static::assertSame(2, strlen($second));
    }

    public function testTryReadEmptyHandleReturnsEmptyString(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle(''));
        static::assertSame('', $handle->tryRead());
    }

    public function testTryReadMaxBytesExactlyEqualsBuffer(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('AB'));
        $all = $handle->readAll();
        $len = strlen($all);
        $handle2 = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('AB'));
        $result = $handle2->tryRead($len);
        static::assertSame($len, strlen($result));
        static::assertSame('', $handle2->tryRead());
    }

    public function testTryReadNullMaxBytesReturnsAll(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('Hello'));
        $result = $handle->tryRead(null);
        static::assertGreaterThan(0, strlen($result));
    }

    public function testTryReadMaxBytesLessThanBufferReturnsPartial(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('Hello'));
        $part1 = $handle->tryRead(2);
        static::assertSame(2, strlen($part1));
        $rest = $handle->tryRead();
        static::assertGreaterThan(0, strlen($rest));
    }

    public function testTryReadOnEofReturnsEmpty(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle(''));
        static::assertSame('', $handle->tryRead());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testReadAfterEofWithEmptyBufferReturnsEmptyString(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('A'));
        $handle->readAll();
        static::assertSame('', $handle->read());
    }

    public function testReadAfterEofReturnsEmptyNotNull(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('A'));
        $handle->readAll();
        $result = $handle->read();
        static::assertIsString($result);
        static::assertSame('', $result);
    }

    public function testReadEmptyHandleReturnsEmptyString(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle(''));
        static::assertSame('', $handle->read());
    }

    public function testReadMaxBytesExactlyEqualsBuffer(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('AB'));
        $all = $handle->readAll();
        $len = strlen($all);
        $handle2 = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('AB'));
        static::assertSame($all, $handle2->read($len));
    }

    public function testReadMaxBytesLessThanBuffer(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('ABCDE'));
        $part = $handle->read(2);
        static::assertSame(2, strlen($part));
        $rest = $handle->readAll();
        static::assertGreaterThan(0, strlen($rest));
    }

    public function testReadMultipleSequentialReadsAfterEof(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('X'));
        $handle->readAll();
        static::assertSame('', $handle->read());
        static::assertSame('', $handle->read());
    }

    public function testReadByteSingleByteHandle(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('A'));
        $byte = $handle->readByte();
        static::assertSame(1, strlen($byte));
    }

    public function testReadByteSecondIsDifferent(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('AB'));
        $b1 = $handle->readByte();
        $b2 = $handle->readByte();
        static::assertNotSame($b1, $b2);
    }

    public function testReadByteEmptyHandleThrows(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle(''));
        $this->expectException(IO\Exception\RuntimeException::class);
        $handle->readByte();
    }

    public function testReadByteMultiByteBufferThenReadAll(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('ABC'));
        $b1 = $handle->readByte();
        static::assertSame(1, strlen($b1));
        $rest = $handle->readAll();
        static::assertGreaterThan(0, strlen($rest));
    }

    public function testReadByteEncodesSpecialChar(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('='));
        static::assertSame('=', $handle->readByte());
        static::assertSame('3', $handle->readByte());
        static::assertSame('D', $handle->readByte());
    }

    public function testReadLineNoNewlineReturnsContentThenNull(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('no newline'));
        $line = $handle->readLine();
        static::assertNotNull($line);
        static::assertSame('no newline', $line);
        static::assertNull($handle->readLine());
    }

    public function testReadLineOnEmptyReturnsNull(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle(''));
        static::assertNull($handle->readLine());
    }

    public function testReadLineAfterPartialRead(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('Hello'));
        $handle->tryRead(2);
        $rest = $handle->readLine();
        static::assertNotNull($rest);
    }

    public function testReadLineMultiLine(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle("line1\r\nline2"));
        $line1 = $handle->readLine();
        static::assertNotNull($line1);
        static::assertStringContainsString('line1', $line1);
    }

    public function testReadUntilSuffixFound(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('Hello'));
        $result = $handle->readUntil('ll');
        static::assertSame('He', $result);
        static::assertSame('o', $handle->readAll());
    }

    public function testReadUntilSuffixNotFound(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('ABC'));
        static::assertNull($handle->readUntil('ZZ'));
    }

    public function testReadUntilRemainingDataCorrect(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('key|value'));
        $before = $handle->readUntil('|');
        static::assertNotNull($before);
        $rest = $handle->readAll();
        static::assertGreaterThan(0, strlen($rest));
    }

    public function testReadUntilBoundedSuffixFoundWithinLimit(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('Hello'));
        $result = $handle->readUntilBounded('ll', 10);
        static::assertSame('He', $result);
    }

    public function testReadUntilBoundedSuffixFoundBeyondLimitThrows(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('ABCDEFGHIJ'));
        $this->expectException(IO\Exception\OverflowException::class);
        $handle->readUntilBounded('ZZ', 3);
    }

    public function testReadUntilBoundedNotFoundWithinLimit(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('AB'));
        static::assertNull($handle->readUntilBounded('ZZ', 100));
    }

    public function testReadUntilBoundedExactLimit(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('Hello'));
        $result = $handle->readUntilBounded('ll', 2);
        static::assertSame('He', $result);
    }

    public function testReadUntilBoundedOneOverThrows(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('Hello'));
        $this->expectException(IO\Exception\OverflowException::class);
        $handle->readUntilBounded('ll', 1);
    }

    public function testReachedEndOfDataSourceStates(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('A'));
        static::assertFalse($handle->reachedEndOfDataSource());
        $handle->readAll();
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testFillBufferPlainAscii(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('Hello, World!'));
        static::assertSame('Hello, World!', $handle->readAll());
    }

    public function testFillBufferSpecialCharsEncoded(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('3+3=6'));
        static::assertSame('3+3=3D6', $handle->readAll());
    }

    public function testFillBufferLongLineWraps(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle(str_repeat('A', 100)));
        $result = $handle->readAll();
        $lines = explode("\r\n", $result);
        foreach ($lines as $line) {
            static::assertLessThanOrEqual(76, strlen($line));
        }
    }

    public function testFillBufferRoundTrip(): void
    {
        $original = 'Subject: Hello =World!';
        $raw = new IO\MemoryHandle($original);
        $encoding = new QuotedPrintable\EncodingReadHandle($raw);
        $encoded = $encoding->readAll();
        $encodedHandle = new IO\MemoryHandle($encoded);
        $decoding = new QuotedPrintable\DecodingReadHandle($encodedHandle);
        static::assertSame($original, $decoding->readAll());
    }

    public function testFillBufferMultiLine(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle("line1\r\nline2"));
        static::assertSame("line1\r\nline2", $handle->readAll());
    }

    public function testFillBufferTrailingWhitespace(): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle('trailing space '));
        static::assertSame('trailing space=20', $handle->readAll());
    }

    public function testDecodingWriteSoftBreak(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new QuotedPrintable\DecodingWriteHandle($inner);
        $handle->write("Hello=\r\nWorld\r\n");
        $handle->flush();
        $inner->seek(0);
        static::assertSame("HelloWorld\r\n", $inner->readAll());
    }

    public function testDecodingWriteFlushEmpty(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new QuotedPrintable\DecodingWriteHandle($inner);
        $handle->flush();
        $inner->seek(0);
        static::assertSame('', $inner->readAll());
    }

    public function testDecodingWriteFlushWithRemainder(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new QuotedPrintable\DecodingWriteHandle($inner);
        $handle->write('Hello');
        $handle->flush();
        $inner->seek(0);
        static::assertSame('Hello', $inner->readAll());
    }

    public function testDecodingWriteChunkedSoftBreak(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new QuotedPrintable\DecodingWriteHandle($inner);
        $handle->write('Hel');
        $handle->write("lo=\r\n");
        $handle->write("World\r\n");
        $handle->flush();
        $inner->seek(0);
        static::assertSame("HelloWorld\r\n", $inner->readAll());
    }

    public function testDecodingWriteAccumulatedBuffer(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new QuotedPrintable\DecodingWriteHandle($inner);
        $handle->writeAll("Hello=20World\r\n");
        $handle->flush();
        $inner->seek(0);
        static::assertSame("Hello World\r\n", $inner->readAll());
    }

    public function testEncodingWriteLineBoundaries(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new QuotedPrintable\EncodingWriteHandle($inner);
        $handle->write('line');
        $handle->write("1\r\n");
        $handle->write('line2');
        $handle->flush();
        $inner->seek(0);
        static::assertSame("line1\r\nline2", $inner->readAll());
    }

    public function testEncodingWriteFirstLineFlag(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new QuotedPrintable\EncodingWriteHandle($inner);
        $handle->writeAll("a\r\nb\r\nc");
        $handle->flush();
        $inner->seek(0);
        static::assertSame("a\r\nb\r\nc", $inner->readAll());
    }

    public function testEncodingWriteFlushEmpty(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new QuotedPrintable\EncodingWriteHandle($inner);
        $handle->flush();
        $inner->seek(0);
        static::assertSame('', $inner->readAll());
    }

    public function testEncodingWriteFlushWithData(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new QuotedPrintable\EncodingWriteHandle($inner);
        $handle->write('Hello');
        $handle->flush();
        $inner->seek(0);
        static::assertSame('Hello', $inner->readAll());
    }

    public function testRoundTripWriteHandles(): void
    {
        $original = "Hello =World!\r\nLine 2";
        $encodedBuffer = new IO\MemoryHandle();
        $encoding = new QuotedPrintable\EncodingWriteHandle($encodedBuffer);
        $encoding->writeAll($original);
        $encoding->flush();
        $decodedBuffer = new IO\MemoryHandle();
        $decoding = new QuotedPrintable\DecodingWriteHandle($decodedBuffer);
        $encodedBuffer->seek(0);
        $decoding->writeAll($encodedBuffer->readAll());
        $decoding->flush();
        $decodedBuffer->seek(0);
        static::assertSame($original, $decodedBuffer->readAll());
    }
}
