<?php

declare(strict_types=1);

namespace Psl\IO;

use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use WeakMap;

use const PHP_SAPI;

/**
 * Return the input handle for the current request.
 *
 * In CLI mode, this is likely STDIN; for HTTP requests, it may contain the
 * POST data, if any.
 *
 * @throws Exception\RuntimeException If the stream cannot be opened, for example when the process has no file
 *  descriptors left.
 *
 * @codeCoverageIgnore
 *
 * @api
 */
function input_handle(): CloseHandleInterface&ReadHandleInterface&StreamHandleInterface
{
    /** @var WeakMap<Driver, CloseHandleInterface&ReadHandleInterface&StreamHandleInterface> $cache */
    static $cache = new WeakMap();

    $key = EventLoop::getDriver();
    if ($cache->offsetExists($key)) {
        return $cache->offsetGet($key);
    }

    if (PHP_SAPI === 'cli') {
        $handle = new CloseReadStreamHandle(Internal\open_resource('php://stdin', 'rb'));
    } else {
        $handle = new CloseReadStreamHandle(Internal\open_resource('php://input', 'rb'));
    }

    $cache->offsetSet($key, $handle);

    return $handle;
}
