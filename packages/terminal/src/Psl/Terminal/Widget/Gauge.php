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
 * A horizontal progress bar widget.
 *
 * Renders a single-row gauge: `label [████░░░░] XX%`
 *
 * @api
 */
final class Gauge implements WidgetInterface
{
    private float $ratio = 0.0;
    private string $label = '';

    /** @var list<ControlSequenceIntroducer> */
    private array $filledStyle = [];

    /** @var list<ControlSequenceIntroducer> */
    private array $emptyStyle = [];

    /** @var list<ControlSequenceIntroducer> */
    private array $labelStyle = [];

    private function __construct() {}

    public static function new(): self
    {
        return new self();
    }

    /**
     * Set the fill ratio (clamped to 0.0–1.0, NAN is treated as 0.0).
     */
    public function ratio(float $ratio): self
    {
        $this->ratio = is_nan($ratio) ? 0.0 : Math\clamp($ratio, 0.0, 1.0);
        return $this;
    }

    /**
     * Set the label displayed to the left of the bar.
     */
    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    /**
     * Set the style for the filled portion of the bar.
     */
    public function filledStyle(ControlSequenceIntroducer ...$style): self
    {
        $this->filledStyle = array_values($style);

        return $this;
    }

    /**
     * Set the style for the empty portion of the bar.
     */
    public function emptyStyle(ControlSequenceIntroducer ...$style): self
    {
        $this->emptyStyle = array_values($style);

        return $this;
    }

    /**
     * Set the style for the label text.
     */
    public function labelStyle(ControlSequenceIntroducer ...$style): self
    {
        $this->labelStyle = array_values($style);

        return $this;
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->isEmpty()) {
            return;
        }

        $y = $area->y;

        $labelText = $this->label !== '' ? $this->label . ' ' : '';
        $labelLen = Str\width($labelText);

        $pct = (int) Math\round($this->ratio * 100.0);
        $pctText = ' ' . $pct . '%';
        $pctLen = Str\width($pctText);

        $barWidth = Math\maxva(0, $area->width - $labelLen - $pctLen);

        $x = $area->x;
        if ($labelText !== '') {
            $buffer->setString($x, $y, $labelText, $this->labelStyle);
            $x += $labelLen;
        }

        $filledWidth = (int) Math\round($this->ratio * (float) $barWidth);
        $emptyWidth = $barWidth - $filledWidth;

        for ($i = 0; $i < $filledWidth; $i++) {
            if ($x >= $area->right()) {
                break;
            }

            $buffer->set($x, $y, new Cell("\u{2588}", $this->filledStyle));
            $x++;
        }

        for ($i = 0; $i < $emptyWidth; $i++) {
            if ($x >= $area->right()) {
                break;
            }

            $buffer->set($x, $y, new Cell("\u{2591}", $this->emptyStyle));
            $x++;
        }

        if ($x < $area->right()) {
            $buffer->setString($x, $y, $pctText, $this->labelStyle);
        }
    }
}
