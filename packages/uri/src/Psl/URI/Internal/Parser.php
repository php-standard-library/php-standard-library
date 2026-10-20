<?php

declare(strict_types=1);

namespace Psl\URI\Internal;

use Psl\IP;
use Psl\IP\Address;
use Psl\URI\Authority\Authority;
use Psl\URI\Authority\HostInterface;
use Psl\URI\Authority\IPHost;
use Psl\URI\Authority\RegisteredNameHost;
use Psl\URI\Exception\InvalidURIException;
use Psl\URI\PathKind;
use Psl\URI\URI;

use function preg_match;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function substr_count;

/**
 * RFC 3986 URI parser with eager normalization.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc3986
 *
 * @internal
 */
final class Parser
{
    /**
     * Parse a URI string per RFC 3986.
     *
     * Applies eager normalization:
     * - Case normalization (scheme and host lowercased)
     * - Percent-encoding normalization (unreserved chars decoded, hex uppercased)
     * - Dot-segment removal (path traversal prevention)
     *
     * @throws InvalidURIException If the input is not a valid URI.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc3986#appendix-B
     */
    public static function parse(string $input): URI
    {
        self::rejectNonASCII($input);
        self::validatePercentEncoding($input);

        $matches = [];
        if (!preg_match('/^(([^:\/?#]+):)?(\/\/([^\/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?$/s', $input, $matches)) {
            return new URI(null, null, '', PathKind::None, null, null);
        }

        $scheme = ($matches[2] ?? '') !== '' ? $matches[2] : null;
        $hasAuthority = ($matches[3] ?? '') !== '';
        $authorityString = $matches[4] ?? '';
        $path = $matches[5] ?? '';
        $hasQuery = isset($matches[6]) && $matches[6] !== '';
        $query = $hasQuery ? $matches[7] ?? '' : null;
        $hasFragment = isset($matches[8]) && $matches[8] !== '';
        $fragment = $hasFragment ? $matches[9] ?? '' : null;

        if ($scheme !== null) {
            self::validateScheme($scheme);
            $scheme = Normalizer::normalizeScheme($scheme);
            /** @var non-empty-string $scheme */
        } elseif (!$hasAuthority && preg_match('/^[^\/]*:/', $path)) {
            throw InvalidURIException::forInvalidPath($path);
        }

        $authority = $hasAuthority ? self::parseAuthority($authorityString) : null;

        $path = Normalizer::normalizeEncoding($path);
        if ($authority !== null) {
            $path = Normalizer::removeDotSegments($path);
        } elseif ($scheme !== null) {
            $path = Normalizer::removeDotSegments($path);
        }

        if ($query !== null) {
            $query = Normalizer::normalizeEncoding($query);
        }

        if ($fragment !== null) {
            $fragment = Normalizer::normalizeEncoding($fragment);
        }

        $pathKind = self::determinePathKind($path);

        /** @var non-empty-string|null $scheme */
        return new URI($scheme, $authority, $path, $pathKind, $query, $fragment);
    }

    /**
     * Reject non-ASCII bytes in URI input.
     *
     * @throws InvalidURIException If the input contains non-ASCII bytes.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc3986#section-2
     */
    private static function rejectNonASCII(string $input): void
    {
        if (preg_match('/[\x80-\xFF]/', $input)) {
            throw InvalidURIException::forNonASCII();
        }
    }

    /**
     * Validate that all percent-encoding sequences are well-formed.
     *
     * @throws InvalidURIException If the input contains invalid percent-encoding.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc3986#section-2.1
     */
    private static function validatePercentEncoding(string $input): void
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $input)) {
            $percentPos = strpos($input, '%');
            if ($percentPos !== false) {
                $length = strlen($input);
                $remaining = $length - $percentPos;
                /** @var non-negative-int $sliceLen */
                $sliceLen = $remaining < 3 ? $remaining : 3;
                $sequence = substr($input, $percentPos, $sliceLen);
                throw InvalidURIException::forInvalidPercentEncoding($sequence);
            }

            throw InvalidURIException::forInvalidPercentEncoding('%');
        }
    }

    /**
     * Validate a URI scheme per RFC 3986 Section 3.1.
     *
     * @throws InvalidURIException If the scheme is invalid.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc3986#section-3.1
     */
    private static function validateScheme(string $scheme): void
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9+\-.]*$/', $scheme)) {
            throw InvalidURIException::forInvalidScheme($scheme);
        }
    }

    /**
     * Parse the authority component per RFC 3986 Section 3.2.
     *
     * @throws InvalidURIException If the authority is malformed.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc3986#section-3.2
     */
    private static function parseAuthority(string $authority): Authority
    {
        $userInfo = null;
        $remaining = $authority;

        $atPos = strrpos($remaining, '@');
        if ($atPos !== false) {
            $userInfo = Normalizer::normalizeEncoding(substr($remaining, 0, $atPos));
            $remaining = substr($remaining, $atPos + 1);
        }

        [$host, $port] = self::parseHost($remaining);

        /** @var int<0, 65535>|null $port */
        return new Authority($userInfo, $host, $port);
    }

    /**
     * Parse a host and optional port from a string.
     *
     * @return array{HostInterface, null|int}
     *
     * @throws InvalidURIException If the host or port is invalid.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc3986#section-3.2.2
     */
    private static function parseHost(string $hostPort): array
    {
        if (str_starts_with($hostPort, '[')) {
            return self::parseIPLiteral($hostPort);
        }

        // Detect bare IPv6 addresses (multiple colons indicate IPv6, not host:port).
        // Per RFC 3986 Section 3.2.2, IPv6 addresses MUST be enclosed in brackets
        // in URIs. However, we handle bare IPv6 gracefully to avoid silent
        // misparsing (e.g., "::1" being split into host=":" and port=1).
        $colonCount = substr_count($hostPort, ':');
        if ($colonCount > 1) {
            try {
                /** @var non-empty-string $hostPort */
                $address = Address::parse($hostPort);

                return [new IPHost($address), null];
            } catch (IP\Exception\InvalidArgumentException) {
                // @mago-expect lint:no-empty-catch-clause - not a valid IPv6, fall through to normal host:port parsing
            }
        }

        $port = null;
        $hostString = $hostPort;
        $lastColon = strrpos($hostPort, ':');
        if ($lastColon !== false) {
            $potentialPort = substr($hostPort, $lastColon + 1);
            if ($potentialPort === '' || preg_match('/^[0-9]+$/', $potentialPort)) {
                $hostString = substr($hostPort, 0, $lastColon);
                if ($potentialPort !== '') {
                    $portValue = (int) $potentialPort;
                    if ($portValue > 65_535) {
                        throw InvalidURIException::forInvalidPort($potentialPort);
                    }

                    $port = $portValue;
                }
            }
        }

        /** @var non-empty-string $hostString */
        $hostString = Normalizer::normalizeHost($hostString);

        if (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $hostString)) {
            try {
                return [
                    new IPHost(Address::parse($hostString)),
                    $port,
                ];
            } catch (IP\Exception\InvalidArgumentException) {
                // @mago-expect lint:no-empty-catch-clause - not a valid IP (e.g. 999.999.999.999), fall through to registered name
            }
        }

        return [new RegisteredNameHost($hostString), $port];
    }

    /**
     * Parse an IP-literal (bracketed IPv6 or IPvFuture) with optional port.
     *
     * @return array{HostInterface, null|int}
     *
     * @throws InvalidURIException If the IP-literal is malformed.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc3986#section-3.2.2
     */
    private static function parseIPLiteral(string $input): array
    {
        $closeBracket = strpos($input, ']');
        if ($closeBracket === false) {
            throw InvalidURIException::forInvalidHost($input);
        }

        /** @var non-negative-int $insideLen */
        $insideLen = $closeBracket - 1;
        $insideBrackets = substr($input, 1, $insideLen);
        $afterBrackets = substr($input, $closeBracket + 1);

        $port = null;
        if (str_starts_with($afterBrackets, ':')) {
            $portString = substr($afterBrackets, 1);
            if ($portString !== '') {
                if (!preg_match('/^[0-9]+$/', $portString)) {
                    throw InvalidURIException::forInvalidPort($portString);
                }

                $portValue = (int) $portString;
                if ($portValue > 65_535) {
                    throw InvalidURIException::forInvalidPort($portString);
                }

                $port = $portValue;
            }
        } elseif ($afterBrackets !== '') {
            throw InvalidURIException::forInvalidHost($input);
        }

        $zone = null;
        $addressString = $insideBrackets;
        $zonePos = strpos($insideBrackets, '%25');
        if ($zonePos !== false) {
            $addressString = substr($insideBrackets, 0, $zonePos);
            $zone = substr($insideBrackets, $zonePos + 3);
        }

        try {
            /** @var non-empty-string $addressString */
            $address = Address::parse($addressString);
        } catch (IP\Exception\InvalidArgumentException $e) {
            throw InvalidURIException::forInvalidHost('[' . $insideBrackets . ']', $e);
        }

        return [new IPHost($address, $zone), $port];
    }

    /**
     * Determine the path kind from a path string.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc3986#section-3.3
     */
    private static function determinePathKind(string $path): PathKind
    {
        if ($path === '') {
            return PathKind::None;
        }

        if ($path[0] === '/') {
            return PathKind::Absolute;
        }

        return PathKind::Rootless;
    }
}
