<?php

declare(strict_types=1);

namespace Psl\Cache\Exception;

use Psl\Exception;

/**
 * Thrown when an argument is invalid, such as a cache key that is empty or contains illegal characters,
 * or a non-positive maximum cache size.
 *
 * @api
 */
final class InvalidArgumentException extends Exception\InvalidArgumentException implements ExceptionInterface {}
