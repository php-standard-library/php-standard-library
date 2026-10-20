<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit\Base64;

use PHPUnit\Framework\TestCase;
use Psl\Encoding\Base64;
use Psl\IO;

use function str_repeat;
use function strlen;

final class EncodingReadHandleTest extends TestCase
{
    public function testTryReadBufferNonEmptyNotEofDoesNotRefill(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('ABCDEFGHIJ'));
        $first = $handle->tryRead(4);
        static::assertSame(4, strlen($first));
        $second = $handle->tryRead(4);
        static::assertSame(4, strlen($second));
    }

    public function testTryReadEmptyHandleReturnsEmptyString(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle(''));
        static::assertSame('', $handle->tryRead());
    }

    public function testTryReadMaxBytesExactlyEqualsBuffer(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('ABC'));
        $all = $handle->readAll();
        $len = strlen($all);

        $handle2 = new Base64\EncodingReadHandle(new IO\MemoryHandle('ABC'));
        $result = $handle2->tryRead($len);
        static::assertSame($len, strlen($result));
        static::assertSame('', $handle2->tryRead());
    }

    public function testTryReadNullMaxBytesReturnsAll(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('Hello'));
        $result = $handle->tryRead(null);
        static::assertGreaterThan(0, strlen($result));
    }

    public function testTryReadMaxBytesLessThanBufferReturnsPartial(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('Hello, World!'));
        $part1 = $handle->tryRead(4);
        static::assertSame(4, strlen($part1));
        $rest = $handle->tryRead();
        static::assertGreaterThan(0, strlen($rest));
        // The trailing partial group is only encoded once the end of the inner handle is reached.
        $rest .= $handle->readAll();
        static::assertSame(Base64\encode('Hello, World!'), $part1 . $rest);
        $decoder = new Base64\DecodingReadHandle(new IO\MemoryHandle($part1 . $rest));
        static::assertSame('Hello, World!', $decoder->readAll());
    }

    public function testReadAfterEofWithEmptyBufferReturnsEmptyString(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('A'));
        $handle->readAll();
        static::assertSame('', $handle->read());
    }

    public function testReadAfterEofReturnsEmptyNotNull(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('A'));
        $handle->readAll();
        $result = $handle->read();
        static::assertIsString($result);
        static::assertSame('', $result);
    }

    public function testReadEmptyHandleReturnsEmptyString(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle(''));
        static::assertSame('', $handle->read());
    }

    public function testReadMaxBytesExactlyEqualsBuffer(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('AB'));
        $all = $handle->readAll();
        $len = strlen($all);
        $handle2 = new Base64\EncodingReadHandle(new IO\MemoryHandle('AB'));
        static::assertSame($all, $handle2->read($len));
    }

    public function testReadMaxBytesLessThanBuffer(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('ABCDE'));
        $part = $handle->read(4);
        static::assertSame(4, strlen($part));
        $rest = $handle->readAll();
        static::assertGreaterThan(0, strlen($rest));
    }

    public function testReadMultipleSequentialReadsAfterEof(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('X'));
        $handle->readAll();
        static::assertSame('', $handle->read());
        static::assertSame('', $handle->read());
    }

    public function testReadByteSingleByteHandle(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('A'));
        $byte = $handle->readByte();
        static::assertSame(1, strlen($byte));
    }

    public function testReadByteSecondIsDifferent(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('ABCDE'));
        $b1 = $handle->readByte();
        $b2 = $handle->readByte();
        static::assertNotSame($b1, $b2);
    }

    public function testReadByteEmptyHandleThrows(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle(''));
        $this->expectException(IO\Exception\RuntimeException::class);
        $handle->readByte();
    }

    public function testReadByteMultiByteBufferThenReadByte(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('ABC'));
        $b1 = $handle->readByte();
        static::assertSame(1, strlen($b1));
        $rest = $handle->readAll();
        static::assertGreaterThan(0, strlen($rest));
    }

    public function testReadLineOutputContainsCRLF(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('Hello, World!'));
        $line = $handle->readLine();
        static::assertNotNull($line);
        static::assertStringContainsString('SGVsbG8sIFdvcmxkIQ==', $line);
    }

    public function testReadLineOnEmptyReturnsNull(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle(''));
        static::assertNull($handle->readLine());
    }

    public function testReadLineAfterPartialRead(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('Hello'));
        $handle->tryRead(2);
        $rest = $handle->readLine();
        static::assertNotNull($rest);
    }

    public function testReadLineMultipleChunks(): void
    {
        $data = str_repeat('A', 60);
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle($data), Base64\Variant::Mime);
        $line1 = $handle->readLine();
        static::assertNotNull($line1);
        static::assertSame(76, strlen($line1));
        $line2 = $handle->readLine();
        static::assertSame('QUFB', $line2);
        static::assertNull($handle->readLine());
    }

    public function testNonMimeVariantsDoNotEmitLineEndings(): void
    {
        $data = str_repeat('A', 60);
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle($data));
        static::assertSame(Base64\encode($data), $handle->readLine());
        static::assertNull($handle->readLine());
    }

    public function testReadUntilSuffixFound(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('Hello'));
        $result = $handle->readUntil('=');
        static::assertNotNull($result);
        static::assertSame('SGVsbG8', $result);
    }

    public function testReadUntilSuffixNotFound(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('ABC'));
        static::assertNull($handle->readUntil('|'));
    }

    public function testReadUntilRemainingDataCorrect(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('Hello'));
        $before = $handle->readUntil('=');
        static::assertSame('SGVsbG8', $before);
        // Like `Base64\encode()`, nothing follows the padding.
        static::assertSame('', $handle->readAll());
    }

    public function testReadUntilBoundedSuffixFoundWithinLimit(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('Hello'));
        $result = $handle->readUntilBounded('=', 10);
        static::assertSame('SGVsbG8', $result);
    }

    public function testReadUntilBoundedSuffixFoundBeyondLimitThrows(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('Hello'));
        $this->expectException(IO\Exception\OverflowException::class);
        $handle->readUntilBounded('=', 3);
    }

    public function testReadUntilBoundedNotFoundWithinLimit(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('ABC'));
        static::assertNull($handle->readUntilBounded('|', 100));
    }

    public function testReachedEndOfDataSourceStates(): void
    {
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle('A'));
        static::assertFalse($handle->reachedEndOfDataSource());
        $handle->readAll();
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testFillBufferChunkSizeBoundary(): void
    {
        $data = str_repeat('A', 57);
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle($data));
        $encoded = $handle->readAll();
        $decoder = new Base64\DecodingReadHandle(new IO\MemoryHandle($encoded));
        static::assertSame($data, $decoder->readAll());
    }

    public function testFillBufferLargerThanChunkSize(): void
    {
        $data = str_repeat('B', 200);
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle($data));
        $encoded = $handle->readAll();
        $decoder = new Base64\DecodingReadHandle(new IO\MemoryHandle($encoded));
        static::assertSame($data, $decoder->readAll());
    }

    public function testFillBufferWithVariant(): void
    {
        $data = 'Hello';
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle($data), Base64\Variant::UrlSafe);
        $encoded = $handle->readAll();
        $decoder = new Base64\DecodingReadHandle(new IO\MemoryHandle($encoded), Base64\Variant::UrlSafe);
        static::assertSame($data, $decoder->readAll());
    }

    public function testFillBufferNoPadding(): void
    {
        $data = 'Hi';
        $handle = new Base64\EncodingReadHandle(new IO\MemoryHandle($data), padding: false);
        $encoded = $handle->readAll();
        $decoder = new Base64\DecodingReadHandle(new IO\MemoryHandle($encoded), padding: false);
        static::assertSame($data, $decoder->readAll());
    }
}
