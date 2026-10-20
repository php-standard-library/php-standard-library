<?php

declare(strict_types=1);

(static function (): void {
    $functions = [
        'Psl\\HTTP\\Client\\Internal\\consume_buffer' => __DIR__ . '/HTTP/Client/Internal/consume_buffer.php',
        'Psl\\HTTP\\Client\\Internal\\resolve_url' => __DIR__ . '/HTTP/Client/Internal/resolve_url.php',
        'Psl\\HTTP\\Client\\Internal\\resolve_protocol_versions' =>
            __DIR__ . '/HTTP/Client/Internal/resolve_protocol_versions.php',
        'Psl\\HTTP\\Client\\Internal\\should_tunnel' => __DIR__ . '/HTTP/Client/Internal/should_tunnel.php',
    ];

    foreach ($functions as $function => $path) {
        if (function_exists($function)) {
            continue;
        }

        require_once $path;
    }
})();
