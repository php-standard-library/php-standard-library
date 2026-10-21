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
 * In CLI mode the handle writes to a duplicate of STDERR, which it makes nonblocking. Other processes sharing the
 * standard error see that too; once the handle is closed, or at the end of an orderly shutdown, the standard error
 * gets back the blocking mode it had. A child process started while the handle is open still shares the nonblocking
 * mode.
 *
 * @throws Exception\RuntimeException If the stream cannot be opened, for example when the process has no file
 *  descriptors left.
 *
 * @codeCoverageIgnore
 *
 * @api
 */
function error_handle(): null|(CloseHandleInterface&WriteHandleInterface&StreamHandleInterface)
{
    /** @var WeakMap<Driver, null|(CloseHandleInterface&WriteHandleInterface&StreamHandleInterface)> $cache */
    static $cache = new WeakMap();

    $key = EventLoop::getDriver();
    if ($cache->offsetExists($key)) {
        return $cache->offsetGet($key);
    }

    $handle = null;
    if (PHP_SAPI === 'cli') {
        $stream = Internal\open_resource('php://stderr', 'wb');
        // The duplicate shares its description with the standard error, and with the processes that started this one:
        // the handle closes the duplicate, but puts back the description's blocking mode first.
        Internal\BlockingModeRegistry::joinForNextHandle($stream);
        try {
            $handle = new CloseWriteStreamHandle($stream);
        } finally {
            Internal\BlockingModeRegistry::abandon($stream);
        }
    }

    $cache->offsetSet($key, $handle);

    return $handle;
}
