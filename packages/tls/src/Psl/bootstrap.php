<?php

declare(strict_types=1);

(static function (): void {
    $functions = [
        'Psl\TLS\Internal\client_ssl_context' => __DIR__ . '/TLS/Internal/client_ssl_context.php',
        'Psl\TLS\Internal\crypto_method' => __DIR__ . '/TLS/Internal/crypto_method.php',
        'Psl\TLS\Internal\enable_crypto' => __DIR__ . '/TLS/Internal/enable_crypto.php',
        'Psl\TLS\Internal\extract_connection_state' => __DIR__ . '/TLS/Internal/extract_connection_state.php',
        'Psl\TLS\Internal\handshake_failure' => __DIR__ . '/TLS/Internal/handshake_failure.php',
        'Psl\TLS\Internal\is_verification_failure' => __DIR__ . '/TLS/Internal/is_verification_failure.php',
        'Psl\TLS\Internal\parse_peer_certificate' => __DIR__ . '/TLS/Internal/parse_peer_certificate.php',
        'Psl\TLS\Internal\server_ssl_context' => __DIR__ . '/TLS/Internal/server_ssl_context.php',
        'Psl\TLS\Internal\upgrade_server' => __DIR__ . '/TLS/Internal/upgrade_server.php',
        'Psl\TLS\connect' => __DIR__ . '/TLS/connect.php',
    ];

    foreach ($functions as $function => $path) {
        if (function_exists($function)) {
            continue;
        }

        require_once $path;
    }
})();
