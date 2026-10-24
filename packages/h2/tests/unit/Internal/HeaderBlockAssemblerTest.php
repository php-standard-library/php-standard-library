<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Internal\HeaderBlockAssembler;

use function str_repeat;
use function strlen;

use const Psl\H2\DEFAULT_MAX_HEADER_BLOCK_SIZE;

final class HeaderBlockAssemblerTest extends TestCase
{
    public function testNotActiveByDefault(): void
    {
        $assembler = new HeaderBlockAssembler();

        static::assertFalse($assembler->isActive());
        static::assertNull($assembler->activeStreamId());
    }

    public function testStartHeaders(): void
    {
        $assembler = new HeaderBlockAssembler();
        $assembler->startHeaders(1, 'fragment1', false);

        static::assertTrue($assembler->isActive());
        static::assertSame(1, $assembler->activeStreamId());
    }

    public function testAppendAndComplete(): void
    {
        $assembler = new HeaderBlockAssembler();
        $assembler->startHeaders(1, 'part1', true);
        $assembler->append('part2');
        $assembler->append('part3');

        [$buffer, $endStream, $isPushPromise, $promisedStreamId] = $assembler->complete();

        static::assertSame('part1part2part3', $buffer);
        static::assertTrue($endStream);
        static::assertFalse($isPushPromise);
        static::assertSame(0, $promisedStreamId);
        static::assertFalse($assembler->isActive());
    }

    public function testStartPushPromise(): void
    {
        $assembler = new HeaderBlockAssembler();
        $assembler->startPushPromise(1, 2, 'promise');
        $assembler->append('more');

        [$buffer, $endStream, $isPushPromise, $promisedStreamId] = $assembler->complete();

        static::assertSame('promisemore', $buffer);
        static::assertFalse($endStream);
        static::assertTrue($isPushPromise);
        static::assertSame(2, $promisedStreamId);
    }

    public function testReset(): void
    {
        $assembler = new HeaderBlockAssembler();
        $assembler->startHeaders(1, 'data', false);
        $assembler->reset();

        static::assertFalse($assembler->isActive());
        static::assertNull($assembler->activeStreamId());
    }

    public function testStartHeadersExceedsMaxBufferSize(): void
    {
        $assembler = new HeaderBlockAssembler(10);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Header block size exceeds maximum');

        $assembler->startHeaders(1, 'this-is-too-long-for-buffer', false);
    }

    public function testStartPushPromiseExceedsMaxBufferSize(): void
    {
        $assembler = new HeaderBlockAssembler(10);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Header block size exceeds maximum');

        $assembler->startPushPromise(1, 2, 'this-is-too-long-for-buffer');
    }

    public function testAppendExceedsMaxBufferSize(): void
    {
        $assembler = new HeaderBlockAssembler(20);
        $assembler->startHeaders(1, 'short', false);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Header block size exceeds maximum');

        $assembler->append('this-makes-it-too-long');
    }

    public function testBufferSizeExceededResetsAssembler(): void
    {
        $assembler = new HeaderBlockAssembler(10);

        try {
            $assembler->startHeaders(1, 'this-is-too-long-for-buffer', false);
            static::fail();
        } catch (ProtocolException) {
            static::addToAssertionCount(1);
        }

        static::assertFalse($assembler->isActive());
        static::assertNull($assembler->activeStreamId());
    }

    public function testUnlimitedBufferSize(): void
    {
        $assembler = new HeaderBlockAssembler(0);
        $assembler->startHeaders(1, str_repeat('x', 100_000), false);

        static::assertTrue($assembler->isActive());
    }

    public function testCompleteResetsState(): void
    {
        $assembler = new HeaderBlockAssembler();
        $assembler->startPushPromise(1, 2, 'data');

        [$buffer, $endStream, $isPushPromise, $promisedStreamId] = $assembler->complete();

        static::assertSame('data', $buffer);
        static::assertFalse($endStream);
        static::assertTrue($isPushPromise);
        static::assertSame(2, $promisedStreamId);
        static::assertFalse($assembler->isActive());
    }

    public function testStartHeadersClearsPreviousPushPromiseState(): void
    {
        $assembler = new HeaderBlockAssembler();
        $assembler->startPushPromise(1, 2, 'push');
        $assembler->reset();
        $assembler->startHeaders(3, 'headers', true);

        [$buffer, $endStream, $isPushPromise, $promisedStreamId] = $assembler->complete();

        static::assertSame('headers', $buffer);
        static::assertTrue($endStream);
        static::assertFalse($isPushPromise);
        static::assertSame(0, $promisedStreamId);
    }

    public function testDefaultLimitRefusesTheFragmentThatWouldExceedSixtyFourKibibytes(): void
    {
        $assembler = new HeaderBlockAssembler();
        $assembler->startHeaders(1, str_repeat('a', DEFAULT_MAX_HEADER_BLOCK_SIZE), false);

        try {
            $assembler->append('b');
            static::fail('Expected the default limit to refuse the fragment.');
        } catch (ProtocolException $error) {
            static::assertSame(
                'HTTP/2 connection error: Header block size exceeds maximum of '
                . DEFAULT_MAX_HEADER_BLOCK_SIZE
                . ' bytes.',
                $error->getMessage(),
            );
            static::assertSame(ErrorCode::ProtocolError, $error->errorCode);
            static::assertFalse($assembler->isActive());
        }
    }

    public function testZeroLiftsTheLimit(): void
    {
        $assembler = new HeaderBlockAssembler(0);
        $assembler->startHeaders(1, str_repeat('a', DEFAULT_MAX_HEADER_BLOCK_SIZE), false);
        $assembler->append(str_repeat('b', DEFAULT_MAX_HEADER_BLOCK_SIZE));

        [$buffer] = $assembler->complete();

        static::assertSame(2 * DEFAULT_MAX_HEADER_BLOCK_SIZE, strlen($buffer));
    }

    public function testARefusedFragmentIsNotBuffered(): void
    {
        $assembler = new HeaderBlockAssembler(10);
        $assembler->startHeaders(1, 'short', false);

        try {
            $assembler->append('this-makes-it-too-long');
            static::fail('Expected the limit to refuse the fragment.');
        } catch (ProtocolException) {
            static::assertFalse($assembler->isActive());
        }

        $assembler->startHeaders(3, 'next', true);
        $assembler->append('block');

        [$buffer, $endStream] = $assembler->complete();

        static::assertSame('nextblock', $buffer);
        static::assertTrue($endStream);
    }
}
