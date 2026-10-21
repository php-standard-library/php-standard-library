<?php

declare(strict_types=1);

(static function (): void {
    $functions = [
        'Psl\TCP\connect' => __DIR__ . '/TCP/connect.php',
        'Psl\TCP\Internal\sockets_available' => __DIR__ . '/TCP/Internal/sockets_available.php',
        'Psl\TCP\listen' => __DIR__ . '/TCP/listen.php',
    ];

    foreach ($functions as $function => $path) {
        if (function_exists($function)) {
            continue;
        }

        require_once $path;
    }
})();
