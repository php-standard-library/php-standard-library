<?php

declare(strict_types=1);

(static function (): void {
    $functions = [
        'Psl\Network\Internal\get_peer_name' => __DIR__ . '/Network/Internal/get_peer_name.php',
        'Psl\Network\Internal\get_sock_name' => __DIR__ . '/Network/Internal/get_sock_name.php',
        'Psl\Network\Internal\server_listen' => __DIR__ . '/Network/Internal/server_listen.php',
        'Psl\Network\Internal\socket_connect' => __DIR__ . '/Network/Internal/socket_connect.php',
        'Psl\Network\Internal\sockets_available' => __DIR__ . '/Network/Internal/sockets_available.php',
        'Psl\Network\Internal\suppress' => __DIR__ . '/Network/Internal/suppress.php',
        'Psl\Network\socket_pair' => __DIR__ . '/Network/socket_pair.php',
    ];

    foreach ($functions as $function => $path) {
        if (function_exists($function)) {
            continue;
        }

        require_once $path;
    }
})();
