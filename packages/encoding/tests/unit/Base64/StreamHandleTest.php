<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit\Base64;

use PHPUnit\Framework\TestCase;
use Psl\Encoding\Base64;
use Psl\Encoding\Exception;
use Psl\IO;

use function base64_encode;
use function str_repeat;
use function strlen;
use function substr;

final class StreamHandleTest extends TestCase
{
    public function testEncodingReadHandle(): void
    {
        $inner = new IO\MemoryHandle('Hello, World!');
        $handle = new Base64\EncodingReadHandle($inner);

        $result = $handle->readAll();

        static::assertSame('SGVsbG8sIFdvcmxkIQ==', $result);
    }

    public function testDecodingReadHandle(): void
    {
        $inner = new IO\MemoryHandle('SGVsbG8sIFdvcmxkIQ==');
        $handle = new Base64\DecodingReadHandle($inner);

        $result = $handle->readAll();

        static::assertSame('Hello, World!', $result);
    }

    public function testEncodingWriteHandle(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\EncodingWriteHandle($inner);

        $handle->writeAll('Hello, World!');
        $handle->flush();

        $inner->seek(0);
        static::assertSame('SGVsbG8sIFdvcmxkIQ==', $inner->readAll());
    }

    public function testDecodingWriteHandle(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\DecodingWriteHandle($inner);

        $handle->writeAll('SGVsbG8sIFdvcmxkIQ==');
        $handle->flush();

        $inner->seek(0);
        static::assertSame('Hello, World!', $inner->readAll());
    }

    public function testRoundTripReadHandles(): void
    {
        $original = 'The quick brown fox jumps over the lazy dog.';

        $raw = new IO\MemoryHandle($original);
        $encoding = new Base64\EncodingReadHandle($raw);
        $encoded = $encoding->readAll();

        $encodedHandle = new IO\MemoryHandle($encoded);
        $decoding = new Base64\DecodingReadHandle($encodedHandle);
        $decoded = $decoding->readAll();

        static::assertSame($original, $decoded);
    }

    public function testRoundTripWriteHandles(): void
    {
        $original = 'The quick brown fox jumps over the lazy dog.';

        $encodedBuffer = new IO\MemoryHandle();
        $encoding = new Base64\EncodingWriteHandle($encodedBuffer);
        $encoding->writeAll($original);
        $encoding->flush();

        $decodedBuffer = new IO\MemoryHandle();
        $decoding = new Base64\DecodingWriteHandle($decodedBuffer);
        $encodedBuffer->seek(0);
        $decoding->writeAll($encodedBuffer->readAll());
        $decoding->flush();

        $decodedBuffer->seek(0);
        static::assertSame($original, $decodedBuffer->readAll());
    }

    public function testLargeDataChunking(): void
    {
        $original = str_repeat('ABCDEFGHIJ', 100);

        $raw = new IO\MemoryHandle($original);
        $encoding = new Base64\EncodingReadHandle($raw);
        $encoded = $encoding->readAll();

        $encodedHandle = new IO\MemoryHandle($encoded);
        $decoding = new Base64\DecodingReadHandle($encodedHandle);
        $decoded = $decoding->readAll();

        static::assertSame($original, $decoded);
    }

    public function testDecodingReadHandleInvalidBase64(): void
    {
        $inner = new IO\MemoryHandle('!!!invalid!!!');
        $handle = new Base64\DecodingReadHandle($inner);

        $this->expectException(Exception\RangeException::class);

        $handle->readAll();
    }

    public function testDecodingWriteHandleInvalidBase64(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\DecodingWriteHandle($inner);

        $handle->writeAll('!!!');

        $this->expectException(Exception\RangeException::class);

        $handle->flush();
    }

    public function testEncodingReadHandleEmpty(): void
    {
        $inner = new IO\MemoryHandle('');
        $handle = new Base64\EncodingReadHandle($inner);

        static::assertSame('', $handle->readAll());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testDecodingReadHandleEmpty(): void
    {
        $inner = new IO\MemoryHandle('');
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('', $handle->readAll());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testEncodingReadHandleBinaryData(): void
    {
        $binary = "\x00\x01\x02\xff\xfe\xfd";
        $inner = new IO\MemoryHandle($binary);
        $handle = new Base64\EncodingReadHandle($inner);

        $encoded = $handle->readAll();

        $decodingHandle = new IO\MemoryHandle($encoded);
        $decoding = new Base64\DecodingReadHandle($decodingHandle);

        static::assertSame($binary, $decoding->readAll());
    }

    public function testDecodingReadHandleWithWhitespace(): void
    {
        $inner = new IO\MemoryHandle("SGVs\r\nbG8s\r\nIFdv\r\ncmxk\r\nIQ==");
        $handle = new Base64\DecodingReadHandle($inner, Base64\Variant::Mime);

        static::assertSame('Hello, World!', $handle->readAll());
    }

    public function testPartialReads(): void
    {
        $inner = new IO\MemoryHandle('SGVsbG8sIFdvcmxkIQ==');
        $handle = new Base64\DecodingReadHandle($inner);

        $result = '';
        while (!$handle->reachedEndOfDataSource()) {
            $chunk = $handle->read(3);
            $result .= $chunk;
        }

        static::assertSame('Hello, World!', $result);
    }

    public function testDecodingReadHandleReadByte(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('ABC'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('A', $handle->readByte());
        static::assertSame('B', $handle->readByte());
        static::assertSame('C', $handle->readByte());
    }

    public function testDecodingReadHandleReadByteOnEmpty(): void
    {
        $inner = new IO\MemoryHandle('');
        $handle = new Base64\DecodingReadHandle($inner);

        $this->expectException(IO\Exception\RuntimeException::class);

        $handle->readByte();
    }

    public function testDecodingReadHandleReadLineLF(): void
    {
        $inner = new IO\MemoryHandle(base64_encode("line1\nline2\nline3"));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('line1', $handle->readLine());
        static::assertSame('line2', $handle->readLine());
        static::assertSame('line3', $handle->readLine());
        static::assertNull($handle->readLine());
    }

    public function testDecodingReadHandleReadLineCRLF(): void
    {
        $inner = new IO\MemoryHandle(base64_encode("line1\r\nline2\r\nline3"));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('line1', $handle->readLine());
        static::assertSame('line2', $handle->readLine());
        static::assertSame('line3', $handle->readLine());
        static::assertNull($handle->readLine());
    }

    public function testDecodingReadHandleReadLineNoNewline(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('no newline'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('no newline', $handle->readLine());
        static::assertNull($handle->readLine());
    }

    public function testDecodingReadHandleReadUntil(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('key=value&other=data'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('key=value', $handle->readUntil('&'));
        static::assertNull($handle->readUntil('&'));
    }

    public function testDecodingReadHandleReadUntilNotFound(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('no delimiter here'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertNull($handle->readUntil('@'));
    }

    public function testDecodingReadHandleReadUntilBounded(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('short:end'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('short', $handle->readUntilBounded(':end', 10));
    }

    public function testDecodingReadHandleReadUntilBoundedOverflow(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('toolongcontent:end'));
        $handle = new Base64\DecodingReadHandle($inner);

        $this->expectException(IO\Exception\OverflowException::class);

        $handle->readUntilBounded(':end', 3);
    }

    public function testDecodingReadHandleReadUntilBoundedNotFound(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('no suffix'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertNull($handle->readUntilBounded('@', 100));
    }

    public function testEncodingReadHandleReadByte(): void
    {
        $inner = new IO\MemoryHandle('A');
        $handle = new Base64\EncodingReadHandle($inner);

        static::assertSame('Q', $handle->readByte());
        static::assertSame('Q', $handle->readByte());
    }

    public function testEncodingReadHandleReadLine(): void
    {
        $inner = new IO\MemoryHandle('Hello, World!');
        $handle = new Base64\EncodingReadHandle($inner);

        $line = $handle->readLine();

        static::assertNotNull($line);
        static::assertStringContainsString('SGVsbG8sIFdvcmxkIQ==', $line);
    }

    public function testEncodingReadHandleReadUntil(): void
    {
        $inner = new IO\MemoryHandle('Hello');
        $handle = new Base64\EncodingReadHandle($inner);

        $result = $handle->readUntil('=');

        static::assertNotNull($result);
        static::assertSame('SGVsbG8', $result);
    }

    public function testDecodingReadHandleTryReadExactBufferSize(): void
    {
        $data = 'ABCD';
        $inner = new IO\MemoryHandle(base64_encode($data));
        $handle = new Base64\DecodingReadHandle($inner);

        $result = $handle->tryRead(4);
        static::assertSame('ABCD', $result);
        static::assertSame('', $handle->tryRead());
    }

    public function testDecodingReadHandleTryReadLessThanBuffer(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('ABCDEF'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('AB', $handle->tryRead(2));
        static::assertSame('CDEF', $handle->tryRead());
    }

    public function testDecodingReadHandleReadAfterEof(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('X'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('X', $handle->read());
        static::assertSame('', $handle->read());
        static::assertTrue($handle->reachedEndOfDataSource());
        static::assertSame('', $handle->read());
    }

    public function testDecodingReadHandleReadWithMaxBytesExact(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('ABC'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('ABC', $handle->read(3));
    }

    public function testDecodingReadHandleReadWithMaxBytesLargerThanBuffer(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('AB'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('AB', $handle->read(100));
    }

    public function testDecodingReadHandleReadByteSingleByteBuffer(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('X'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('X', $handle->readByte());
    }

    public function testDecodingReadHandleReachedEndOfDataSourceStates(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('A'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertFalse($handle->reachedEndOfDataSource());

        $handle->readAll();

        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testDecodingReadHandleReadUntilBoundedOverflowInBuffer(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('aaaaabbbbb:end'));
        $handle = new Base64\DecodingReadHandle($inner);

        $this->expectException(IO\Exception\OverflowException::class);

        $handle->readUntilBounded(':end', 5);
    }

    public function testDecodingReadHandleReadUntilBoundedExactLimit(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('abcde:end'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('abcde', $handle->readUntilBounded(':end', 5));
    }

    public function testDecodingReadHandleReadUntilBoundedOverflowAfterFill(): void
    {
        $inner = new IO\MemoryHandle(base64_encode(str_repeat('x', 100) . ':end'));
        $handle = new Base64\DecodingReadHandle($inner);

        $this->expectException(IO\Exception\OverflowException::class);

        $handle->readUntilBounded(':end', 10);
    }

    public function testDecodingReadHandleReadUntilSuffixConsumesCorrectly(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('abc|def|ghi'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('abc', $handle->readUntil('|'));
        static::assertSame('def', $handle->readUntil('|'));
        static::assertNull($handle->readUntil('|'));
    }

    public function testDecodingReadHandleReadLineThenReadAll(): void
    {
        $inner = new IO\MemoryHandle(base64_encode("first\nsecond"));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('first', $handle->readLine());
        static::assertSame('second', $handle->readAll());
    }

    public function testDecodingReadHandleWithVariant(): void
    {
        $data = 'Hello+World/Test';
        $encoded = Base64\encode($data, Base64\Variant::UrlSafe);
        $inner = new IO\MemoryHandle($encoded);
        $handle = new Base64\DecodingReadHandle($inner, Base64\Variant::UrlSafe);

        static::assertSame($data, $handle->readAll());
    }

    public function testDecodingReadHandleWithNoPadding(): void
    {
        $data = 'Hi';
        $encoded = Base64\encode($data, padding: false);
        $inner = new IO\MemoryHandle($encoded);
        $handle = new Base64\DecodingReadHandle($inner, padding: false);

        static::assertSame($data, $handle->readAll());
    }

    public function testDecodingReadHandleRemainderHandling(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('A'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('A', $handle->readAll());
    }

    public function testDecodingWriteHandleWithVariant(): void
    {
        $data = 'Hello+World/Test';
        $encoded = Base64\encode($data, Base64\Variant::UrlSafe);

        $inner = new IO\MemoryHandle();
        $handle = new Base64\DecodingWriteHandle($inner, Base64\Variant::UrlSafe);

        $handle->writeAll($encoded);
        $handle->flush();

        $inner->seek(0);
        static::assertSame($data, $inner->readAll());
    }

    public function testDecodingWriteHandleWithNoPadding(): void
    {
        $data = 'Hi';
        $encoded = Base64\encode($data, padding: false);

        $inner = new IO\MemoryHandle();
        $handle = new Base64\DecodingWriteHandle($inner, padding: false);

        $handle->writeAll($encoded);
        $handle->flush();

        $inner->seek(0);
        static::assertSame($data, $inner->readAll());
    }

    public function testDecodingWriteHandleChunkedInput(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\DecodingWriteHandle($inner);

        $handle->write('SG');
        $handle->write('Vs');
        $handle->write('bG8=');
        $handle->flush();

        $inner->seek(0);
        static::assertSame('Hello', $inner->readAll());
    }

    public function testEncodingReadHandleTryRead(): void
    {
        $inner = new IO\MemoryHandle('ABC');
        $handle = new Base64\EncodingReadHandle($inner);

        $result = $handle->tryRead(4);
        static::assertSame('QUJD', substr($result, 0, 4));
    }

    public function testEncodingReadHandleReachedEndOfDataSource(): void
    {
        $inner = new IO\MemoryHandle('A');
        $handle = new Base64\EncodingReadHandle($inner);

        static::assertFalse($handle->reachedEndOfDataSource());

        $handle->readAll();

        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testEncodingReadHandleReadAfterEof(): void
    {
        $inner = new IO\MemoryHandle('A');
        $handle = new Base64\EncodingReadHandle($inner);

        $handle->readAll();

        static::assertSame('', $handle->read());
        static::assertSame('', $handle->tryRead());
    }

    public function testEncodingReadHandleReadByteOnEmpty(): void
    {
        $inner = new IO\MemoryHandle('');
        $handle = new Base64\EncodingReadHandle($inner);

        $this->expectException(IO\Exception\RuntimeException::class);

        $handle->readByte();
    }

    public function testEncodingReadHandleReadLineCRLF(): void
    {
        $data = "line1\r\nline2";
        $inner = new IO\MemoryHandle(base64_encode($data));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('line1', $handle->readLine());
        static::assertSame('line2', $handle->readLine());
    }

    public function testEncodingWriteHandleChunked(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\EncodingWriteHandle($inner);

        $handle->write('Hello');
        $handle->write(', World!');
        $handle->flush();

        $inner->seek(0);
        $encoded = $inner->readAll();

        $decodingHandle = new IO\MemoryHandle($encoded);
        $decoder = new Base64\DecodingReadHandle($decodingHandle);
        static::assertSame('Hello, World!', $decoder->readAll());
    }

    public function testEncodingWriteHandleWithVariant(): void
    {
        $data = 'Hello';
        $inner = new IO\MemoryHandle();
        $handle = new Base64\EncodingWriteHandle($inner, Base64\Variant::UrlSafe);

        $handle->writeAll($data);
        $handle->flush();

        $inner->seek(0);
        $decodingHandle = new IO\MemoryHandle($inner->readAll());
        $decoder = new Base64\DecodingReadHandle($decodingHandle, Base64\Variant::UrlSafe);
        static::assertSame($data, $decoder->readAll());
    }

    public function testDecodingReadHandleReadUntilBoundedSuffixFoundAfterFill(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('ab:end'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('ab', $handle->readUntilBounded(':end', 10));
    }

    public function testDecodingReadHandleReadLineEmpty(): void
    {
        $inner = new IO\MemoryHandle(base64_encode("\n\n"));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('', $handle->readLine());
        static::assertSame('', $handle->readLine());
        static::assertNull($handle->readLine());
    }

    public function testDecodingReadHandleTryReadOnEof(): void
    {
        $inner = new IO\MemoryHandle('');
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('', $handle->tryRead());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testDecodingTryReadWithNonEmptyBufferAndNotEof(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('ABCDEF'));
        $handle = new Base64\DecodingReadHandle($inner);

        $first = $handle->tryRead(2);
        static::assertSame('AB', $first);

        $second = $handle->tryRead(2);
        static::assertSame('CD', $second);
    }

    public function testDecodingTryReadMaxBytesEqualToBufferLength(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('XYZ'));
        $handle = new Base64\DecodingReadHandle($inner);

        $result = $handle->tryRead(3);
        static::assertSame('XYZ', $result);
        static::assertSame('', $handle->tryRead());
    }

    public function testDecodingReadReturnsEmptyOnEofWithEmptyBuffer(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('A'));
        $handle = new Base64\DecodingReadHandle($inner);

        $handle->readAll();
        $result = $handle->read();
        static::assertSame('', $result);
    }

    public function testDecodingReadReturnsEmptyAfterFillProducesNothing(): void
    {
        $inner = new IO\MemoryHandle('');
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('', $handle->read());
    }

    public function testDecodingReadMaxBytesExactBuffer(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('AB'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('AB', $handle->read(2));
    }

    public function testDecodingReadMaxBytesLessThanBuffer(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('ABCDEF'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('AB', $handle->read(2));
        static::assertSame('CDEF', $handle->read(100));
    }

    public function testDecodingReadByteWithMultiByteBuffer(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('AB'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('A', $handle->readByte());
        static::assertSame('B', $handle->readByte());
    }

    public function testDecodingReadLineBufferEmptyNotEof(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('no newline here'));
        $handle = new Base64\DecodingReadHandle($inner);

        $line = $handle->readLine();
        static::assertSame('no newline here', $line);
        static::assertNull($handle->readLine());
    }

    public function testDecodingReadLineCRLFStripping(): void
    {
        $inner = new IO\MemoryHandle(base64_encode("hello\r\nworld"));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('hello', $handle->readLine());
        static::assertSame('world', $handle->readLine());
    }

    public function testDecodingReadUntilOffsetCalculation(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('aXbXc'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('a', $handle->readUntil('X'));
        static::assertSame('b', $handle->readUntil('X'));
        static::assertNull($handle->readUntil('X'));
    }

    public function testDecodingReadUntilSuffixLongerThanInitialBuffer(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('a::end'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('a', $handle->readUntil('::end'));
    }

    public function testDecodingReadUntilBoundedBufferExactlyAtMax(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('abcde:s'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('abcde', $handle->readUntilBounded(':s', 5));
    }

    public function testDecodingReadUntilBoundedBufferOneOverMax(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('abcdef:s'));
        $handle = new Base64\DecodingReadHandle($inner);

        $this->expectException(IO\Exception\OverflowException::class);
        $handle->readUntilBounded(':s', 5);
    }

    public function testDecodingReadUntilBoundedSuffixConsumedCorrectly(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('ab:endrest'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('ab', $handle->readUntilBounded(':end', 10));
        static::assertSame('rest', $handle->readAll());
    }

    public function testDecodingFillBufferRemainderConcatOrder(): void
    {
        $encoded = base64_encode('Hello, World!');
        $part1 = substr($encoded, 0, 5); // 5 chars, remainder = 1 byte
        $part2 = substr($encoded, 5);

        $inner = new IO\MemoryHandle($part1 . $part2);
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('Hello, World!', $handle->readAll());
    }

    public function testDecodingFillBufferUsableZero(): void
    {
        $inner = new IO\MemoryHandle('SGVsbG8='); // "Hel" + "lo"
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('Hello', $handle->readAll());
    }

    public function testDecodingFillBufferRemainderOnEof(): void
    {
        $inner = new IO\MemoryHandle(base64_encode('Test'));
        $handle = new Base64\DecodingReadHandle($inner);

        static::assertSame('Test', $handle->readAll());
    }

    public function testDecodingPaddingTrueVsFalse(): void
    {
        $data = 'Hi';
        $withPadding = Base64\encode($data, padding: true);
        $withoutPadding = Base64\encode($data, padding: false);

        static::assertNotSame($withPadding, $withoutPadding);

        $h1 = new Base64\DecodingReadHandle(new IO\MemoryHandle($withPadding), padding: true);
        static::assertSame($data, $h1->readAll());

        $h2 = new Base64\DecodingReadHandle(new IO\MemoryHandle($withoutPadding), padding: false);
        static::assertSame($data, $h2->readAll());

        $this->expectException(Exception\IncorrectPaddingException::class);
        $h3 = new Base64\DecodingReadHandle(new IO\MemoryHandle($withoutPadding), padding: true);
        $h3->readAll();
    }

    public function testDecodingWritePaddingTrueVsFalse(): void
    {
        $data = 'Hi';
        $withPadding = Base64\encode($data, padding: true);
        $withoutPadding = Base64\encode($data, padding: false);

        $inner1 = new IO\MemoryHandle();
        $h1 = new Base64\DecodingWriteHandle($inner1, padding: true);
        $h1->writeAll($withPadding);
        $h1->flush();
        $inner1->seek(0);
        static::assertSame($data, $inner1->readAll());

        $inner2 = new IO\MemoryHandle();
        $h2 = new Base64\DecodingWriteHandle($inner2, padding: false);
        $h2->writeAll($withoutPadding);
        $h2->flush();
        $inner2->seek(0);
        static::assertSame($data, $inner2->readAll());
    }

    public function testDecodingWriteChunkBoundary(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\DecodingWriteHandle($inner);

        $handle->write('SGV');
        $inner->seek(0);
        static::assertSame('', $inner->readAll());

        $handle->write('s');
        $inner->seek(0);
        static::assertSame('Hel', $inner->readAll());
    }

    public function testDecodingWriteModulusCalculation(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\DecodingWriteHandle($inner);

        $handle->write('SGVsbG8=');
        $handle->flush();

        $inner->seek(0);
        static::assertSame('Hello', $inner->readAll());
    }

    public function testEncodingTryReadNonEmptyBufferNotEof(): void
    {
        $inner = new IO\MemoryHandle('ABCDEFGHIJ');
        $handle = new Base64\EncodingReadHandle($inner);

        $first = $handle->tryRead(4);
        static::assertSame(4, strlen($first));

        $second = $handle->tryRead(4);
        static::assertSame(4, strlen($second));
    }

    public function testEncodingTryReadMaxBytesExactBuffer(): void
    {
        $inner = new IO\MemoryHandle('ABC');
        $handle = new Base64\EncodingReadHandle($inner);

        $all = $handle->readAll();
        $totalLen = strlen($all);

        $inner2 = new IO\MemoryHandle('ABC');
        $handle2 = new Base64\EncodingReadHandle($inner2);
        $result = $handle2->tryRead($totalLen);
        static::assertSame($totalLen, strlen($result));
    }

    public function testEncodingTryReadLessThanBuffer(): void
    {
        $inner = new IO\MemoryHandle('Hello, World!');
        $handle = new Base64\EncodingReadHandle($inner);

        $part1 = $handle->tryRead(4);
        static::assertSame(4, strlen($part1));

        $rest = $handle->tryRead();
        static::assertGreaterThan(0, strlen($rest));

        // The trailing partial group is only encoded once the end of the inner handle is reached.
        $rest .= $handle->readAll();
        static::assertSame(Base64\encode('Hello, World!'), $part1 . $rest);

        $decodingHandle = new IO\MemoryHandle($part1 . $rest);
        $decoder = new Base64\DecodingReadHandle($decodingHandle);
        static::assertSame('Hello, World!', $decoder->readAll());
    }

    public function testEncodingReadEofWithEmptyBuffer(): void
    {
        $inner = new IO\MemoryHandle('A');
        $handle = new Base64\EncodingReadHandle($inner);

        $handle->readAll();

        static::assertSame('', $handle->read());
        static::assertSame('', $handle->read());
    }

    public function testEncodingReadMaxBytesExact(): void
    {
        $inner = new IO\MemoryHandle('AB');
        $handle = new Base64\EncodingReadHandle($inner);

        $all = $handle->readAll();
        $len = strlen($all);

        $inner2 = new IO\MemoryHandle('AB');
        $handle2 = new Base64\EncodingReadHandle($inner2);
        static::assertSame($all, $handle2->read($len));
    }

    public function testEncodingReadByteSubstr(): void
    {
        $inner = new IO\MemoryHandle('ABCDE');
        $handle = new Base64\EncodingReadHandle($inner);

        $byte1 = $handle->readByte();
        $byte2 = $handle->readByte();
        static::assertNotSame($byte1, $byte2);

        $rest = $handle->readAll();
        static::assertGreaterThan(0, strlen($rest));
    }

    public function testEncodingReadAndTryReadReturnEmptyAfterEof(): void
    {
        $inner = new IO\MemoryHandle('');
        $handle = new Base64\EncodingReadHandle($inner);

        static::assertSame('', $handle->tryRead());
        static::assertSame('', $handle->read());
        static::assertSame('', $handle->tryRead(10));
    }

    public function testDecodingWriteRemainderConcatOrder(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\DecodingWriteHandle($inner);
        $handle->write('SG');
        $handle->write('Vs');
        $inner->seek(0);
        static::assertSame('Hel', $inner->readAll());
        $handle->flush();
    }

    public function testDecodingWriteSingleCharNoOutput(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\DecodingWriteHandle($inner);
        $handle->write('S');
        $inner->seek(0);
        static::assertSame('', $inner->readAll());
    }

    public function testDecodingWriteModulusCalc(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\DecodingWriteHandle($inner);
        $handle->write('SGVsb');
        $inner->seek(0);
        static::assertSame('Hel', $inner->readAll());
    }

    public function testDecodingWriteFlushEmpty(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\DecodingWriteHandle($inner);
        $handle->flush();
        $inner->seek(0);
        static::assertSame('', $inner->readAll());
    }

    public function testEncodingWriteExactChunkSize(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\EncodingWriteHandle($inner);
        $handle->write(str_repeat('A', 57));
        $inner->seek(0);
        $encoded = $inner->readAll();
        static::assertGreaterThan(0, strlen($encoded));
    }

    public function testEncodingWriteFlushWithRemainder(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\EncodingWriteHandle($inner);
        $handle->write('Hello');
        $handle->flush();
        $inner->seek(0);
        $decodingHandle = new IO\MemoryHandle($inner->readAll());
        $decoder = new Base64\DecodingReadHandle($decodingHandle);
        static::assertSame('Hello', $decoder->readAll());
    }

    public function testEncodingWriteFlushEmpty(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\EncodingWriteHandle($inner);
        $handle->flush();
        $inner->seek(0);
        static::assertSame('', $inner->readAll());
    }
}
