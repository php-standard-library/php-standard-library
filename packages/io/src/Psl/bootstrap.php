<?php

declare(strict_types=1);

(static function (): void {
    $functions = [
        'Psl\IO\Internal\close_all' => __DIR__ . '/IO/Internal/close_all.php',
        'Psl\IO\Internal\open_resource' => __DIR__ . '/IO/Internal/open_resource.php',
        'Psl\IO\Internal\suppress' => __DIR__ . '/IO/Internal/suppress.php',
        'Psl\IO\copy' => __DIR__ . '/IO/copy.php',
        'Psl\IO\copy_chunked' => __DIR__ . '/IO/copy_chunked.php',
        'Psl\IO\copy_bidirectional' => __DIR__ . '/IO/copy_bidirectional.php',
        'Psl\IO\copy_bidirectional_chunked' => __DIR__ . '/IO/copy_bidirectional_chunked.php',
        'Psl\IO\error_handle' => __DIR__ . '/IO/error_handle.php',
        'Psl\IO\input_handle' => __DIR__ . '/IO/input_handle.php',
        'Psl\IO\is_terminal' => __DIR__ . '/IO/is_terminal.php',
        'Psl\IO\output_handle' => __DIR__ . '/IO/output_handle.php',
        'Psl\IO\pipe' => __DIR__ . '/IO/pipe.php',
        'Psl\IO\spool' => __DIR__ . '/IO/spool.php',
        'Psl\IO\streaming' => __DIR__ . '/IO/streaming.php',
        'Psl\IO\write' => __DIR__ . '/IO/write.php',
        'Psl\IO\write_error' => __DIR__ . '/IO/write_error.php',
        'Psl\IO\write_error_line' => __DIR__ . '/IO/write_error_line.php',
        'Psl\IO\write_line' => __DIR__ . '/IO/write_line.php',
    ];

    foreach ($functions as $function => $path) {
        if (function_exists($function)) {
            continue;
        }

        require_once $path;
    }
})();
