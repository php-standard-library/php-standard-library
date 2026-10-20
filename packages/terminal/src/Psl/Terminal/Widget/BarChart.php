<?php

declare(strict_types=1);

namespace Psl\Terminal\Widget;

use Psl\Ansi\ControlSequenceIntroducer;
use Psl\Math;
use Psl\Str;
use Psl\Terminal\Buffer;
use Psl\Terminal\Cell;
use Psl\Terminal\Rect;

use function array_values;
use function is_nan;

/**
 * A vertical bar chart widget using Unicode block characters.
 *
 * Each bar is drawn vertically with a label at the bottom.
 * Values are expected in the range 0.0–1.0.
 *
 * @api
 */
final class BarChart implements WidgetInterface
{
    /** @var list<array{string, float}> label, value pairs */
    private array $data = [];

    /** @var positive-int */
    private int $barWidth = 3;

    /** @var non-negative-int */
    private int $barGap = 1;

    /** @var list<ControlSequenceIntroducer> */
    private array $barStyle = [];

    /** @var list<ControlSequenceIntroducer> */
    private array $labelStyle = [];

    private function __construct() {}

    public static function new(): self
    {
        return new self();
    }

    /**
     * Set the chart data as label/value pairs.
     *
     * @param list<array{string, float}> $data Each entry is [label, value] where value is 0.0–1.0.
     */
    public function data(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Set the width of each bar in characters.
     *
     * @param positive-int $width
     */
    public function barWidth(int $width): self
    {
        $this->barWidth = $width;
        return $this;
    }

    /**
     * Set the gap between bars in characters.
     *
     * @param non-negative-int $gap
     */
    public function barGap(int $gap): self
    {
        $this->barGap = $gap;
        return $this;
    }

    /**
     * Set the style for the bar fill characters.
     */
    public function barStyle(ControlSequenceIntroducer ...$style): self
    {
        $this->barStyle = array_values($style);

        return $this;
    }

    /**
     * Set the style for the labels below the bars.
     */
    public function labelStyle(ControlSequenceIntroducer ...$style): self
    {
        $this->labelStyle = array_values($style);

        return $this;
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->isEmpty() || $this->data === [] || $area->height < 2) {
            return;
        }

        $barHeight = $area->height - 1;
        $labelY = $area->bottom() - 1;

        foreach ($this->data as $i => $entry) {
            [$label, $value] = $entry;
            $value = is_nan($value) ? 0.0 : Math\clamp($value, 0.0, 1.0);

            $barX = $area->x + ($i * ($this->barWidth + $this->barGap));
            if ($barX >= $area->right()) {
                break;
            }

            $filledHeight = (int) Math\round($value * (float) $barHeight);

            for ($row = 0; $row < $barHeight; $row++) {
                $y = $area->y + ($barHeight - 1 - $row);
                $isFilled = $row < $filledHeight;

                for ($col = 0; $col < $this->barWidth; $col++) {
                    $x = $barX + $col;
                    if ($x >= $area->right()) {
                        break;
                    }

                    if ($isFilled) {
                        $buffer->set($x, $y, new Cell("\u{2588}", $this->barStyle));
                    }
                }
            }

            $truncatedLabel = Str\width_slice($label, 0, $this->barWidth);
            $labelLen = Str\width($truncatedLabel);
            $labelStartX = $barX + (int) (($this->barWidth - $labelLen) / 2);

            if ($labelStartX < $area->right()) {
                /** @var non-negative-int $maxLen */
                $maxLen = $area->right() - $labelStartX;
                $buffer->setString(
                    $labelStartX,
                    $labelY,
                    Str\width_slice($truncatedLabel, 0, $maxLen),
                    $this->labelStyle,
                );
            }
        }
    }
}
