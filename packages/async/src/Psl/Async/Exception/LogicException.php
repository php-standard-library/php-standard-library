<?php

declare(strict_types=1);

namespace Psl\Async\Exception;

use Psl\Exception;

/**
 * Thrown when the async API is used in a way its contract forbids, such as entering the event loop
 * from inside a fiber.
 *
 * @api
 */
class LogicException extends Exception\LogicException implements ExceptionInterface {}
