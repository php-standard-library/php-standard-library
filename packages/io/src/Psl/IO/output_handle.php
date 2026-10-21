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
 * In CLI mode the handle writes to a duplicate of STDOUT, which it makes nonblocking. Other processes sharing the
 * standard output see that too; once the handle is closed, or at the end of an orderly shutdown, the standard output
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
function output_handle(): StreamHandleInterface&WriteHandleInterface&CloseHandleInterface
{
    /** @var WeakMap<Driver, StreamHandleInterface&WriteHandleInterface&CloseHandleInterface> $cache */
    static $cache = new WeakMap();

    $key = EventLoop::getDriver();
    if ($cache->offsetExists($key)) {
        return $cache->offsetGet($key);
    }

    if (PHP_SAPI === 'cli') {
        $stream = Internal\open_resource('php://stdout', 'wb');
        // The duplicate shares its description with the standard output, and with the processes that started this
        // one: the handle closes the duplicate, but puts back the description's blocking mode first.
        Internal\BlockingModeRegistry::joinForNextHandle($stream);
        try {
            $handle = new CloseWriteStreamHandle($stream);
        } finally {
            Internal\BlockingModeRegistry::abandon($stream);
        }
    } else {
        $handle = new CloseWriteStreamHandle(Internal\open_resource('php://output', 'wb'));
    }

    $cache->offsetSet($key, $handle);

    return $handle;
}
