<?php

declare(strict_types=1);

namespace Psl\DNS\Exception;

use Psl\Exception;
use Throwable;

/**
 * Thrown when invalid input is provided to a DNS operation, including malformed
 * domain names, invalid record encodings, and bad EDNS option data.
 *
 * @api
 */
final class InvalidArgumentException extends Exception\InvalidArgumentException implements ExceptionInterface
{
    private function __construct(string $message, null|Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Create an exception when a DNS label exceeds the 63-octet maximum.
     */
    public static function forLabelTooLong(string $label): self
    {
        return new self('DNS label \'' . $label . '\' exceeds maximum length of 63 octets.');
    }

    /**
     * Create an exception when a DNS label contains a null byte.
     */
    public static function forLabelContainsNull(): self
    {
        return new self('DNS label contains a null byte.');
    }

    /**
     * Create an exception when a DNS name exceeds the 255-octet maximum encoded length.
     */
    public static function forNameTooLong(string $name): self
    {
        return new self('DNS name \'' . $name . '\' exceeds maximum encoded length of 255 octets.');
    }

    /**
     * Create an exception for a DNS name encoding failure.
     */
    public static function forEncodingFailure(string $detail, Throwable $previous): self
    {
        return new self('Failed to encode DNS name: ' . $detail . '.', $previous);
    }

    /**
     * Create an exception when a type bitmap length is out of valid range.
     */
    public static function forTypeBitmapLength(int $length): self
    {
        return new self('Type bitmap length ' . $length . ' is out of valid range.');
    }

    /**
     * Create an exception when a CAA tag length overflows the available data.
     */
    public static function forCAATagLengthOverflow(int $tagLength, int $available): self
    {
        return new self('CAA tag length ' . $tagLength . ' exceeds available data of ' . $available . ' bytes.');
    }

    /**
     * Create an exception for an invalid base32hex character.
     */
    public static function forInvalidBase32HexCharacter(string $char): self
    {
        return new self('Invalid base32hex character: \'' . $char . '\'.');
    }

    /**
     * Create an exception when encoding an EDNS option fails.
     */
    public static function forOptionEncodingFailure(
        string $option,
        string $detail,
        null|Throwable $previous = null,
    ): self {
        return new self('Failed to encode ' . $option . ' option: ' . $detail . '.', $previous);
    }
}
