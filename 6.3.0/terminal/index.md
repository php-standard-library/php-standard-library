# Terminal

The `Terminal` component provides a full-featured TUI (Terminal User Interface) framework built on top of the `Ansi` and `Async` components.

It handles the event loop, raw mode, input parsing, diff-based rendering, and provides a library of composable widgets for building interactive terminal applications.

## Quick Start

```php
use Psl\Terminal;
use Psl\Terminal\Event;
use Psl\Terminal\Widget;

final class MyState {}

$app = Terminal\Application::create(new MyState(), title: 'My App');

$app->on(Event\Key::class, static function (Event\Key $event, MyState $_state) use ($app): void {
    if ($event->is('ctrl+c')) {
        $app->stop();
    }
});

return $app->run(static function (Terminal\Frame $frame, MyState $_state): void {
    $buffer = $frame->buffer();

    Widget\Paragraph::new([
        Widget\Line::new([Widget\Span::raw('Hello, World!')]),
    ])->render($frame->rect(), $buffer);
});
```

## Architecture

The framework follows an immediate-mode rendering model:

1. **Application** manages the event loop, terminal setup/teardown, and render scheduling
2. **Events** are parsed from raw stdin bytes and dispatched to registered handlers
3. **Frame** is provided to the render callback each tick with a fresh **Buffer**
4. **Widgets** render themselves into a rectangular **Rect** area of the **Buffer**
5. **Buffer** diffs against the previous frame and writes only changed cells

## Application

### Local Terminal

For local terminal applications using STDIN/STDOUT:

```php
use Psl\DateTime;
use Psl\Terminal;

final class AppState {}

$app = Terminal\Application::create(
    state: new AppState(),
    title: 'My App',
    tickInterval: DateTime\Duration::milliseconds(16), // ~60 ticks/s (default: 16ms)
    scrollSmoothing: true, // filter trackpad scroll micro-reversals (default: true)
    mouseMotion: false, // track mouse movement, not just clicks (default: false)
);
```

This automatically handles raw mode, terminal size detection, SIGWINCH/SIGINT signal handling, and in-band resize notifications.

### Custom I/O

For remote scenarios (e.g. SSH servers) or testing, where you provide your own I/O handles:

```php
use Psl\IO;
use Psl\Terminal;

final class RemoteState {}

$sshInputStream = IO\input_handle();
$sshOutputStream = IO\output_handle();

$app = Terminal\Application::custom(
    state: new RemoteState(),
    input: $sshInputStream,
    output: $sshOutputStream,
    width: 80,
    height: 24,
    title: 'Remote App',
);
```

In this mode, raw mode is not managed by the application -- the caller is responsible for it. Signal handlers are not registered, and Ctrl+C is handled via the input stream parser. Use `$app->dispatch(new Event\Resize($cols, $rows))` to inject resize events.

## Event Handling

Register handlers for specific event types. Multiple handlers per event type are supported:

```php
use Psl\Terminal;
use Psl\Terminal\Event;
use Psl\Terminal\Widget;

final class EventState {}

$app = Terminal\Application::create(new EventState(), title: 'Events Demo');

$app->on(Event\Key::class, static function (Event\Key $event, EventState $_state) use ($app): void {
    if ($event->is('ctrl+c')) {
        $app->stop();
    }

    if ($event->is('enter')) {
        /**
         * handle enter
         */
    }

    if ($event->is('up')) {
        /**
         * handle up arrow
         */
    }
});

$app->on(Event\Mouse::class, static function (Event\Mouse $_event, EventState $_state): void {
    // $_event->kind (Press, Release, Drag, ScrollUp, ScrollDown, Move)
    // $_event->column, $_event->row
});

$app->on(Event\Paste::class, static function (Event\Paste $_event, EventState $_state): void {
    // $_event->text -- the pasted string
});

$app->on(Event\Resize::class, static function (Event\Resize $_event, EventState $_state): void {
    // $_event->width, $_event->height
});

$app->run(static function (Terminal\Frame $frame, EventState $_state): void {
    Widget\Paragraph::new([
        Widget\Line::new([Widget\Span::raw('Press Ctrl+C to exit')]),
    ])->render($frame->rect(), $frame->buffer());
});
```

Periodic callbacks can be registered for tasks like polling or animation:

```php
use Psl\DateTime\Duration;
use Psl\Terminal;
use Psl\Terminal\Event;
use Psl\Terminal\Widget;

final class TimerState
{
    public int $ticks = 0;
}

$app = Terminal\Application::create(new TimerState(), title: 'Interval Demo');

$app->on(Event\Key::class, static function (Event\Key $event, TimerState $_state) use ($app): void {
    if ($event->is('ctrl+c')) {
        $app->stop();
    }
});

$app->interval(Duration::milliseconds(100), static function (TimerState $state): void {
    // runs every 100ms
    $state->ticks++;
});

$app->run(static function (Terminal\Frame $frame, TimerState $state): void {
    Widget\Paragraph::new([
        Widget\Line::new([Widget\Span::raw('Ticks: ' . $state->ticks)]),
    ])->render($frame->rect(), $frame->buffer());
});
```

## Layout

The layout system splits rectangular areas into sub-regions using constraints:

```php
use Psl\Terminal;
use Psl\Terminal\Event;
use Psl\Terminal\Layout;
use Psl\Terminal\Widget;

final class LayoutState {}

$app = Terminal\Application::create(new LayoutState(), title: 'Layout Demo');

$app->on(Event\Key::class, static function (Event\Key $event, LayoutState $_state) use ($app): void {
    if ($event->is('ctrl+c')) {
        $app->stop();
    }
});

$app->run(static function (Terminal\Frame $frame, LayoutState $_state): void {
    $buffer = $frame->buffer();

    // Vertical split: header (3 rows) + content (fill) + footer (1 row)
    [$header, $content, $footer] = Layout\vertical($frame, [
        Layout\fixed(3),
        Layout\fill(),
        Layout\fixed(1),
    ]);

    // Horizontal split: sidebar (20 cols) + main (fill)
    [$sidebar, $main] = Layout\horizontal($content, [
        Layout\fixed(20),
        Layout\fill(),
    ]);

    // Constraints can be wrapped with min/max
    // Layout\min(10, Layout\fill());     // fill, but at least 10
    // Layout\max(50, Layout\fill());     // fill, but at most 50

    Widget\Paragraph::new([
        Widget\Line::new([Widget\Span::raw('Header Area')]),
    ])->render($header, $buffer);

    Widget\Paragraph::new([
        Widget\Line::new([Widget\Span::raw('Sidebar')]),
    ])->render($sidebar, $buffer);

    Widget\Paragraph::new([
        Widget\Line::new([Widget\Span::raw('Main Content')]),
    ])->render($main, $buffer);

    Widget\Paragraph::new([
        Widget\Line::new([Widget\Span::raw('Footer')]),
    ])->render($footer, $buffer);
});
```

## Widgets

All widgets implement `WidgetInterface` with a single method: `render(Rect $area, Buffer $buffer): void`. They use a builder pattern for configuration.

### Paragraph

Multi-line text with wrapping, scrolling, and alignment:

```php
use Psl\Ansi;
use Psl\Ansi\Color;
use Psl\Ansi\Style;
use Psl\Terminal;
use Psl\Terminal\Event;
use Psl\Terminal\Widget;

final class ParagraphState {}

$app = Terminal\Application::create(new ParagraphState(), title: 'Paragraph Demo');

$app->on(Event\Key::class, static function (Event\Key $event, ParagraphState $_state) use ($app): void {
    if ($event->is('ctrl+c')) {
        $app->stop();
    }
});

$app->run(static function (Terminal\Frame $frame, ParagraphState $_state): void {
    Widget\Paragraph::new([
        Widget\Line::new([
            Widget\Span::styled('Error: ', Ansi\foreground(Color\red()), Style\bold()),
            Widget\Span::raw('something went wrong'),
        ]),
        Widget\Line::new([Widget\Span::raw('Check the logs for details.')]),
    ])
        ->wrap(Widget\Wrap::Word)
        ->alignment(Widget\Alignment::Left)
        ->scroll(0)
        ->render($frame->rect(), $frame->buffer());
});
```

### Block

A container that draws a border and optional title around an inner widget:

```php
use Psl\Ansi;
use Psl\Ansi\Color;
use Psl\Ansi\Style;
use Psl\Terminal;
use Psl\Terminal\Event;
use Psl\Terminal\Widget;

final class BlockState {}

$app = Terminal\Application::create(new BlockState(), title: 'Block Demo');

$app->on(Event\Key::class, static function (Event\Key $event, BlockState $_state) use ($app): void {
    if ($event->is('ctrl+c')) {
        $app->stop();
    }
});

$app->run(static function (Terminal\Frame $frame, BlockState $_state): void {
    $area = $frame->rect();
    $buffer = $frame->buffer();

    $block = Widget\Block::new()
        ->title(' Status ')
        ->titleStyle(Ansi\foreground(Color\bright_white()), Style\bold())
        ->border(Widget\Border::rounded(Ansi\foreground(Color\bright_cyan())))
        ->padding(right: 1, left: 1)
        ->margin(top: 1)
        ->background(Color\ansi256(235));

    $paragraph = Widget\Paragraph::new([
        Widget\Line::new([Widget\Span::raw('All systems operational.')]),
    ]);

    $block->render($area, $paragraph, $buffer);
});
```

Border styles: `Border::rounded()`, `Border::plain()`, `Border::double()`, `Border::thick()`.

### Table

Columnar data with headers, scrolling, and row highlighting:

```php
use Psl\Ansi;
use Psl\Ansi\Color;
use Psl\Terminal;
use Psl\Terminal\Event;
use Psl\Terminal\Widget;

final class TableState {}

$app = Terminal\Application::create(new TableState(), title: 'Table Demo');

$app->on(Event\Key::class, static function (Event\Key $event, TableState $_state) use ($app): void {
    if ($event->is('ctrl+c')) {
        $app->stop();
    }
});

$app->run(static function (Terminal\Frame $frame, TableState $_state): void {
    Widget\Table::new()
        ->headers(['Name', 'Status', 'CPU'])
        ->widths([15, 10, 8])
        ->rows([
            [
                Widget\Span::raw('nginx'),
                Widget\Span::styled('running', Ansi\foreground(Color\green())),
                Widget\Span::raw('2.1%'),
            ],
            [
                Widget\Span::raw('postgres'),
                Widget\Span::styled('running', Ansi\foreground(Color\green())),
                Widget\Span::raw('5.3%'),
            ],
        ])
        ->highlight(0)
        ->highlightStyle(Ansi\foreground(Color\bright_white()), Ansi\background(Color\blue()))
        ->render($frame->rect(), $frame->buffer());
});
```

### Menu

A selectable list of items with scrolling and highlight:

```php
use Psl\Ansi;
use Psl\Ansi\Color;
use Psl\Terminal;
use Psl\Terminal\Event;
use Psl\Terminal\Widget;

final class MenuState
{
    public int $selectedIndex = 0;
    public int $scrollOffset = 0;
}

$app = Terminal\Application::create(new MenuState(), title: 'Menu Demo');

$app->on(Event\Key::class, static function (Event\Key $event, MenuState $_state) use ($app): void {
    if ($event->is('ctrl+c')) {
        $app->stop();
    }
});

$app->run(static function (Terminal\Frame $frame, MenuState $state): void {
    Widget\Menu::new([
        Widget\MenuItem::raw('Open File'),
        Widget\MenuItem::raw('Save'),
        Widget\MenuItem::styled([
            Widget\Span::styled('Quit', Ansi\foreground(Color\red())),
        ]),
    ])
        ->highlight($state->selectedIndex)
        ->scroll($state->scrollOffset)
        ->highlightStyle(Ansi\foreground(Color\bright_white()), Ansi\background(Color\blue()))
        ->render($frame->rect(), $frame->buffer());
});
```

### Other Widgets

- **Tabs** -- horizontal tab bar with active/inactive styling
- **Gauge** -- horizontal progress bar with label and percentage
- **Sparkline** -- single-row data visualization using Unicode block characters
- **BarChart** -- vertical bar chart with labels
- **Scrollbar** -- vertical scrollbar for scrollable content
- **TextInput** -- single-line text input with cursor and placeholder

### Text Primitives

```php
use Psl\Ansi;
use Psl\Ansi\Color;
use Psl\Ansi\Style;
use Psl\Terminal;
use Psl\Terminal\Event;
use Psl\Terminal\Widget;

final class TextState {}

$app = Terminal\Application::create(new TextState(), title: 'Text Primitives Demo');

$app->on(Event\Key::class, static function (Event\Key $event, TextState $_state) use ($app): void {
    if ($event->is('ctrl+c')) {
        $app->stop();
    }
});

$app->run(static function (Terminal\Frame $frame, TextState $_state): void {
    // Span -- a styled text fragment (the smallest text unit)
    $plain = Widget\Span::raw('plain text');
    $boldRed = Widget\Span::styled('bold red', Ansi\foreground(Color\red()), Style\bold());

    // Line -- a horizontal sequence of spans
    $line = Widget\Line::new([
        Widget\Span::styled('[INFO] ', Ansi\foreground(Color\blue())),
        Widget\Span::raw('Server started on port 8080'),
    ]);

    Widget\Paragraph::new([
        Widget\Line::new([$plain]),
        Widget\Line::new([$boldRed]),
        $line,
    ])->render($frame->rect(), $frame->buffer());
});
```

## Buffer

The `Buffer` is a 2D grid of `Cell` objects. Widgets write to it, and `flush()` performs diff-based rendering:

```php
use Psl\Ansi;
use Psl\Ansi\Color;
use Psl\Ansi\Style;
use Psl\Terminal;
use Psl\Terminal\Event;

final class BufferState {}

$app = Terminal\Application::create(new BufferState(), title: 'Buffer Demo');

$app->on(Event\Key::class, static function (Event\Key $event, BufferState $_state) use ($app): void {
    if ($event->is('ctrl+c')) {
        $app->stop();
    }
});

$app->run(static function (Terminal\Frame $frame, BufferState $_state): void {
    $buffer = $frame->buffer();

    // Direct cell manipulation
    $buffer->set(0, 0, new Terminal\Cell('X', [Ansi\foreground(Color\red())]));
    $buffer->setString(0, 1, 'Hello', [Ansi\foreground(Color\green()), Style\bold()]);

    // Read cells
    $cell = $buffer->get(0, 0);
    $grapheme = $cell?->grapheme;
});
```

See [src/Psl/Terminal/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/terminal/src/Psl/Terminal/) for the full API.
