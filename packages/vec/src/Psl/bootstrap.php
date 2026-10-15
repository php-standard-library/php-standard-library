<?php

declare(strict_types=1);

(static function (): void {
    $functions = [
        'Psl\Vec\chunk' => __DIR__ . '/Vec/chunk.php',
        'Psl\Vec\chunk_with_keys' => __DIR__ . '/Vec/chunk_with_keys.php',
        'Psl\Vec\concat' => __DIR__ . '/Vec/concat.php',
        'Psl\Vec\drop' => __DIR__ . '/Vec/drop.php',
        'Psl\Vec\enumerate' => __DIR__ . '/Vec/enumerate.php',
        'Psl\Vec\fill' => __DIR__ . '/Vec/fill.php',
        'Psl\Vec\filter' => __DIR__ . '/Vec/filter.php',
        'Psl\Vec\filter_keys' => __DIR__ . '/Vec/filter_keys.php',
        'Psl\Vec\filter_nonnull_by' => __DIR__ . '/Vec/filter_nonnull_by.php',
        'Psl\Vec\filter_nulls' => __DIR__ . '/Vec/filter_nulls.php',
        'Psl\Vec\filter_with_key' => __DIR__ . '/Vec/filter_with_key.php',
        'Psl\Vec\flat_map' => __DIR__ . '/Vec/flat_map.php',
        'Psl\Vec\flatten' => __DIR__ . '/Vec/flatten.php',
        'Psl\Vec\intersect' => __DIR__ . '/Vec/intersect.php',
        'Psl\Vec\keys' => __DIR__ . '/Vec/keys.php',
        'Psl\Vec\map' => __DIR__ . '/Vec/map.php',
        'Psl\Vec\map_nonnull' => __DIR__ . '/Vec/map_nonnull.php',
        'Psl\Vec\map_with_key' => __DIR__ . '/Vec/map_with_key.php',
        'Psl\Vec\partition' => __DIR__ . '/Vec/partition.php',
        'Psl\Vec\range' => __DIR__ . '/Vec/range.php',
        'Psl\Vec\reductions' => __DIR__ . '/Vec/reductions.php',
        'Psl\Vec\reproduce' => __DIR__ . '/Vec/reproduce.php',
        'Psl\Vec\reverse' => __DIR__ . '/Vec/reverse.php',
        'Psl\Vec\shuffle' => __DIR__ . '/Vec/shuffle.php',
        'Psl\Vec\slice' => __DIR__ . '/Vec/slice.php',
        'Psl\Vec\sort' => __DIR__ . '/Vec/sort.php',
        'Psl\Vec\sort_by' => __DIR__ . '/Vec/sort_by.php',
        'Psl\Vec\take' => __DIR__ . '/Vec/take.php',
        'Psl\Vec\unique' => __DIR__ . '/Vec/unique.php',
        'Psl\Vec\unique_by' => __DIR__ . '/Vec/unique_by.php',
        'Psl\Vec\unique_scalar' => __DIR__ . '/Vec/unique_scalar.php',
        'Psl\Vec\values' => __DIR__ . '/Vec/values.php',
        'Psl\Vec\zip' => __DIR__ . '/Vec/zip.php',
    ];

    foreach ($functions as $function => $path) {
        if (function_exists($function)) {
            continue;
        }

        require_once $path;
    }
})();
