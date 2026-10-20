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
use Psl\Terminal\Widget\Gauge;

use function restore_error_handler;
use function set_error_handler;

use const INF;
use const NAN;

final class GaugeTest extends TestCase
{
    public function testBasicGaugeWithLabel(): void
    {
        $buffer = new Buffer(30, 1);
        $area = new Rect(0, 0, 30, 1);

        Gauge::new()->ratio(0.5)->label('CPU')->render($area, $buffer);

        static::assertSame('C', $buffer->get(0, 0)?->grapheme);
        static::assertSame('P', $buffer->get(1, 0)?->grapheme);
        static::assertSame('U', $buffer->get(2, 0)?->grapheme);
        static::assertSame(' ', $buffer->get(3, 0)?->grapheme);
    }

    public function testZeroRatio(): void
    {
        $buffer = new Buffer(20, 1);
        $area = new Rect(0, 0, 20, 1);

        Gauge::new()->ratio(0.0)->render($area, $buffer);

        $allEmpty = true;
        for ($x = 0; $x < 17; $x++) {
            if ($buffer->get($x, 0)?->grapheme !== "\u{2588}") {
                continue;
            }

            $allEmpty = false;
            break;
        }

        static::assertTrue($allEmpty);
    }

    public function testFullRatio(): void
    {
        $buffer = new Buffer(20, 1);
        $area = new Rect(0, 0, 20, 1);

        Gauge::new()->ratio(1.0)->render($area, $buffer);

        $filledCount = 0;
        for ($x = 0; $x < 15; $x++) {
            if ($buffer->get($x, 0)?->grapheme !== "\u{2588}") {
                continue;
            }

            $filledCount++;
        }

        static::assertSame(15, $filledCount);
    }

    public function testRatioIsClamped(): void
    {
        $buffer = new Buffer(20, 1);
        $area = new Rect(0, 0, 20, 1);

        Gauge::new()->ratio(1.5)->render($area, $buffer);
        Gauge::new()->ratio(-0.5)->render($area, $buffer);

        static::assertTrue(true);
    }

    public function testNoLabel(): void
    {
        $buffer = new Buffer(20, 1);
        $area = new Rect(0, 0, 20, 1);

        Gauge::new()->ratio(0.5)->render($area, $buffer);

        $grapheme = $buffer->get(0, 0)?->grapheme;
        static::assertTrue($grapheme === "\u{2588}" || $grapheme === "\u{2591}");
    }

    public function testFilledStyleApplied(): void
    {
        $buffer = new Buffer(20, 1);
        $area = new Rect(0, 0, 20, 1);

        $fg = Ansi\foreground(Color\bright_green());

        Gauge::new()->ratio(0.5)->filledStyle($fg)->render($area, $buffer);

        $cell = $buffer->get(0, 0);
        static::assertNotNull($cell);
        static::assertSame("\u{2588}", $cell->grapheme);
        static::assertNotEmpty($cell->style);
    }

    public function testEmptyStyleApplied(): void
    {
        $buffer = new Buffer(20, 1);
        $area = new Rect(0, 0, 20, 1);

        $fg = Ansi\foreground(Color\bright_black());

        Gauge::new()->ratio(0.0)->emptyStyle($fg)->render($area, $buffer);

        $cell = $buffer->get(0, 0);
        static::assertNotNull($cell);
        static::assertSame("\u{2591}", $cell->grapheme);
        static::assertNotEmpty($cell->style);
    }

    public function testEmptyAreaRendersNothing(): void
    {
        $buffer = new Buffer(20, 1);
        $area = new Rect(0, 0, 0, 0);

        Gauge::new()->ratio(0.5)->label('CPU')->render($area, $buffer);

        static::assertSame(' ', $buffer->get(0, 0)?->grapheme);
    }

    public function testPercentageText(): void
    {
        $buffer = new Buffer(20, 1);
        $area = new Rect(0, 0, 20, 1);

        Gauge::new()->ratio(0.5)->render($area, $buffer);

        $pctStr = '';
        for ($x = 0; $x < 20; $x++) {
            $g = $buffer->get($x, 0)?->grapheme;
            if ($g === '%' || $g === ' ' || $g !== null && $g >= '0' && $g <= '9') {
                $pctStr .= $g;
            }
        }

        static::assertStringContainsString(' 50%', $pctStr);
    }

    public function testGaugeFilledAndEmptyCounts(): void
    {
        $buffer = new Buffer(15, 1);
        $area = new Rect(0, 0, 15, 1);

        Gauge::new()->ratio(0.5)->render($area, $buffer);

        $filledCount = 0;
        $emptyCount = 0;
        for ($x = 0; $x < 15; $x++) {
            $g = $buffer->get($x, 0)?->grapheme;
            if ($g === "\u{2588}") {
                $filledCount++;
            } elseif ($g === "\u{2591}") {
                $emptyCount++;
            }
        }

        $totalBar = $filledCount + $emptyCount;
        static::assertGreaterThan(0, $filledCount);
        static::assertGreaterThan(0, $emptyCount);
        static::assertSame($filledCount, $totalBar - $emptyCount);
    }

    public function testGaugeLabelPosition(): void
    {
        $buffer = new Buffer(30, 1);
        $area = new Rect(2, 0, 28, 1);

        Gauge::new()->ratio(0.5)->label('CPU')->render($area, $buffer);

        static::assertSame('C', $buffer->get(2, 0)?->grapheme);
        static::assertSame('P', $buffer->get(3, 0)?->grapheme);
        static::assertSame('U', $buffer->get(4, 0)?->grapheme);
        static::assertSame(' ', $buffer->get(5, 0)?->grapheme);

        $firstBarOrEmpty = $buffer->get(6, 0)?->grapheme;
        static::assertTrue($firstBarOrEmpty === "\u{2588}" || $firstBarOrEmpty === "\u{2591}");
    }

    public function testGaugeBarClipsToRight(): void
    {
        $buffer = new Buffer(10, 1);
        $area = new Rect(0, 0, 5, 1);

        Gauge::new()->ratio(1.0)->render($area, $buffer);

        $cell = $buffer->get(5, 0);
        static::assertNotNull($cell);
        static::assertSame(' ', $cell->grapheme);
        static::assertSame([], $cell->style);
    }

    public function testGaugePctRendered(): void
    {
        $buffer = new Buffer(20, 1);
        $area = new Rect(0, 0, 20, 1);

        Gauge::new()->ratio(1.0)->render($area, $buffer);

        $found = false;
        for ($x = 0; $x < 20; $x++) {
            if ($buffer->get($x, 0)?->grapheme !== '%') {
                continue;
            }

            $found = true;
            break;
        }

        static::assertTrue($found);
    }

    public function testGaugeFilledStyleModifier(): void
    {
        $buffer = new Buffer(20, 1);
        $area = new Rect(0, 0, 20, 1);

        Gauge::new()
            ->ratio(0.5)
            ->filledStyle(Style\bold())
            ->render($area, $buffer);

        $cell = $buffer->get(0, 0);
        static::assertNotNull($cell);
        static::assertSame("\u{2588}", $cell->grapheme);
        static::assertNotEmpty($cell->style);
    }

    public function testGaugeEmptyStyleModifier(): void
    {
        $buffer = new Buffer(20, 1);
        $area = new Rect(0, 0, 20, 1);

        Gauge::new()
            ->ratio(0.0)
            ->emptyStyle(Style\italic())
            ->render($area, $buffer);

        $cell = $buffer->get(0, 0);
        static::assertNotNull($cell);
        static::assertSame("\u{2591}", $cell->grapheme);
        static::assertNotEmpty($cell->style);
    }

    public function testGaugeBarWidthClamped(): void
    {
        $buffer = new Buffer(5, 1);
        $area = new Rect(0, 0, 5, 1);

        Gauge::new()->ratio(0.0)->label('VERY LONG LABEL')->render($area, $buffer);

        static::assertSame('V', $buffer->get(0, 0)?->grapheme);
    }

    public function testNonFiniteRatioRendersWithoutWarning(): void
    {
        // Casting NAN to int emits a warning since PHP 8.5.
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            foreach ([NAN, INF, -INF] as $ratio) {
                $buffer = new Buffer(20, 1);
                Gauge::new()->ratio($ratio)->render(new Rect(0, 0, 20, 1), $buffer);

                $text = '';
                for ($x = 0; $x < 20; $x++) {
                    $text .= $buffer->get($x, 0)->grapheme ?? '';
                }

                static::assertStringEndsWith($ratio === INF ? ' 100%' : ' 0%', $text);
            }
        } finally {
            restore_error_handler();
        }
    }
}
