<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit\Hex;

use LogicException;
use PHPUnit\Framework\TestCase;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Encoding\Exception;
use Psl\Encoding\Hex;
use Psl\IO;

use function bin2hex;
use function str_repeat;
use function substr;

final class DecodingReadHandleTest extends TestCase
{
    public function testTryReadBufferNonEmptyNotEofDoesNotRefill(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('ABCDEF')));
        $first = $handle->tryRead(2);
        static::assertSame('AB', $first);
        $second = $handle->tryRead(2);
        static::assertSame('CD', $second);
    }

    public function testTryReadEmptyHandleReturnsEmptyString(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(''));
        static::assertSame('', $handle->tryRead());
    }

    public function testTryReadMaxBytesExactlyEqualsBuffer(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('XYZ')));
        $result = $handle->tryRead(3);
        static::assertSame('XYZ', $result);
        static::assertSame('', $handle->tryRead());
    }

    public function testTryReadNullMaxBytesReturnsAll(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('ABCDEF')));
        static::assertSame('ABCDEF', $handle->tryRead(null));
    }

    public function testTryReadMaxBytesLessThanBufferReturnsPartial(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('ABCDEF')));
        static::assertSame('A', $handle->tryRead(1));
        static::assertSame('BCDEF', $handle->tryRead());
    }

    public function testReadAfterEofWithEmptyBufferReturnsEmptyString(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('A')));
        $handle->readAll();
        static::assertSame('', $handle->read());
    }

    public function testReadAfterEofReturnsEmptyNotNull(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('X')));
        $handle->readAll();
        $result = $handle->read();
        static::assertIsString($result);
        static::assertSame('', $result);
    }

    public function testReadEmptyHandleReturnsEmptyString(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(''));
        static::assertSame('', $handle->read());
    }

    public function testReadMaxBytesExactlyEqualsBuffer(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('AB')));
        static::assertSame('AB', $handle->read(2));
    }

    public function testReadMaxBytesLessThanBuffer(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('ABCDEF')));
        static::assertSame('AB', $handle->read(2));
        static::assertSame('CDEF', $handle->read(100));
    }

    public function testReadMultipleSequentialReadsAfterEof(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('Z')));
        $handle->readAll();
        static::assertSame('', $handle->read());
        static::assertSame('', $handle->read());
        static::assertSame('', $handle->read());
    }

    public function testReadMaxBytesLargerThanBuffer(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('AB')));
        static::assertSame('AB', $handle->read(100));
    }

    public function testReadNullMaxBytesReturnsAll(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('Hello')));
        static::assertSame('Hello', $handle->read(null));
    }

    public function testReadByteSingleByteHandle(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('X')));
        static::assertSame('X', $handle->readByte());
    }

    public function testReadByteSecondIsDifferent(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('AB')));
        static::assertSame('A', $handle->readByte());
        static::assertSame('B', $handle->readByte());
    }

    public function testReadByteEmptyHandleThrows(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(''));
        $this->expectException(IO\Exception\RuntimeException::class);
        $handle->readByte();
    }

    public function testReadByteMultiByteBufferThenReadByte(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('ABCDE')));
        static::assertSame('A', $handle->readByte());
        static::assertSame('B', $handle->readByte());
        static::assertSame('CDE', $handle->readAll());
    }

    public function testReadLineWithCRLFStripsCR(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex("hello\r\nworld")));
        static::assertSame('hello', $handle->readLine());
        static::assertSame('world', $handle->readLine());
    }

    public function testReadLineWithoutNewlineReturnsContentThenNull(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('no newline')));
        static::assertSame('no newline', $handle->readLine());
        static::assertNull($handle->readLine());
    }

    public function testReadLineEmptyLines(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex("\n\n")));
        static::assertSame('', $handle->readLine());
        static::assertSame('', $handle->readLine());
        static::assertNull($handle->readLine());
    }

    public function testReadLineAfterPartialRead(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex("first\nsecond")));
        static::assertSame('first', $handle->readLine());
        static::assertSame('second', $handle->readAll());
    }

    public function testReadLineOnEmptyReturnsNull(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(''));
        static::assertNull($handle->readLine());
    }

    public function testReadLineWithLF(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex("a\nb\nc")));
        static::assertSame('a', $handle->readLine());
        static::assertSame('b', $handle->readLine());
        static::assertSame('c', $handle->readLine());
        static::assertNull($handle->readLine());
    }

    public function testReadUntilSuffixFoundImmediately(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('key=value')));
        static::assertSame('key', $handle->readUntil('='));
    }

    public function testReadUntilMultipleSuffixes(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('a|b|c')));
        static::assertSame('a', $handle->readUntil('|'));
        static::assertSame('b', $handle->readUntil('|'));
        static::assertNull($handle->readUntil('|'));
    }

    public function testReadUntilSuffixNotFound(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('no pipe')));
        static::assertNull($handle->readUntil('|'));
    }

    public function testReadUntilRemainingDataCorrect(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('abc|def')));
        static::assertSame('abc', $handle->readUntil('|'));
        static::assertSame('def', $handle->readAll());
    }

    public function testReadUntilLongSuffix(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('data::END::rest')));
        static::assertSame('data', $handle->readUntil('::END::'));
        static::assertSame('rest', $handle->readAll());
    }

    public function testReadUntilBoundedContentExactlyAtMaxBytes(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('abcde:s')));
        static::assertSame('abcde', $handle->readUntilBounded(':s', 5));
    }

    public function testReadUntilBoundedContentOneOverMaxBytesThrows(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('abcdef:s')));
        $this->expectException(IO\Exception\OverflowException::class);
        $handle->readUntilBounded(':s', 5);
    }

    public function testReadUntilBoundedSuffixFoundWithinLimit(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('ab:end')));
        static::assertSame('ab', $handle->readUntilBounded(':end', 10));
    }

    public function testReadUntilBoundedSuffixFoundBeyondLimitThrows(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('toolongcontent:end')));
        $this->expectException(IO\Exception\OverflowException::class);
        $handle->readUntilBounded(':end', 3);
    }

    public function testReadUntilBoundedNotFoundWithinLimit(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('no suffix')));
        static::assertNull($handle->readUntilBounded('@', 100));
    }

    public function testReadUntilBoundedRemainingDataCorrect(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('ab:endrest')));
        static::assertSame('ab', $handle->readUntilBounded(':end', 10));
        static::assertSame('rest', $handle->readAll());
    }

    public function testReadUntilBoundedBufferGrowsPastLimitThrows(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex(str_repeat('x', 100) . ':end')));
        $this->expectException(IO\Exception\OverflowException::class);
        $handle->readUntilBounded(':end', 10);
    }

    public function testFillBufferRemainderConcatOrder(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('Hello, World!')));
        static::assertSame('Hello, World!', $handle->readAll());
    }

    public function testFillBufferTwoBytePair(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle('48656c6c6f'));
        static::assertSame('Hello', $handle->readAll());
    }

    public function testFillBufferRemainderSingleChar(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('A')));
        static::assertSame('A', $handle->readAll());
    }

    public function testFillBufferBinaryData(): void
    {
        $binary = "\x00\x01\x02\xff\xfe\xfd";
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex($binary)));
        static::assertSame($binary, $handle->readAll());
    }

    public function testReachedEndOfDataSourceStates(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('A')));
        static::assertFalse($handle->reachedEndOfDataSource());
        $handle->readAll();
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testTryReadOnEofReturnsEmpty(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(''));
        static::assertSame('', $handle->tryRead());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testReadByteAfterEofThrows(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex('A')));
        $handle->readAll();
        $this->expectException(IO\Exception\RuntimeException::class);
        $handle->readByte();
    }

    public function testInvalidHexThrows(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle('ZZZZ'));
        $this->expectException(Exception\RangeException::class);
        $handle->readAll();
    }

    public function testInterleavedMethods(): void
    {
        $handle = new Hex\DecodingReadHandle(new IO\MemoryHandle(bin2hex("line1|line2\nline3")));
        static::assertSame('line1', $handle->readUntil('|'));
        static::assertSame('line2', $handle->readLine());
        static::assertSame('l', $handle->readByte());
        static::assertSame('ine3', $handle->readAll());
    }

    public function testReadWaitsForAWholeByte(): void
    {
        // The inner handle returns one hex character per read: every other read ends in the middle of a pair.
        $handle = new Hex\DecodingReadHandle(self::trickle(bin2hex('Hello')));

        $decoded = '';
        while (!$handle->reachedEndOfDataSource()) {
            $chunk = $handle->read();
            static::assertTrue(
                '' !== $chunk || $handle->reachedEndOfDataSource(),
                'read() returned nothing before EOF.',
            );
            $decoded .= $chunk;
        }

        static::assertSame('Hello', $decoded);
    }

    public function testReadByteWaitsForAWholeByte(): void
    {
        $handle = new Hex\DecodingReadHandle(self::trickle(bin2hex('Hi')));

        static::assertSame('H', $handle->readByte());
        static::assertSame('i', $handle->readByte());
    }

    public function testTryReadMayReturnNothingInTheMiddleOfAPair(): void
    {
        $inner = new class() implements IO\ReadHandleInterface {
            use IO\ReadHandleConvenienceMethodsTrait;

            public string $available = '4';

            public function reachedEndOfDataSource(): bool
            {
                return false;
            }

            public function tryRead(null|int $maxBytes = null): string
            {
                $chunk = $this->available;
                $this->available = '';

                return $chunk;
            }

            public function read(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                throw new LogicException('read() waits for data that never comes.');
            }
        };
        $handle = new Hex\DecodingReadHandle($inner);

        static::assertSame('', $handle->tryRead());
        static::assertFalse($handle->reachedEndOfDataSource());

        $inner->available = '8';
        static::assertSame('H', $handle->tryRead());
    }

    /**
     * A handle that returns its data one byte per read.
     */
    private static function trickle(string $data): IO\ReadHandleInterface
    {
        return new class($data) implements IO\ReadHandleInterface {
            use IO\ReadHandleConvenienceMethodsTrait;

            public function __construct(
                private string $data,
            ) {}

            public function reachedEndOfDataSource(): bool
            {
                return '' === $this->data;
            }

            public function tryRead(null|int $maxBytes = null): string
            {
                $chunk = substr($this->data, 0, 1);
                $this->data = substr($this->data, 1);

                return $chunk;
            }

            public function read(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return $this->tryRead($maxBytes);
            }
        };
    }
}
