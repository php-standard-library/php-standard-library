<?php

declare(strict_types=1);

(static function (): void {
    $constants = [
        'Psl\Ansi\Internal\ESCAPE_SEQUENCE_PATTERN' => __DIR__ . '/Ansi/Internal/constants.php',
    ];

    $functions = [
        'Psl\Ansi\Color\ansi256' => __DIR__ . '/Ansi/Color/ansi256.php',
        'Psl\Ansi\Color\black' => __DIR__ . '/Ansi/Color/black.php',
        'Psl\Ansi\Color\blue' => __DIR__ . '/Ansi/Color/blue.php',
        'Psl\Ansi\Color\bright_black' => __DIR__ . '/Ansi/Color/bright_black.php',
        'Psl\Ansi\Color\bright_blue' => __DIR__ . '/Ansi/Color/bright_blue.php',
        'Psl\Ansi\Color\bright_cyan' => __DIR__ . '/Ansi/Color/bright_cyan.php',
        'Psl\Ansi\Color\bright_green' => __DIR__ . '/Ansi/Color/bright_green.php',
        'Psl\Ansi\Color\bright_magenta' => __DIR__ . '/Ansi/Color/bright_magenta.php',
        'Psl\Ansi\Color\bright_red' => __DIR__ . '/Ansi/Color/bright_red.php',
        'Psl\Ansi\Color\bright_white' => __DIR__ . '/Ansi/Color/bright_white.php',
        'Psl\Ansi\Color\bright_yellow' => __DIR__ . '/Ansi/Color/bright_yellow.php',
        'Psl\Ansi\Color\cyan' => __DIR__ . '/Ansi/Color/cyan.php',
        'Psl\Ansi\Color\green' => __DIR__ . '/Ansi/Color/green.php',
        'Psl\Ansi\Color\hex' => __DIR__ . '/Ansi/Color/hex.php',
        'Psl\Ansi\Color\magenta' => __DIR__ . '/Ansi/Color/magenta.php',
        'Psl\Ansi\Color\red' => __DIR__ . '/Ansi/Color/red.php',
        'Psl\Ansi\Color\rgb' => __DIR__ . '/Ansi/Color/rgb.php',
        'Psl\Ansi\Color\white' => __DIR__ . '/Ansi/Color/white.php',
        'Psl\Ansi\Color\yellow' => __DIR__ . '/Ansi/Color/yellow.php',
        'Psl\Ansi\Cursor\back' => __DIR__ . '/Ansi/Cursor/back.php',
        'Psl\Ansi\Cursor\down' => __DIR__ . '/Ansi/Cursor/down.php',
        'Psl\Ansi\Cursor\forward' => __DIR__ . '/Ansi/Cursor/forward.php',
        'Psl\Ansi\Cursor\hide' => __DIR__ . '/Ansi/Cursor/hide.php',
        'Psl\Ansi\Cursor\move_to' => __DIR__ . '/Ansi/Cursor/move_to.php',
        'Psl\Ansi\Cursor\request_position' => __DIR__ . '/Ansi/Cursor/request_position.php',
        'Psl\Ansi\Cursor\restore' => __DIR__ . '/Ansi/Cursor/restore.php',
        'Psl\Ansi\Cursor\save' => __DIR__ . '/Ansi/Cursor/save.php',
        'Psl\Ansi\Cursor\show' => __DIR__ . '/Ansi/Cursor/show.php',
        'Psl\Ansi\Cursor\up' => __DIR__ . '/Ansi/Cursor/up.php',
        'Psl\Ansi\Screen\bracketed_paste_end' => __DIR__ . '/Ansi/Screen/bracketed_paste_end.php',
        'Psl\Ansi\Screen\bracketed_paste_start' => __DIR__ . '/Ansi/Screen/bracketed_paste_start.php',
        'Psl\Ansi\Screen\change_directory' => __DIR__ . '/Ansi/Screen/change_directory.php',
        'Psl\Ansi\Screen\clipboard' => __DIR__ . '/Ansi/Screen/clipboard.php',
        'Psl\Ansi\Screen\disable_kitty_keyboard' => __DIR__ . '/Ansi/Screen/disable_kitty_keyboard.php',
        'Psl\Ansi\Screen\enable_kitty_keyboard' => __DIR__ . '/Ansi/Screen/enable_kitty_keyboard.php',
        'Psl\Ansi\Screen\erase' => __DIR__ . '/Ansi/Screen/erase.php',
        'Psl\Ansi\Screen\erase_line' => __DIR__ . '/Ansi/Screen/erase_line.php',
        'Psl\Ansi\Screen\icon' => __DIR__ . '/Ansi/Screen/icon.php',
        'Psl\Ansi\Screen\icon_and_title' => __DIR__ . '/Ansi/Screen/icon_and_title.php',
        'Psl\Ansi\Screen\notify' => __DIR__ . '/Ansi/Screen/notify.php',
        'Psl\Ansi\Screen\progress' => __DIR__ . '/Ansi/Screen/progress.php',
        'Psl\Ansi\Screen\progress_clear' => __DIR__ . '/Ansi/Screen/progress_clear.php',
        'Psl\Ansi\Screen\reset_mode' => __DIR__ . '/Ansi/Screen/reset_mode.php',
        'Psl\Ansi\Screen\scroll_down' => __DIR__ . '/Ansi/Screen/scroll_down.php',
        'Psl\Ansi\Screen\scroll_up' => __DIR__ . '/Ansi/Screen/scroll_up.php',
        'Psl\Ansi\Screen\set_mode' => __DIR__ . '/Ansi/Screen/set_mode.php',
        'Psl\Ansi\Screen\title' => __DIR__ . '/Ansi/Screen/title.php',
        'Psl\Ansi\Style\blink' => __DIR__ . '/Ansi/Style/blink.php',
        'Psl\Ansi\Style\bold' => __DIR__ . '/Ansi/Style/bold.php',
        'Psl\Ansi\Style\dim' => __DIR__ . '/Ansi/Style/dim.php',
        'Psl\Ansi\Style\double_underline' => __DIR__ . '/Ansi/Style/double_underline.php',
        'Psl\Ansi\Style\hidden' => __DIR__ . '/Ansi/Style/hidden.php',
        'Psl\Ansi\Style\italic' => __DIR__ . '/Ansi/Style/italic.php',
        'Psl\Ansi\Style\overline' => __DIR__ . '/Ansi/Style/overline.php',
        'Psl\Ansi\Style\reversed' => __DIR__ . '/Ansi/Style/reversed.php',
        'Psl\Ansi\Style\strikethrough' => __DIR__ . '/Ansi/Style/strikethrough.php',
        'Psl\Ansi\Style\underline' => __DIR__ . '/Ansi/Style/underline.php',
        'Psl\Ansi\apply' => __DIR__ . '/Ansi/apply.php',
        'Psl\Ansi\background' => __DIR__ . '/Ansi/background.php',
        'Psl\Ansi\bell' => __DIR__ . '/Ansi/bell.php',
        'Psl\Ansi\contains' => __DIR__ . '/Ansi/contains.php',
        'Psl\Ansi\foreground' => __DIR__ . '/Ansi/foreground.php',
        'Psl\Ansi\link' => __DIR__ . '/Ansi/link.php',
        'Psl\Ansi\reset' => __DIR__ . '/Ansi/reset.php',
        'Psl\Ansi\strip' => __DIR__ . '/Ansi/strip.php',
    ];

    foreach ($constants as $constant => $path) {
        if (defined($constant)) {
            continue;
        }

        require_once $path;
    }

    foreach ($functions as $function => $path) {
        if (function_exists($function)) {
            continue;
        }

        require_once $path;
    }
})();
