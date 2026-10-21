<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\IO;

// Enforce a maximum of 5 bytes -- throw if the source has more
$inner = new IO\MemoryHandle('hello');
$handle = new IO\BoundedReadHandle($inner, 5);
$handle->readAll(); // 'hello' -- fits exactly, no error

// When the source exceeds the limit, an exception is thrown
$inner = new IO\MemoryHandle('hello world');
$handle = new IO\BoundedReadHandle($inner, 5);
$handle->read(5); // 'hello'

try {
    $handle->read(); // throws -- underlying handle has more data
} catch (IO\Exception\LimitExceededException $e) {
    $e->getMessage(); // 'Data exceeded the configured limit of 5 bytes.'
    $e->limit; // 5
}

// The overflow is latched: later reads throw it again without reading any further
try {
    $handle->read();
} catch (IO\Exception\LimitExceededException $e) {
    $e->limit; // 5
}
