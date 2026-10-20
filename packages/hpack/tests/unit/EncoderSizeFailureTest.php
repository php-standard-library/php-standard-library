<?php

declare(strict_types=1);

namespace Psl\HPACK\Tests\Unit;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\HPACK\Decoder;
use Psl\HPACK\Encoder;
use Psl\HPACK\Exception\HeaderListSizeException;
use Psl\HPACK\Header;

use function str_repeat;

final class EncoderSizeFailureTest extends TestCase
{
    #[DataProvider('provideEncodingModes')]
    public function testRejectedHeadersPreserveEncoderState(bool $withStatus, bool $resize): void
    {
        $encoder = new Encoder(4096, 150);
        $expected = new Encoder(4096, 150);
        $decoder = new Decoder();
        $seed = new Header('x-seed', 'old');
        $decoder->decode($encoder->encode([$seed]));
        $expected->encode([$seed]);

        if ($resize) {
            $encoder->resize(64);
            $encoder->resize(256);
            $expected->resize(64);
            $expected->resize(256);
            $decoder->resize(256);
        }

        $headers = (static function () use ($seed): Generator {
            yield 0 => $seed;
            yield 0 => new Header('x-new', 'value');
            yield 0 => new Header('x-large', str_repeat('x', 100));
        })();

        try {
            if ($withStatus) {
                $encoder->encodeWithStatus('201', $headers);
            } else {
                $encoder->encode($headers);
            }

            static::fail('The header list exceeds the configured limit.');
        } catch (HeaderListSizeException) {
            $valid = [new Header('x-new', 'value'), $seed];
            $retry = (static function () use ($valid): Generator {
                foreach ($valid as $header) {
                    yield 0 => $header;
                }
            })();

            if ($withStatus) {
                $encoded = $encoder->encodeWithStatus('201', $retry);
                static::assertSame($expected->encodeWithStatus('201', $valid), $encoded);
                static::assertEquals([new Header(':status', '201'), ...$valid], $decoder->decode($encoded));
            } else {
                $encoded = $encoder->encode($retry);
                static::assertSame($expected->encode($valid), $encoded);
                static::assertEquals($valid, $decoder->decode($encoded));
            }
        }
    }

    public function testRejectedStatusPreservesPendingTableSizeUpdate(): void
    {
        $encoder = new Encoder(4096, 0);
        $encoder->resize(64);
        $expected = new Encoder(4096, 0);
        $expected->resize(64);

        try {
            $encoder->encodeWithStatus('201', []);
            static::fail('The status exceeds the configured limit.');
        } catch (HeaderListSizeException) {
            static::assertSame($expected->encode([]), $encoder->encode([]));
        }
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function provideEncodingModes(): iterable
    {
        yield 'headers' => [false, false];
        yield 'headers with pending resize' => [false, true];
        yield 'response' => [true, false];
        yield 'response with pending resize' => [true, true];
    }
}
