<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

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
    // $_event->kind (Press, Release, Drag, ScrollUp, ScrollDown, ScrollLeft, ScrollRight, Move)
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
