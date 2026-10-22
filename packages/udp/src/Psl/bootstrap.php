<?php

declare(strict_types=1);

(static function (): void {
    $functions = [
        'Psl\UDP\Internal\parse_address' => __DIR__ . '/UDP/Internal/parse_address.php',
        'Psl\UDP\Internal\receive_from_stream' => __DIR__ . '/UDP/Internal/receive_from_stream.php',
        'Psl\UDP\Internal\sockets_available' => __DIR__ . '/UDP/Internal/sockets_available.php',
        'Psl\UDP\Internal\validate_payload_size' => __DIR__ . '/UDP/Internal/validate_payload_size.php',
        'Psl\UDP\connect' => __DIR__ . '/UDP/connect.php',
    ];

    foreach ($functions as $function => $path) {
        if (function_exists($function)) {
            continue;
        }

        require_once $path;
    }
})();
