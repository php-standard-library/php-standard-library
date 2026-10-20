<?php

declare(strict_types=1);

namespace Psl\Message\Exception;

use Psl\Exception;
use Throwable;

/**
 * Thrown when an invalid argument is passed to a Psl\Message component method.
 *
 * @see InvalidMailboxException for the subclass specific to invalid mailbox components.
 *
 * @inheritors InvalidMailboxException
 *
 * @api
 */
class InvalidArgumentException extends Exception\InvalidArgumentException implements ExceptionInterface
{
    protected function __construct(string $message, null|Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Create an exception for when the envelope recipients list is empty.
     *
     * @see Envelope::__construct()
     */
    public static function forEmptyRecipients(): self
    {
        return new self('Envelope must have at least one recipient.');
    }

    /**
     * A header value contains a line break or null byte.
     */
    public static function forInvalidHeaderValue(): self
    {
        return new self('Header values must not contain CR, LF, or NUL.');
    }
}
