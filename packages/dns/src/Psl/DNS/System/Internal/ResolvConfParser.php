<?php

declare(strict_types=1);

namespace Psl\DNS\System\Internal;

use Psl\DNS\Nameserver;
use Psl\DNS\System\Settings;

use function array_values;
use function explode;
use function preg_match;
use function preg_split;
use function str_contains;
use function trim;

/**
 * Parses Linux /etc/resolv.conf output.
 *
 * Format reference: resolv.conf(5) man page.
 *
 * Recognized directives:
 * - nameserver <ip>          (up to 3, per spec)
 * - nameserver <ip>#<port>   (non-standard but used by systemd-resolved)
 * - search <domain> ...      (space-separated search domains)
 * - domain <domain>          (single search domain, overridden by search)
 *
 * Lines starting with '#' or ';' are comments.
 * Empty lines are ignored.
 *
 * @internal
 */
final class ResolvConfParser
{
    /**
     * The whitespace characters matched by `\s` in the patterns below.
     */
    private const string WHITESPACE = " \t\n\r\x0B\x0C";

    /**
     * @param string $content Raw content of /etc/resolv.conf.
     */
    public static function parse(string $content): Settings
    {
        $nameservers = [];
        $searchDomains = [];

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line, self::WHITESPACE);

            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            $matches = null;
            if (preg_match('/^nameserver\s+(.+)$/i', $line, $matches)) {
                $entry = self::parseNameserver(trim($matches[1], self::WHITESPACE));
                if ($entry !== null) {
                    $nameservers[] = $entry;
                }

                continue;
            }

            if (preg_match('/^search\s+(.+)$/i', $line, $matches)) {
                $searchDomains = self::parseSearchDomains(trim($matches[1], self::WHITESPACE));

                continue;
            }

            if (preg_match('/^domain\s+(\S+)$/i', $line, $matches)) {
                $domain = trim($matches[1], self::WHITESPACE);
                if ($domain !== '') {
                    $searchDomains = [$domain];
                }
            }
        }

        return new Settings($nameservers, $searchDomains);
    }

    /**
     * Parse a nameserver value, optionally including a port separated by '#'.
     */
    private static function parseNameserver(string $value): null|Nameserver
    {
        if ($value === '') {
            return null;
        }

        $port = 53;

        if (str_contains($value, '#')) {
            [$value, $portStr] = explode('#', $value, 2);
            $value = trim($value, self::WHITESPACE);
            $parsedPort = (int) trim($portStr, self::WHITESPACE);
            if ($parsedPort > 0 && $parsedPort <= 65_535) {
                $port = $parsedPort;
            }
        }

        if (str_contains($value, '%')) {
            [$value] = explode('%', $value, 2);
            $value = trim($value, self::WHITESPACE);
        }

        if ($value === '') {
            return null;
        }

        return new Nameserver($value, $port);
    }

    /**
     * @return list<string>
     */
    private static function parseSearchDomains(string $value): array
    {
        $domains = [];
        $searchDomains = preg_split('/\s+/', $value);
        if (false === $searchDomains) {
            return $domains;
        }

        foreach ($searchDomains as $domain) {
            $domain = trim($domain, self::WHITESPACE);
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return array_values($domains);
    }
}
