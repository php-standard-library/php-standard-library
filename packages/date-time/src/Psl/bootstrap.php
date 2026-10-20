<?php

declare(strict_types=1);

(static function (): void {
    $constants = [
        'Psl\DateTime\NANOSECONDS_PER_MICROSECOND' => __DIR__ . '/DateTime/constants.php',
    ];

    $functions = [
        'Psl\DateTime\Internal\add_days_to_date' => __DIR__ . '/DateTime/Internal/add_days_to_date.php',
        'Psl\DateTime\Internal\create_intl_calendar' => __DIR__ . '/DateTime/Internal/create_intl_calendar.php',
        'Psl\DateTime\Internal\create_intl_calendar_from_date_time' =>
            __DIR__ . '/DateTime/Internal/create_intl_calendar_from_date_time.php',
        'Psl\DateTime\Internal\create_intl_date_formatter' =>
            __DIR__ . '/DateTime/Internal/create_intl_date_formatter.php',
        'Psl\DateTime\Internal\create_intl_timezone' => __DIR__ . '/DateTime/Internal/create_intl_timezone.php',
        'Psl\DateTime\Internal\default_timezone' => __DIR__ . '/DateTime/Internal/default_timezone.php',
        'Psl\DateTime\Internal\format' => __DIR__ . '/DateTime/Internal/format.php',
        'Psl\DateTime\Internal\format_iso8601_duration' => __DIR__ . '/DateTime/Internal/format_iso8601_duration.php',
        'Psl\DateTime\Internal\format_iso8601_period' => __DIR__ . '/DateTime/Internal/format_iso8601_period.php',
        'Psl\DateTime\Internal\format_rfc3339' => __DIR__ . '/DateTime/Internal/format_rfc3339.php',
        'Psl\DateTime\Internal\high_resolution_time' => __DIR__ . '/DateTime/Internal/high_resolution_time.php',
        'Psl\DateTime\Internal\normalize_duration_parts' => __DIR__ . '/DateTime/Internal/normalize_duration_parts.php',
        'Psl\DateTime\Internal\parse' => __DIR__ . '/DateTime/Internal/parse.php',
        'Psl\DateTime\Internal\parse_iso8601_duration' => __DIR__ . '/DateTime/Internal/parse_iso8601_duration.php',
        'Psl\DateTime\Internal\parse_iso8601_period' => __DIR__ . '/DateTime/Internal/parse_iso8601_period.php',
        'Psl\DateTime\Internal\resolve_local_date_time' => __DIR__ . '/DateTime/Internal/resolve_local_date_time.php',
        'Psl\DateTime\Internal\system_time' => __DIR__ . '/DateTime/Internal/system_time.php',
        'Psl\DateTime\Internal\to_intl_timezone' => __DIR__ . '/DateTime/Internal/to_intl_timezone.php',
        'Psl\DateTime\is_leap_year' => __DIR__ . '/DateTime/is_leap_year.php',
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
