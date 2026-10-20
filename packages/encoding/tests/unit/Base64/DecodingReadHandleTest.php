<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit\Base64;

use PHPUnit\Framework\TestCase;
use Psl\Encoding\Base64;
use Psl\Encoding\Exception;
use Psl\IO;

use function base64_encode;
use function str_repeat;

final class DecodingReadHandleTest extends TestCase
{
    public function testTryReadBufferNonEmptyNotEofDoesNotRefill(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('ABCDEF')));
        $first = $handle->tryRead(2);
        static::assertSame('AB', $first);
        $second = $handle->tryRead(2);
        static::assertSame('CD', $second);
    }

    public function testTryReadEmptyHandleReturnsEmptyString(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(''));
        static::assertSame('', $handle->tryRead());
    }

    public function testTryReadMaxBytesExactlyEqualsBuffer(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('XYZ')));
        $result = $handle->tryRead(3);
        static::assertSame('XYZ', $result);
        static::assertSame('', $handle->tryRead());
    }

    public function testTryReadNullMaxBytesReturnsAll(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('ABCDEF')));
        $result = $handle->tryRead(null);
        static::assertSame('ABCDEF', $result);
    }

    public function testTryReadMaxBytesLessThanBufferReturnsPartial(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('ABCDEF')));
        static::assertSame('A', $handle->tryRead(1));
        static::assertSame('BCDEF', $handle->tryRead());
    }

    public function testReadAfterEofWithEmptyBufferReturnsEmptyString(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('A')));
        $handle->readAll();
        static::assertSame('', $handle->read());
    }

    public function testReadAfterEofReturnsEmptyNotNull(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('X')));
        $handle->readAll();
        $result = $handle->read();
        static::assertIsString($result);
        static::assertSame('', $result);
    }

    public function testReadEmptyHandleReturnsEmptyString(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(''));
        static::assertSame('', $handle->read());
    }

    public function testReadMaxBytesExactlyEqualsBuffer(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('ABC')));
        static::assertSame('ABC', $handle->read(3));
    }

    public function testReadMaxBytesLessThanBuffer(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('ABCDEF')));
        static::assertSame('AB', $handle->read(2));
        static::assertSame('CDEF', $handle->read(100));
    }

    public function testReadMultipleSequentialReadsAfterEof(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('Z')));
        $handle->readAll();
        static::assertSame('', $handle->read());
        static::assertSame('', $handle->read());
        static::assertSame('', $handle->read());
    }

    public function testReadMaxBytesLargerThanBuffer(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('AB')));
        static::assertSame('AB', $handle->read(100));
    }

    public function testReadNullMaxBytesReturnsAll(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('Hello')));
        static::assertSame('Hello', $handle->read(null));
    }

    public function testReadByteSingleByteHandle(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('X')));
        static::assertSame('X', $handle->readByte());
    }

    public function testReadByteSecondIsDifferent(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('AB')));
        static::assertSame('A', $handle->readByte());
        static::assertSame('B', $handle->readByte());
    }

    public function testReadByteEmptyHandleThrows(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(''));
        $this->expectException(IO\Exception\RuntimeException::class);
        $handle->readByte();
    }

    public function testReadByteMultiByteBufferThenReadByte(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('ABCDE')));
        static::assertSame('A', $handle->readByte());
        static::assertSame('B', $handle->readByte());
        static::assertSame('CDE', $handle->readAll());
    }

    public function testReadLineWithCRLFStripsCR(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode("hello\r\nworld")));
        static::assertSame('hello', $handle->readLine());
        static::assertSame('world', $handle->readLine());
    }

    public function testReadLineWithoutNewlineReturnsContentThenNull(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('no newline')));
        static::assertSame('no newline', $handle->readLine());
        static::assertNull($handle->readLine());
    }

    public function testReadLineEmptyLines(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode("\n\n")));
        static::assertSame('', $handle->readLine());
        static::assertSame('', $handle->readLine());
        static::assertNull($handle->readLine());
    }

    public function testReadLineAfterPartialRead(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode("first\nsecond")));
        static::assertSame('first', $handle->readLine());
        static::assertSame('second', $handle->readAll());
    }

    public function testReadLineOnEmptyReturnsNull(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(''));
        static::assertNull($handle->readLine());
    }

    public function testReadLineWithLF(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode("a\nb\nc")));
        static::assertSame('a', $handle->readLine());
        static::assertSame('b', $handle->readLine());
        static::assertSame('c', $handle->readLine());
        static::assertNull($handle->readLine());
    }

    public function testReadUntilSuffixFoundImmediately(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('key=value')));
        static::assertSame('key', $handle->readUntil('='));
    }

    public function testReadUntilMultipleSuffixes(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('a|b|c')));
        static::assertSame('a', $handle->readUntil('|'));
        static::assertSame('b', $handle->readUntil('|'));
        static::assertNull($handle->readUntil('|'));
    }

    public function testReadUntilSuffixNotFound(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('no pipe')));
        static::assertNull($handle->readUntil('|'));
    }

    public function testReadUntilRemainingDataCorrect(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('abc|def')));
        static::assertSame('abc', $handle->readUntil('|'));
        static::assertSame('def', $handle->readAll());
    }

    public function testReadUntilLongSuffix(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('data::END::rest')));
        static::assertSame('data', $handle->readUntil('::END::'));
        static::assertSame('rest', $handle->readAll());
    }

    public function testReadUntilBoundedContentExactlyAtMaxBytes(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('abcde:s')));
        static::assertSame('abcde', $handle->readUntilBounded(':s', 5));
    }

    public function testReadUntilBoundedContentOneOverMaxBytesThrows(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('abcdef:s')));
        $this->expectException(IO\Exception\OverflowException::class);
        $handle->readUntilBounded(':s', 5);
    }

    public function testReadUntilBoundedSuffixFoundWithinLimit(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('ab:end')));
        static::assertSame('ab', $handle->readUntilBounded(':end', 10));
    }

    public function testReadUntilBoundedSuffixFoundBeyondLimitThrows(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('toolongcontent:end')));
        $this->expectException(IO\Exception\OverflowException::class);
        $handle->readUntilBounded(':end', 3);
    }

    public function testReadUntilBoundedNotFoundWithinLimit(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('no suffix')));
        static::assertNull($handle->readUntilBounded('@', 100));
    }

    public function testReadUntilBoundedRemainingDataCorrect(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('ab:endrest')));
        static::assertSame('ab', $handle->readUntilBounded(':end', 10));
        static::assertSame('rest', $handle->readAll());
    }

    public function testReadUntilBoundedBufferGrowsPastLimitThrows(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode(str_repeat('x', 100) . ':end')));
        $this->expectException(IO\Exception\OverflowException::class);
        $handle->readUntilBounded(':end', 10);
    }

    public function testReadUntilBoundedExactLimitSuffixAtEnd(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('12345:end')));
        static::assertSame('12345', $handle->readUntilBounded(':end', 5));
    }

    public function testFillBufferPaddingTrue(): void
    {
        $data = 'Hi';
        $encoded = Base64\encode($data, padding: true);
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle($encoded), padding: true);
        static::assertSame($data, $handle->readAll());
    }

    public function testFillBufferPaddingFalse(): void
    {
        $data = 'Hi';
        $encoded = Base64\encode($data, padding: false);
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle($encoded), padding: false);
        static::assertSame($data, $handle->readAll());
    }

    public function testFillBufferRemainderConcatOrder(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('Hello, World!')));
        static::assertSame('Hello, World!', $handle->readAll());
    }

    public function testFillBufferModulusCalculation(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle('SGVsbG8='));
        static::assertSame('Hello', $handle->readAll());
    }

    public function testFillBufferRemainderOnEof(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('Test')));
        static::assertSame('Test', $handle->readAll());
    }

    public function testFillBufferWhitespaceStrippedForMime(): void
    {
        $handle = new Base64\DecodingReadHandle(
            new IO\MemoryHandle("SGVs\r\nbG8s\r\nIFdv\r\ncmxk\r\nIQ=="),
            Base64\Variant::Mime,
        );
        static::assertSame('Hello, World!', $handle->readAll());
    }

    public function testFillBufferWhitespaceRejectedForStandard(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle("SGVs\r\nbG8s\r\nIFdv\r\ncmxk\r\nIQ=="));
        $this->expectException(Exception\RangeException::class);
        $handle->readAll();
    }

    public function testFillBufferWithVariantUrlSafe(): void
    {
        $data = 'Hello+World/Test';
        $encoded = Base64\encode($data, Base64\Variant::UrlSafe);
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle($encoded), Base64\Variant::UrlSafe);
        static::assertSame($data, $handle->readAll());
    }

    public function testReachedEndOfDataSourceBeforeAndAfterRead(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('A')));
        static::assertFalse($handle->reachedEndOfDataSource());
        $handle->readAll();
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testTryReadOnEofReturnsEmpty(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(''));
        static::assertSame('', $handle->tryRead());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testReadByteAfterEofThrows(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode('A')));
        $handle->readAll();
        $this->expectException(IO\Exception\RuntimeException::class);
        $handle->readByte();
    }

    public function testInvalidBase64Throws(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle('!!!invalid!!!'));
        $this->expectException(Exception\RangeException::class);
        $handle->readAll();
    }

    public function testFillBufferBinaryData(): void
    {
        $binary = "\x00\x01\x02\xff\xfe\xfd";
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(base64_encode($binary)));
        static::assertSame($binary, $handle->readAll());
    }
}
