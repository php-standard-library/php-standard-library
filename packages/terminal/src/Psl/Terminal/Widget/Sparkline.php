<?php

declare(strict_types=1);

namespace Psl\Terminal\Widget;

use Psl\Ansi\ControlSequenceIntroducer;
use Psl\Math;
use Psl\Terminal\Buffer;
use Psl\Terminal\Cell;
use Psl\Terminal\Rect;

use function array_slice;
use function array_values;
use function count;
use function is_nan;

/**
 * A sparkline widget that renders data points as Unicode block characters.
 *
 * Uses ▁▂▃▄▅▆▇█ to represent values from 0.0 to 1.0.
 * Data is right-aligned: when fewer points than width, new data appears at the right edge.
 *
 * @api
 */
final class Sparkline implements WidgetInterface
{
    /** @var list<string> Block characters from lowest to highest */
    private const array BLOCKS = [
        "\u{2581}", // ▁
        "\u{2582}", // ▂
        "\u{2583}", // ▃
        "\u{2584}", // ▄
        "\u{2585}", // ▅
        "\u{2586}", // ▆
        "\u{2587}", // ▇
        "\u{2588}", // █
    ];

    /** @var list<float> */
    private array $data;

    /** @var list<ControlSequenceIntroducer> */
    private array $style = [];

    /**
     * @param list<float> $data Data points, each 0.0–1.0.
     */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param list<float> $data Data points, each 0.0–1.0.
     */
    public static function new(array $data): self
    {
        return new self($data);
    }

    /**
     * Set the style for the sparkline characters.
     */
    public function style(ControlSequenceIntroducer ...$style): self
    {
        $this->style = array_values($style);

        return $this;
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->isEmpty() || $this->data === []) {
            return;
        }

        $width = $area->width;
        $dataCount = count($this->data);

        $visible = $this->data;
        if ($dataCount > $width) {
            $visible = array_slice($this->data, $dataCount - $width);
        }

        $startX = $area->x + $width - count($visible);
        $y = $area->y;

        foreach ($visible as $i => $value) {
            $x = $startX + $i;
            if ($x >= $area->right()) {
                break;
            }

            // Clamp before casting: NAN, INF and huge floats cannot be cast to int without a warning.
            $value = is_nan($value) ? 0.0 : Math\clamp($value, 0.0, 1.0);
            /** @var int<0, 7> $index */
            $index = (int) Math\round($value * 7.0);
            $char = self::BLOCKS[$index];

            $buffer->set($x, $y, new Cell($char, $this->style));
        }
    }
}
