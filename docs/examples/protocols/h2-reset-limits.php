<?php

declare(strict_types=1);

use Psl\DateTime\Duration;
use Psl\H2;

// Allow up to 50 resets caused by peer errors in each 10-second window.
$configuration = new H2\Configuration(rateLimiter: new H2\RateLimiter([
    H2\RateLimiter::STREAM_ERROR_RESET => [50, Duration::seconds(10)],
]));
