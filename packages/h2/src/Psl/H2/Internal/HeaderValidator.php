<?php

declare(strict_types=1);

namespace Psl\H2\Internal;

use Psl\H2\Exception\StreamException;
use Psl\HPACK\Header;

use function strlen;
use function strpbrk;
use function strspn;
use function strtolower;
use function substr;
use function trim;

/**
 * Validates HTTP/2 header fields per RFC 9113 §8.2–§8.3 and RFC 8441.
 *
 * @internal
 */
final readonly class HeaderValidator
{
    /**
     * HTTP/1.1 connection-specific headers that are forbidden in HTTP/2 (RFC 9113 section 8.2.2).
     *
     * @var array<string, true>
     */
    private const array BANNED_HEADERS = [
        'connection' => true,
        'keep-alive' => true,
        'proxy-connection' => true,
        'upgrade' => true,
        'transfer-encoding' => true,
    ];

    /**
     * Lowercase token characters allowed in HTTP/2 field names (RFC 9110 Section 5.6.2).
     */
    private const string TOKEN_CHARACTERS = "!#$%&'*+-.^_`|~0123456789abcdefghijklmnopqrstuvwxyz";

    /**
     * Control bytes forbidden in a field value: NUL, every C0 control except HTAB,
     * and DEL.
     */
    private const string FORBIDDEN_VALUE_BYTES =
        "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0a\x0b\x0c\x0d\x0e\x0f"
            . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /**
     * Valid request pseudo-headers per RFC 9113 Section 8.3.1 and RFC 8441.
     *
     * @var array<string, true>
     */
    private const array REQUEST_PSEUDO_HEADERS = [
        ':method' => true,
        ':scheme' => true,
        ':path' => true,
        ':authority' => true,
        ':protocol' => true,
    ];

    /**
     * Valid response pseudo-headers per RFC 9113 Section 8.3.2.
     *
     * @var array<string, true>
     */
    private const array RESPONSE_PSEUDO_HEADERS = [
        ':status' => true,
    ];

    /**
     * @param list<Header> $headers
     *
     * @throws StreamException If headers violate RFC 9113 §8.2–§8.3.
     */
    public static function validate(array $headers, bool $isClient, bool $isTrailing, int $streamId): void
    {
        $pseudoSeen = [];
        $pseudosDone = false;
        $method = null;
        $hasProtocol = false;

        foreach ($headers as $header) {
            /** @var string $name */
            $name = $header->name;
            if ($name === '') {
                throw StreamException::forMalformedMessage($streamId, 'Header name must not be empty');
            }

            self::validateFieldValue($header, $streamId);

            if ($header->name[0] === ':') {
                if ($isTrailing) {
                    throw StreamException::forMalformedMessage(
                        $streamId,
                        'Pseudo-header "' . $header->name . '" in trailing headers',
                    );
                }

                if ($pseudosDone) {
                    throw StreamException::forMalformedMessage(
                        $streamId,
                        'Pseudo-header "' . $header->name . '" appears after regular headers',
                    );
                }

                if (isset($pseudoSeen[$header->name])) {
                    throw StreamException::forMalformedMessage(
                        $streamId,
                        'Duplicate pseudo-header "' . $header->name . '"',
                    );
                }

                $allowed = $isClient ? self::RESPONSE_PSEUDO_HEADERS : self::REQUEST_PSEUDO_HEADERS;
                if (!isset($allowed[$header->name])) {
                    throw StreamException::forMalformedMessage(
                        $streamId,
                        'Invalid pseudo-header "'
                        . $header->name
                        . '" for '
                        . ($isClient ? 'client' : 'server')
                        . ' role',
                    );
                }

                $pseudoSeen[$header->name] = true;

                if ($header->name === ':method') {
                    $method = $header->value;
                }

                if ($header->name === ':protocol') {
                    $hasProtocol = true;
                }

                if ($header->name === ':path' && $header->value === '') {
                    throw StreamException::forMalformedMessage($streamId, 'Pseudo-header ":path" must not be empty');
                }
            } else {
                $pseudosDone = true;

                $lower = strtolower($header->name);
                if ($lower !== $header->name) {
                    throw StreamException::forMalformedMessage(
                        $streamId,
                        'Header name "' . $header->name . '" contains uppercase characters',
                    );
                }

                if (strspn($name, self::TOKEN_CHARACTERS) !== strlen($name)) {
                    throw StreamException::forMalformedMessage(
                        $streamId,
                        'Header name "' . $name . '" contains invalid characters',
                    );
                }

                self::validateConnectionSpecificField($header, $streamId);
            }
        }

        if (!$isTrailing) {
            if ($isClient) {
                self::validateResponsePseudoHeaders($pseudoSeen, $streamId);
            } else {
                self::validateRequestPseudoHeaders($pseudoSeen, $method, $hasProtocol, $streamId);
            }
        }
    }

    /**
     * Check an outbound, already lowercased field name (RFC 9113 Section 8.2.1).
     *
     * Pseudo-header names keep their leading colon; regular names must be
     * non-empty token characters.
     *
     * @throws StreamException If the name is empty or contains non-token bytes.
     */
    public static function validateFieldName(string $name, int $streamId): void
    {
        if ($name === '') {
            throw StreamException::forMalformedMessage($streamId, 'Header name must not be empty');
        }

        $token = $name[0] === ':' ? substr($name, 1) : $name;
        if ($token === '' || strspn($token, self::TOKEN_CHARACTERS) !== strlen($token)) {
            throw StreamException::forMalformedMessage(
                $streamId,
                'Header name "' . $name . '" contains invalid characters',
            );
        }
    }

    /**
     * @throws StreamException If the header has connection-specific semantics.
     */
    public static function validateConnectionSpecificField(Header $header, int $streamId): void
    {
        $lower = strtolower($header->name);
        if ($lower === 'te' && strtolower($header->value) !== 'trailers') {
            throw StreamException::forMalformedMessage($streamId, 'Header "te" has value other than "trailers"');
        }

        if (isset(self::BANNED_HEADERS[$lower])) {
            throw StreamException::forMalformedMessage($streamId, 'Banned header "' . $header->name . '" in HTTP/2');
        }
    }

    /**
     * Field values may hold visible ASCII, obs-text, spaces and tabs, but no NUL,
     * CR, LF or other control bytes, and no leading or trailing whitespace
     * (RFC 9113 Section 8.2.1, RFC 9110 Section 5.5).
     *
     * @throws StreamException If the field value contains a forbidden byte.
     */
    public static function validateFieldValue(Header $header, int $streamId): void
    {
        if (
            strpbrk($header->value, self::FORBIDDEN_VALUE_BYTES) !== false
            || trim($header->value, " \t") !== $header->value
        ) {
            throw StreamException::forMalformedMessage($streamId, 'Invalid value for header "' . $header->name . '"');
        }
    }

    /**
     * @param array<string, true> $pseudoSeen
     *
     * @throws StreamException
     */
    private static function validateRequestPseudoHeaders(
        array $pseudoSeen,
        null|string $method,
        bool $hasProtocol,
        int $streamId,
    ): void {
        if (!isset($pseudoSeen[':method'])) {
            throw StreamException::forMalformedMessage($streamId, 'Missing required pseudo-header ":method"');
        }

        if ($method === 'CONNECT') {
            if ($hasProtocol) {
                // Extended CONNECT (RFC 8441): :scheme and :path are REQUIRED.
                if (!isset($pseudoSeen[':scheme'])) {
                    throw StreamException::forMalformedMessage(
                        $streamId,
                        'Extended CONNECT request MUST include ":scheme"',
                    );
                }

                if (!isset($pseudoSeen[':path'])) {
                    throw StreamException::forMalformedMessage(
                        $streamId,
                        'Extended CONNECT request MUST include ":path"',
                    );
                }
            } else {
                // Regular CONNECT (RFC 9113 §8.5): :scheme and :path MUST NOT be present.
                if (isset($pseudoSeen[':scheme'])) {
                    throw StreamException::forMalformedMessage($streamId, 'CONNECT request MUST NOT include ":scheme"');
                }

                if (isset($pseudoSeen[':path'])) {
                    throw StreamException::forMalformedMessage($streamId, 'CONNECT request MUST NOT include ":path"');
                }
            }
        } else {
            // :protocol is only valid with CONNECT
            if ($hasProtocol) {
                throw StreamException::forMalformedMessage(
                    $streamId,
                    ':protocol pseudo-header is only valid with the CONNECT method',
                );
            }

            if (!isset($pseudoSeen[':scheme'])) {
                throw StreamException::forMalformedMessage($streamId, 'Missing required pseudo-header ":scheme"');
            }

            if (!isset($pseudoSeen[':path'])) {
                throw StreamException::forMalformedMessage($streamId, 'Missing required pseudo-header ":path"');
            }
        }
    }

    /**
     * @param array<string, true> $pseudoSeen
     *
     * @throws StreamException
     */
    private static function validateResponsePseudoHeaders(array $pseudoSeen, int $streamId): void
    {
        if (!isset($pseudoSeen[':status'])) {
            throw StreamException::forMalformedMessage($streamId, 'Missing required pseudo-header ":status"');
        }
    }
}
