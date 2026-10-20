<?php

declare(strict_types=1);

namespace Psl\HPACK\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\HPACK\Decoder;
use Psl\HPACK\Encoder;
use Psl\HPACK\Exception\InvalidTableIndexException;
use Psl\HPACK\Header;

final class DecoderTableLimitTest extends TestCase
{
    #[DataProvider('provideAllowedLimits')]
    public function testLimitChangePreservesPeerTableSize(int $limit): void
    {
        $encoder = new Encoder();
        $decoder = new Decoder();
        $encoder->resize(64);
        $decoder->decode($encoder->encode([new Header('x-a', 'first')]));
        $decoder->resize($limit);
        $decoder->decode($encoder->encode([new Header('x-b', 'second')]));

        $this->expectException(InvalidTableIndexException::class);
        $decoder->decode("\xbf");
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideAllowedLimits(): iterable
    {
        yield 'larger limit' => [8192];
        yield 'smaller limit above selected size' => [128];
    }
}
