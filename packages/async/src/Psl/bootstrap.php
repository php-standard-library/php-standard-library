<?php

declare(strict_types=1);

(static function (): void {
    $functions = [
        'Psl\Async\Internal\next_id' => __DIR__ . '/Async/Internal/next_id.php',
        'Psl\Async\Internal\notify_cancellation' => __DIR__ . '/Async/Internal/notify_cancellation.php',
        'Psl\Async\all' => __DIR__ . '/Async/all.php',
        'Psl\Async\any' => __DIR__ . '/Async/any.php',
        'Psl\Async\await' => __DIR__ . '/Async/await.php',
        'Psl\Async\concurrently' => __DIR__ . '/Async/concurrently.php',
        'Psl\Async\first' => __DIR__ . '/Async/first.php',
        'Psl\Async\later' => __DIR__ . '/Async/later.php',
        'Psl\Async\main' => __DIR__ . '/Async/main.php',
        'Psl\Async\run' => __DIR__ . '/Async/run.php',
        'Psl\Async\series' => __DIR__ . '/Async/series.php',
        'Psl\Async\sleep' => __DIR__ . '/Async/sleep.php',
    ];

    foreach ($functions as $function => $path) {
        if (function_exists($function)) {
            continue;
        }

        require_once $path;
    }
})();
