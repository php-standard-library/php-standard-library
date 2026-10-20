<?php

declare(strict_types=1);

namespace Psl\Example\Terminal;

use Psl\Ansi;
use Psl\Ansi\Color;
use Psl\Ansi\Style;
use Psl\Async;
use Psl\Iter;
use Psl\Math;
use Psl\Str;
use Psl\Terminal;
use Psl\Terminal\Event;
use Psl\Terminal\Layout;
use Psl\Terminal\Widget;

use const PHP_INT_MAX;

require __DIR__ . '/../../vendor/autoload.php';

final class DebugState
{
    /** @var list<Widget\Line> */
    public array $events = [];
    public int $scroll_offset = 0;
    public int $event_count = 0;
}

function format_event(int $index, string $label, Color\Color $color, string $detail): Widget\Line
{
    return Widget\Line::new([
        Widget\Span::styled(Str\format('#%-5d ', $index), Ansi\foreground(Color\ansi256(245))),
        Widget\Span::styled(Str\format('%-6s ', $label), Ansi\foreground($color), Style\bold()),
        Widget\Span::raw($detail),
    ]);
}

Async\main(static function (): int {
    $app = Terminal\Application::create(new DebugState(), title: 'Event Debug', mouseMotion: true);

    $app->on(Event\Key::class, static function (Event\Key $event, DebugState $state) use ($app): void {
        if ($event->is('ctrl+c')) {
            $app->stop();
            return;
        }

        $state->event_count++;

        $parts = [];
        $parts[] = 'name=' . $event->name;
        if ($event->char !== null) {
            $parts[] = 'char=' . $event->char;
        }

        $state->events[] = namespace\format_event(
            $state->event_count,
            'Key',
            Color\bright_cyan(),
            Str\join($parts, ' '),
        );
        $state->scroll_offset = PHP_INT_MAX;
    });

    $app->on(Event\Mouse::class, static function (Event\Mouse $event, DebugState $state): void {
        $state->event_count++;

        $mods = [];
        if ($event->modifiers->shift()) {
            $mods[] = 'Shift';
        }

        if ($event->modifiers->alt()) {
            $mods[] = 'Alt';
        }

        if ($event->modifiers->ctrl()) {
            $mods[] = 'Ctrl';
        }

        $detail = Str\format(
            'kind=%s col=%d row=%d%s',
            $event->kind->name,
            $event->column,
            $event->row,
            $mods !== [] ? ' modifiers=' . Str\join($mods, '+') : '',
        );

        $color = match ($event->kind) {
            Event\MouseKind::ScrollUp,
            Event\MouseKind::ScrollDown,
            Event\MouseKind::ScrollLeft,
            Event\MouseKind::ScrollRight,
                => Color\bright_yellow(),
            Event\MouseKind::Press => Color\bright_green(),
            Event\MouseKind::Release => Color\bright_red(),
            Event\MouseKind::Drag => Color\bright_magenta(),
            Event\MouseKind::Move => Color\bright_blue(),
        };

        $state->events[] = namespace\format_event($state->event_count, 'Mouse', $color, $detail);
        $state->scroll_offset = PHP_INT_MAX;
    });

    $app->on(Event\Paste::class, static function (Event\Paste $event, DebugState $state): void {
        $state->event_count++;
        $text = $event->text;
        $preview = Str\width($text) > 40 ? Str\width_slice($text, 0, 40) . '...' : $text;

        $state->events[] = namespace\format_event(
            $state->event_count,
            'Paste',
            Color\bright_green(),
            Str\format('len=%d text=%s', Str\length($text), $preview),
        );
        $state->scroll_offset = PHP_INT_MAX;
    });

    $app->on(Event\Focus::class, static function (Event\Focus $event, DebugState $state): void {
        $state->event_count++;

        $state->events[] = namespace\format_event(
            $state->event_count,
            'Focus',
            $event->focused ? Color\bright_green() : Color\bright_red(),
            $event->focused ? 'gained' : 'lost',
        );
        $state->scroll_offset = PHP_INT_MAX;
    });

    $app->on(Event\Resize::class, static function (Event\Resize $event, DebugState $state): void {
        $state->event_count++;

        $state->events[] = namespace\format_event(
            $state->event_count,
            'Resize',
            Color\bright_blue(),
            Str\format('width=%d height=%d', $event->width, $event->height),
        );
        $state->scroll_offset = PHP_INT_MAX;
    });

    return $app->run(static function (Terminal\Frame $frame, DebugState $state): void {
        $buffer = $frame->buffer();

        [$main, $statusBar] = Layout\vertical($frame, [
            Layout\fill(),
            Layout\fixed(1),
        ]);

        $block = Widget\Block::new()
            ->title(' Events ')
            ->titleStyle(Ansi\foreground(Color\bright_white()), Style\bold())
            ->border(Widget\Border::rounded(Ansi\foreground(Color\bright_cyan())))
            ->padding(right: 2, left: 1);

        $inner = $block->innerArea($main);
        $totalLines = Iter\count($state->events);
        $visibleLines = Math\maxva(1, $inner->height);

        $state->scroll_offset = Math\clamp($state->scroll_offset, 0, Math\maxva(0, $totalLines - 1));

        $block->render($main, Widget\Paragraph::new($state->events)->scroll($state->scroll_offset), $buffer);

        if ($totalLines > $visibleLines) {
            $scrollbarRect = new Terminal\Rect($main->right() - 2, $inner->y, 1, $inner->height);
            Widget\Scrollbar::new()
                ->contentLength($totalLines)
                ->viewportLength($visibleLines)
                ->position($state->scroll_offset)
                ->thumbStyle(Ansi\foreground(Color\bright_cyan()))
                ->trackStyle(Ansi\foreground(Color\ansi256(238)))
                ->render($scrollbarRect, $buffer);
        }

        Widget\Paragraph::new([Widget\Line::new([
            Widget\Span::styled(
                Str\format(' %d events | Ctrl+C quit', $state->event_count),
                Ansi\foreground(Color\bright_black()),
            ),
        ])])->render($statusBar, $buffer);
    });
});
