<?php

declare(strict_types=1);

namespace Psl\Terminal\Tests\Unit\Widget;

use ErrorException;
use PHPUnit\Framework\TestCase;
use Psl\Ansi;
use Psl\Ansi\Color;
use Psl\Ansi\Style;
use Psl\Terminal\Buffer;
use Psl\Terminal\Rect;
use Psl\Terminal\Widget\BarChart;

use function restore_error_handler;
use function set_error_handler;

use const NAN;

final class BarChartTest extends TestCase
{
    public function testBasicBar(): void
    {
        $buffer = new Buffer(3, 6);
        $area = new Rect(0, 0, 3, 6);

        BarChart::new()
            ->data([['A', 1.0]])
            ->barWidth(3)
            ->barGap(0)
            ->render($area, $buffer);

        for ($row = 0; $row < 5; $row++) {
            static::assertSame("\u{2588}", $buffer->get(0, $row)?->grapheme, "Row {$row} col 0");
            static::assertSame("\u{2588}", $buffer->get(1, $row)?->grapheme, "Row {$row} col 1");
            static::assertSame("\u{2588}", $buffer->get(2, $row)?->grapheme, "Row {$row} col 2");
        }

        static::assertSame('A', $buffer->get(1, 5)?->grapheme);
    }

    public function testHalfBar(): void
    {
        $buffer = new Buffer(3, 5);
        $area = new Rect(0, 0, 3, 5);

        BarChart::new()
            ->data([['X', 0.5]])
            ->barWidth(3)
            ->barGap(0)
            ->render($area, $buffer);

        static::assertSame(' ', $buffer->get(0, 0)?->grapheme); // empty
        static::assertSame(' ', $buffer->get(0, 1)?->grapheme); // empty
        static::assertSame("\u{2588}", $buffer->get(0, 2)?->grapheme); // filled
        static::assertSame("\u{2588}", $buffer->get(0, 3)?->grapheme); // filled

        static::assertSame('X', $buffer->get(1, 4)?->grapheme);
    }

    public function testMultipleBars(): void
    {
        $buffer = new Buffer(7, 4);
        $area = new Rect(0, 0, 7, 4);

        BarChart::new()
            ->data([['A', 1.0], ['B', 0.0]])
            ->barWidth(3)
            ->barGap(1)
            ->render($area, $buffer);

        static::assertSame("\u{2588}", $buffer->get(0, 0)?->grapheme);
        static::assertSame("\u{2588}", $buffer->get(0, 2)?->grapheme);
        static::assertSame(' ', $buffer->get(4, 0)?->grapheme);
        static::assertSame(' ', $buffer->get(4, 2)?->grapheme);
        static::assertSame('A', $buffer->get(1, 3)?->grapheme);
        static::assertSame('B', $buffer->get(5, 3)?->grapheme);
    }

    public function testEmptyDataRendersNothing(): void
    {
        $buffer = new Buffer(10, 5);
        $area = new Rect(0, 0, 10, 5);

        BarChart::new()->data([])->render($area, $buffer);

        static::assertSame(' ', $buffer->get(0, 0)?->grapheme);
    }

    public function testTooSmallAreaRendersNothing(): void
    {
        $buffer = new Buffer(10, 1);
        $area = new Rect(0, 0, 10, 1);

        BarChart::new()->data([['A', 0.5]])->render($area, $buffer);

        static::assertSame(' ', $buffer->get(0, 0)?->grapheme);
    }

    public function testBarStyleApplied(): void
    {
        $buffer = new Buffer(3, 3);
        $area = new Rect(0, 0, 3, 3);

        $fg = Ansi\foreground(Color\bright_cyan());

        BarChart::new()
            ->data([['X', 1.0]])
            ->barWidth(3)
            ->barGap(0)
            ->barStyle($fg)
            ->render($area, $buffer);

        $cell = $buffer->get(0, 0);
        static::assertNotNull($cell);
        static::assertNotEmpty($cell->style);
    }

    public function testLabelStyleApplied(): void
    {
        $buffer = new Buffer(3, 3);
        $area = new Rect(0, 0, 3, 3);

        $fg = Ansi\foreground(Color\bright_white());

        BarChart::new()
            ->data([['X', 0.5]])
            ->barWidth(3)
            ->barGap(0)
            ->labelStyle($fg)
            ->render($area, $buffer);

        $cell = $buffer->get(1, 2);
        static::assertNotNull($cell);
        static::assertNotEmpty($cell->style);
    }

    public function testBarStyleModifierIsApplied(): void
    {
        $buffer = new Buffer(3, 3);
        $area = new Rect(0, 0, 3, 3);

        BarChart::new()
            ->data([['A', 1.0]])
            ->barWidth(3)
            ->barGap(0)
            ->barStyle(Style\bold())
            ->render($area, $buffer);

        $cell = $buffer->get(0, 0);
        static::assertNotNull($cell);
        static::assertNotEmpty($cell->style);
    }

    public function testLabelStyleModifierIsApplied(): void
    {
        $buffer = new Buffer(3, 3);
        $area = new Rect(0, 0, 3, 3);

        BarChart::new()
            ->data([['X', 0.5]])
            ->barWidth(3)
            ->barGap(0)
            ->labelStyle(Style\italic())
            ->render($area, $buffer);

        $cell = $buffer->get(1, 2);
        static::assertNotNull($cell);
        static::assertNotEmpty($cell->style);
    }

    public function testRenderWithHeight2(): void
    {
        $buffer = new Buffer(3, 2);
        $area = new Rect(0, 0, 3, 2);

        BarChart::new()
            ->data([['A', 1.0]])
            ->barWidth(3)
            ->barGap(0)
            ->render($area, $buffer);

        static::assertSame("\u{2588}", $buffer->get(0, 0)?->grapheme);
        static::assertSame('A', $buffer->get(1, 1)?->grapheme);
    }

    public function testBarHeightIsAreaHeightMinusOne(): void
    {
        $buffer = new Buffer(3, 3);
        $area = new Rect(0, 0, 3, 3);

        BarChart::new()
            ->data([['A', 1.0]])
            ->barWidth(3)
            ->barGap(0)
            ->render($area, $buffer);

        static::assertSame("\u{2588}", $buffer->get(0, 0)?->grapheme);
        static::assertSame("\u{2588}", $buffer->get(0, 1)?->grapheme);
        static::assertSame(' ', $buffer->get(0, 2)?->grapheme);
    }

    public function testBarStopsAtAreaRight(): void
    {
        $buffer = new Buffer(3, 3);
        $area = new Rect(0, 0, 3, 3);

        BarChart::new()
            ->data([['A', 1.0], ['B', 1.0]])
            ->barWidth(3)
            ->barGap(0)
            ->render($area, $buffer);

        static::assertSame("\u{2588}", $buffer->get(0, 0)?->grapheme);
        static::assertSame("\u{2588}", $buffer->get(2, 0)?->grapheme);
        static::assertSame('A', $buffer->get(1, 2)?->grapheme);
    }

    public function testLabelCentering(): void
    {
        $buffer = new Buffer(5, 3);
        $area = new Rect(0, 0, 5, 3);

        BarChart::new()
            ->data([['X', 0.5]])
            ->barWidth(5)
            ->barGap(0)
            ->render($area, $buffer);

        static::assertSame('X', $buffer->get(2, 2)?->grapheme);
        static::assertSame(' ', $buffer->get(0, 2)?->grapheme);
        static::assertSame(' ', $buffer->get(4, 2)?->grapheme);
    }

    public function testEmptyDataWithNonEmptyArea(): void
    {
        $buffer = new Buffer(10, 5);
        $area = new Rect(0, 0, 10, 5);

        BarChart::new()->data([])->render($area, $buffer);

        static::assertSame(' ', $buffer->get(0, 0)?->grapheme);
        static::assertSame(' ', $buffer->get(5, 2)?->grapheme);
    }

    public function testBarColumnClipAtRight(): void
    {
        $buffer = new Buffer(4, 3);
        $area = new Rect(0, 0, 4, 3);

        BarChart::new()
            ->data([['A', 1.0]])
            ->barWidth(6)
            ->barGap(0)
            ->render($area, $buffer);

        static::assertSame("\u{2588}", $buffer->get(3, 0)?->grapheme);
        static::assertNull($buffer->get(4, 0));
    }

    public function testLabelClipAtRight(): void
    {
        $buffer = new Buffer(3, 3);
        $area = new Rect(0, 0, 3, 3);

        BarChart::new()
            ->data([['AB', 1.0]])
            ->barWidth(3)
            ->barGap(0)
            ->render($area, $buffer);

        static::assertSame('A', $buffer->get(0, 2)?->grapheme);
        static::assertSame('B', $buffer->get(1, 2)?->grapheme);
        static::assertNull($buffer->get(3, 2));
    }

    public function testBarsClipWhenExceedingAreaWidth(): void
    {
        $buffer = new Buffer(5, 3);
        $area = new Rect(0, 0, 5, 3);

        BarChart::new()
            ->data([['A', 1.0], ['B', 1.0], ['C', 1.0]])
            ->barWidth(3)
            ->barGap(1)
            ->render($area, $buffer);

        static::assertSame("\u{2588}", $buffer->get(0, 0)?->grapheme);
        static::assertSame("\u{2588}", $buffer->get(4, 0)?->grapheme);
    }

    public function testNanValueRendersEmptyBar(): void
    {
        $buffer = new Buffer(3, 3);
        $area = new Rect(0, 0, 3, 3);

        // Casting NAN to int emits a warning since PHP 8.5.
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            BarChart::new()
                ->data([['A', NAN]])
                ->barWidth(3)
                ->barGap(0)
                ->render($area, $buffer);
        } finally {
            restore_error_handler();
        }

        static::assertSame(' ', $buffer->get(0, 0)?->grapheme);
        static::assertSame(' ', $buffer->get(0, 1)?->grapheme);
        static::assertSame('A', $buffer->get(1, 2)?->grapheme);
    }
}
