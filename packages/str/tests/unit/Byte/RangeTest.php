<?php

declare(strict_types=1);

namespace Psl\Str\Tests\Unit\Byte;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Range;
use Psl\Str\Byte;
use Psl\Str\Exception;

final class RangeTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testRange(string $expected, string $string, Range\RangeInterface $range): void
    {
        static::assertSame($expected, Byte\range($string, $range));
    }

    /**
     * @return list<array{0: string, 1: string, 2: Range\RangeInterface}>
     */
    public static function provideData(): array
    {
        return [
            ['', '', Range\between(0, 5, upperInclusive: true)],
            ['Hello,', 'Hello, World!', Range\between(0, 5, upperInclusive: true)],
            ['Hello', 'Hello, World!', Range\between(0, 5, upperInclusive: false)],
            ['Hello, World!', 'Hello, World!', Range\from(0)],
            ['World!', 'Hello, World!', Range\between(7, 12, upperInclusive: true)],
            ['World', 'Hello, World!', Range\between(7, 12, upperInclusive: false)],
            ['World', 'Hello, World!', Range\between(7, 11, upperInclusive: true)],
            [
                'destiny',
                'People linked by destiny will always find each other.',
                Range\between(17, 23, upperInclusive: true),
            ],
            [
                'destiny',
                'People linked by destiny will always find each other.',
                Range\between(17, 24, upperInclusive: false),
            ],
            ['hel', 'hello world', Range\to(3, inclusive: false)],
            ['', 'lo world', Range\between(3, 3)],
            ['', 'foo', Range\between(3, 3)],
            ['', 'foo', Range\between(3, 12)],
            ['foo', 'foo', Range\full()],
        ];
    }

    public function testRangeThrowsForOutOfBoundOffset(): void
    {
        $this->expectException(Exception\OutOfBoundsException::class);

        Byte\range('Hello', Range\from(10));
    }

    public function testRangeThrowsForNegativeOutOfBoundOffset(): void
    {
        $this->expectException(Exception\OutOfBoundsException::class);

        Byte\range('Hello', Range\from(-6));
    }
}
