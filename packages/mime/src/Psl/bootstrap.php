<?php

declare(strict_types=1);

(static function (): void {
    $constants = [
        'Psl\MIME\Sniff\Internal\SNIFF_BUFFER_SIZE' => __DIR__ . '/MIME/Sniff/Internal/constants.php',
    ];

    $functions = [
        'Psl\MIME\Sniff\from_string' => __DIR__ . '/MIME/Sniff/from_string.php',
        'Psl\MIME\Sniff\from_handle' => __DIR__ . '/MIME/Sniff/from_handle.php',
        'Psl\MIME\Sniff\Internal\match_signatures' => __DIR__ . '/MIME/Sniff/Internal/match_signatures.php',
        'Psl\MIME\Sniff\Internal\sniff_riff' => __DIR__ . '/MIME/Sniff/Internal/sniff_riff.php',
        'Psl\MIME\Sniff\Internal\sniff_bmp' => __DIR__ . '/MIME/Sniff/Internal/sniff_bmp.php',
        'Psl\MIME\Sniff\Internal\sniff_ftyp' => __DIR__ . '/MIME/Sniff/Internal/sniff_ftyp.php',
        'Psl\MIME\Sniff\Internal\sniff_text' => __DIR__ . '/MIME/Sniff/Internal/sniff_text.php',
        'Psl\MIME\Sniff\Internal\is_binary' => __DIR__ . '/MIME/Sniff/Internal/is_binary.php',
        'Psl\MIME\Internal\generate_boundary' => __DIR__ . '/MIME/Internal/generate_boundary.php',
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
