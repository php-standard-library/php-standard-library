<?php

declare(strict_types=1);

namespace Psl\Message\Exception;

/**
 * Thrown when a {@see \Psl\Message\Address\Mailbox} is constructed with invalid components.
 *
 * Each factory method describes the specific validation failure (empty local-part,
 * empty domain, invalid characters, etc.).
 *
 * @api
 */
final class InvalidMailboxException extends InvalidArgumentException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * The local-part (before @) is empty.
     */
    public static function forEmptyLocalPart(): self
    {
        return new self('Mailbox local-part must not be empty.');
    }

    /**
     * The domain (after @) is empty.
     */
    public static function forEmptyDomain(): self
    {
        return new self('Mailbox domain must not be empty.');
    }

    /**
     * The local-part contains invalid characters.
     */
    public static function forInvalidLocalPart(string $input): self
    {
        return new self('Invalid mailbox local-part: "' . $input . '".');
    }

    /**
     * The domain contains invalid characters.
     */
    public static function forInvalidDomain(string $input): self
    {
        return new self('Invalid mailbox domain: "' . $input . '".');
    }

    /**
     * The display name contains a header delimiter.
     */
    public static function forInvalidDisplayName(): self
    {
        return new self('Mailbox display name must not contain CR, LF, or NUL.');
    }

    /**
     * The comment contains a header delimiter.
     */
    public static function forInvalidComment(): self
    {
        return new self('Mailbox comment must not contain CR, LF, or NUL.');
    }
}
