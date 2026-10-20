<?php

declare(strict_types=1);

(static function (): void {
    $functions = [
        'Psl\Message\Internal\encode_domain' => __DIR__ . '/Message/Internal/encode_domain.php',
        'Psl\Message\parse' => __DIR__ . '/Message/parse.php',
        'Psl\Message\serialize' => __DIR__ . '/Message/serialize.php',
    ];

    foreach ($functions as $function => $path) {
        if (function_exists($function)) {
            continue;
        }

        require_once $path;
    }
})();
