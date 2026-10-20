<?php

declare(strict_types=1);

namespace Psl\Password\Exception;

use Psl\Exception;

/**
 * @mutation-free
 *
 * @api
 */
final class InvalidArgumentException extends Exception\InvalidArgumentException implements ExceptionInterface {}
