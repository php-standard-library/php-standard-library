<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal;

use Psl\HTTP\Client\Exception\RequestException;
use Psl\HTTP\Message\FieldMap;

use function count;
use function ctype_digit;
use function ltrim;
use function preg_match;
use function strlen;
use function trim;

use const PHP_INT_MAX;

/**
 * Structural validation shared by the HTTP/1 and HTTP/2 request paths.
 *
 * Applying the same checks on both paths closes an HTTP/2-to-HTTP/1 downgrade
 * injection: a request a proxy forwards from H2 to H1 cannot carry a method,
 * target or field that would be reinterpreted as framing on the wire
 * (RFC 9110 Sections 5.5 and 9.1, RFC 9112 Section 3).
 *
 * @internal
 */
final class RequestValidator
{
    private function __construct() {}

    /**
     * @throws RequestException If the method is not a token (RFC 9110 Section 9.1).
     */
    public static function validateMethod(string $method): void
    {
        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $method) !== 1) {
            throw RequestException::forInvalidRequest('Method must be a token.');
        }
    }

    /**
     * The request target must be a single run of visible ASCII: no spaces, tabs,
     * control bytes or non-ASCII, which would otherwise split the request line or
     * smuggle a second request (RFC 9112 Section 3.2).
     *
     * @throws RequestException If the target is empty or contains forbidden bytes.
     */
    public static function validateRequestTarget(string $target): void
    {
        if ($target === '' || preg_match('/^[\x21-\x7e]+$/D', $target) !== 1) {
            throw RequestException::forInvalidRequest('Request target contains invalid characters.');
        }
    }

    /**
     * @throws RequestException If the name is not a token.
     */
    public static function validateFieldName(string $name): void
    {
        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1) {
            throw RequestException::forInvalidRequest('Header field name must be a token.');
        }
    }

    /**
     * Field values allow visible ASCII, obs-text, spaces and tabs, but no other
     * control bytes, and no leading or trailing whitespace (RFC 9110 Section 5.5).
     *
     * @throws RequestException If the value contains a forbidden control byte.
     */
    public static function validateFieldValue(string $value): void
    {
        if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1 || trim($value, " \t") !== $value) {
            throw RequestException::forInvalidRequest('Header field value contains invalid characters.');
        }
    }

    /**
     * Validate every field name and value, and reject more than one Host.
     *
     * @throws RequestException If a field is malformed or Host is duplicated.
     */
    public static function validateFields(FieldMap $fields): void
    {
        foreach ($fields as [$name, $value]) {
            self::validateFieldName($name);
            self::validateFieldValue($value);
        }

        if (count($fields->getAll('host')) > 1) {
            throw RequestException::forInvalidRequest('A request must not carry multiple Host header fields.');
        }
    }

    /**
     * Parse a Content-Length field, rejecting duplicates and out-of-range values.
     *
     * @return null|non-negative-int The declared length, or null when absent.
     *
     * @throws RequestException If the value is malformed.
     */
    public static function contentLength(FieldMap $fields): null|int
    {
        $value = $fields->get('content-length');
        if ($value === null) {
            return null;
        }

        $value = trim($value, " \t");
        $digits = ltrim($value, '0');
        $maximum = (string) PHP_INT_MAX;
        if (
            !ctype_digit($value)
            || strlen($digits) > strlen($maximum)
            || strlen($digits) === strlen($maximum) && $digits > $maximum
            || count($fields->getAll('content-length')) !== 1
        ) {
            throw RequestException::forInvalidRequest('Invalid Content-Length.');
        }

        /** @var non-negative-int */
        return (int) $value;
    }
}
