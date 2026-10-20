<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\H2;

/** @var H2\ConnectionInterface $connection */

while ($connection->isConnected()) {
    try {
        $events = $connection->readEvent();
        // Handle events for the active streams.
    } catch (H2\Exception\StreamException $error) {
        // H2 sent RST_STREAM for $error->streamId. Keep reading other streams.
        continue;
    }
}
