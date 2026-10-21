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
 * In CLI mode the handle reads a duplicate of STDIN, which it makes nonblocking. Other processes sharing the
 * standard input see that too; once the handle is closed, or at the end of an orderly shutdown, the standard input
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
function input_handle(): CloseHandleInterface&ReadHandleInterface&StreamHandleInterface
{
    /** @var WeakMap<Driver, CloseHandleInterface&ReadHandleInterface&StreamHandleInterface> $cache */
    static $cache = new WeakMap();

    $key = EventLoop::getDriver();
    if ($cache->offsetExists($key)) {
        return $cache->offsetGet($key);
    }

    if (PHP_SAPI === 'cli') {
        $stream = Internal\open_resource('php://stdin', 'rb');
        // The duplicate shares its description with the standard input, and with the processes that started this one:
        // the handle closes the duplicate, but puts back the description's blocking mode first.
        Internal\BlockingModeRegistry::joinForNextHandle($stream);
        try {
            $handle = new CloseReadStreamHandle($stream);
        } finally {
            Internal\BlockingModeRegistry::abandon($stream);
        }
    } else {
        $handle = new CloseReadStreamHandle(Internal\open_resource('php://input', 'rb'));
    }

    $cache->offsetSet($key, $handle);

    return $handle;
}
