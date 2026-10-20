<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\Event\DataReceived;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame\DataFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Internal\StateMachine;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function str_repeat;

/**
 * `content-length` validation against received DATA frames.
 *
 * See GHSA-pw9p-jvrm-f7rm and RFC 9113 §8.1.1 / §8.1.2.6.
 */
final class ContentLengthValidationTest extends TestCase
{
    /**
     * Open a server-side stream with the given content-length header (or none).
     *
     * @return array{StateMachine, Encoder}
     */
    private function createServerWithStream(null|string $contentLength = null, bool $endStream = false): array
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $headers = [
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ];

        if ($contentLength !== null) {
            $headers[] = new Header('content-length', $contentLength);
        }

        $encoder = new Encoder();
        $block = $encoder->encode($headers);
        $sm->receive(new HeadersFrame(1, $block, $endStream, true)->toRaw());

        return [$sm, $encoder];
    }

    public function testDataMatchingDeclaredContentLengthSucceeds(): void
    {
        [$sm] = $this->createServerWithStream('5');

        $dataRaw = new DataFrame(1, 'hello', true)->toRaw();
        [, $events] = $sm->receive($dataRaw);

        static::assertNotEmpty($events);
        static::assertInstanceOf(DataReceived::class, $events[0]);
        static::assertSame('hello', $events[0]->data);
        static::assertTrue($events[0]->endStream);
    }

    public function testDataExceedingDeclaredContentLengthThrows(): void
    {
        [$sm] = $this->createServerWithStream('5');

        $dataRaw = new DataFrame(1, 'too much data', false)->toRaw();

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('content-length mismatch');

        $sm->receive($dataRaw);
    }

    public function testEndStreamWithLessDataThanDeclaredThrows(): void
    {
        [$sm] = $this->createServerWithStream('10');

        $dataRaw = new DataFrame(1, 'short', true)->toRaw();

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('content-length mismatch');

        $sm->receive($dataRaw);
    }

    public function testEndStreamOnHeadersWithDeclaredContentLengthButNoDataThrows(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('content-length mismatch');

        $this->createServerWithStream('5', endStream: true);
    }

    public function testEndStreamOnHeadersWithZeroContentLengthSucceeds(): void
    {
        [$sm] = $this->createServerWithStream('0', endStream: true);

        static::assertNotNull($sm);
    }

    public function testMalformedContentLengthNonDigitThrows(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('malformed content-length');

        $this->createServerWithStream('not-a-number');
    }

    public function testMalformedContentLengthEmptyThrows(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('malformed content-length');

        $this->createServerWithStream('');
    }

    public function testMalformedContentLengthNegativeThrows(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('malformed content-length');

        $this->createServerWithStream('-1');
    }

    public function testMalformedContentLengthHexThrows(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('malformed content-length');

        $this->createServerWithStream('0x10');
    }

    public function testMalformedContentLengthWithWhitespaceThrows(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('malformed content-length');

        $this->createServerWithStream(' 5');
    }

    public function testMissingContentLengthDoesNotEnforceValidation(): void
    {
        [$sm] = $this->createServerWithStream(null);

        $dataRaw = new DataFrame(1, 'arbitrary length payload', true)->toRaw();
        [, $events] = $sm->receive($dataRaw);

        static::assertNotEmpty($events);
        static::assertInstanceOf(DataReceived::class, $events[0]);
    }

    public function testDataSplitAcrossMultipleFramesMatchingTotalSucceeds(): void
    {
        [$sm] = $this->createServerWithStream('10');

        $sm->receive(new DataFrame(1, 'four', false)->toRaw());
        $sm->receive(new DataFrame(1, 'four', false)->toRaw());
        [, $events] = $sm->receive(new DataFrame(1, 'go', true)->toRaw());

        static::assertNotEmpty($events);
        static::assertInstanceOf(DataReceived::class, $events[0]);
        static::assertTrue($events[0]->endStream);
    }

    public function testDataExceedingContentLengthAcrossMultipleFramesThrows(): void
    {
        [$sm] = $this->createServerWithStream('5');

        $sm->receive(new DataFrame(1, 'abc', false)->toRaw());

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('content-length mismatch');

        $sm->receive(new DataFrame(1, 'def', false)->toRaw());
    }

    public function testHeadResponseDoesNotValidateContentLength(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $sm->sendHeadersEncoded(
            1,
            [new Header(':method', 'HEAD'), new Header(':scheme', 'https'), new Header(':path', '/')],
            true,
        );

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':status', '200'),
            new Header('content-length', '100'),
        ]);

        [, $events] = $sm->receive(new HeadersFrame(1, $block, true, true)->toRaw());
        static::assertNotEmpty($events);
    }

    public function testTrailingHeadersContentLengthIsIgnored(): void
    {
        [$sm, $encoder] = $this->createServerWithStream('5');

        $sm->receive(new DataFrame(1, 'hello', false)->toRaw());

        $trailerBlock = $encoder->encode([new Header('x-checksum', 'abc')]);
        $sm->receive(new HeadersFrame(1, $trailerBlock, true, true)->toRaw());

        static::assertTrue(true);
    }

    public function testLargeContentLengthMatchingActualPayloadSucceeds(): void
    {
        $size = 16_000;
        [$sm] = $this->createServerWithStream((string) $size);

        $sm->receive(new DataFrame(1, str_repeat('x', $size), true)->toRaw());

        static::assertTrue(true);
    }

    /**
     * Open a server-side stream carrying the given content-length fields, in order.
     *
     * @param list<string> $contentLengths
     *
     * @return array{StateMachine, Encoder}
     */
    private function createServerWithContentLengths(array $contentLengths): array
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $headers = [
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ];

        foreach ($contentLengths as $value) {
            $headers[] = new Header('content-length', $value);
        }

        $encoder = new Encoder();
        $block = $encoder->encode($headers);
        $sm->receive(new HeadersFrame(1, $block, false, true)->toRaw());

        return [$sm, $encoder];
    }

    public function testConflictingContentLengthFieldsThrow(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('conflicting content-length');

        $this->createServerWithContentLengths(['5', '500']);
    }

    public function testConflictingContentLengthFieldsThrowRegardlessOfOrder(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('conflicting content-length');

        $this->createServerWithContentLengths(['500', '5']);
    }

    public function testMalformedContentLengthAfterAValidOneThrows(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('malformed content-length');

        $this->createServerWithContentLengths(['5', 'not-a-number']);
    }

    public function testIdenticalDuplicateContentLengthFieldsCollapse(): void
    {
        [$sm] = $this->createServerWithContentLengths(['5', '5']);

        [, $events] = $sm->receive(new DataFrame(1, 'hello', true)->toRaw());

        static::assertNotEmpty($events);
        static::assertInstanceOf(DataReceived::class, $events[0]);
        static::assertSame('hello', $events[0]->data);
    }

    public function testCollapsedDuplicateContentLengthIsStillEnforcedAgainstData(): void
    {
        [$sm] = $this->createServerWithContentLengths(['5', '5']);

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('content-length mismatch');

        $sm->receive(new DataFrame(1, 'too much data', false)->toRaw());
    }

    public function testConflictingContentLengthOnResponseThrowsForClient(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $sm->sendHeadersEncoded(
            1,
            [new Header(':method', 'GET'), new Header(':scheme', 'https'), new Header(':path', '/')],
            true,
        );

        $block = new Encoder()->encode([
            new Header(':status', '200'),
            new Header('content-length', '5'),
            new Header('content-length', '500'),
        ]);

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('conflicting content-length');

        $sm->receive(new HeadersFrame(1, $block, false, true)->toRaw());
    }
}
