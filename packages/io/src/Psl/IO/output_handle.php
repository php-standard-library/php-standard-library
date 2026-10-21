<?php

declare(strict_types=1);

namespace Psl\IO;

use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use WeakMap;

use const PHP_SAPI;

/**
 * Return the output handle for the current request.
 *
 * This should generally be used for sending data to clients. In CLI mode, this
 * is usually the process STDOUT.
 *
 * @throws Exception\RuntimeException If the stream cannot be opened, for example when the process has no file
 *  descriptors left.
 *
 * @codeCoverageIgnore
 *
 * @api
 */
function output_handle(): StreamHandleInterface&WriteHandleInterface&CloseHandleInterface
{
    /** @var WeakMap<Driver, StreamHandleInterface&WriteHandleInterface&CloseHandleInterface> $cache */
    static $cache = new WeakMap();

    $key = EventLoop::getDriver();
    if ($cache->offsetExists($key)) {
        return $cache->offsetGet($key);
    }

    if (PHP_SAPI === 'cli') {
        $handle = new CloseWriteStreamHandle(Internal\open_resource('php://stdout', 'wb'));
    } else {
        $handle = new CloseWriteStreamHandle(Internal\open_resource('php://output', 'wb'));
    }

    $cache->offsetSet($key, $handle);

    return $handle;
}
