<?php

declare(strict_types=1);

namespace Psl\DNS\System\Internal;

use Psl\DNS\Nameserver;
use Psl\DNS\System\Settings;

use function array_values;
use function explode;
use function in_array;
use function preg_match;
use function preg_split;
use function str_contains;
use function strlen;
use function substr;
use function trim;

use const PREG_OFFSET_CAPTURE;

/**
 * Parses Windows `ipconfig /all` output for DNS server addresses.
 *
 * Example output structure:
 *
 *     Windows IP Configuration
 *
 *        Host Name . . . . . . . . . . . . : DESKTOP-ABC123
 *        Primary Dns Suffix  . . . . . . . : example.com
 *        ...
 *        DNS Suffix Search List. . . . . . : example.com
 *                                            corp.example.com
 *
 *     Ethernet adapter Ethernet:
 *
 *        Connection-specific DNS Suffix  . : lan.local
 *        ...
 *        DNS Servers . . . . . . . . . . . : 8.8.8.8
 *                                            8.8.4.4
 *        ...
 *
 *     Wireless LAN adapter Wi-Fi:
 *
 *        Connection-specific DNS Suffix  . :
 *        ...
 *        DNS Servers . . . . . . . . . . . : 1.1.1.1
 *                                            1.0.0.1
 *        ...
 *
 * DNS server entries span multiple lines when there are multiple servers.
 * Continuation lines are indented and contain only the IP address.
 *
 * Adapters with "Media disconnected" status are skipped.
 *
 * @internal
 */
final class IpconfigParser
{
    /**
     * The whitespace characters matched by `\s` in the patterns below.
     */
    private const string WHITESPACE = " \t\n\r\x0B\x0C";

    /**
     * @param string $output Raw output of `ipconfig /all`.
     */
    public static function parse(string $output): Settings
    {
        $nameservers = [];
        $seenNameservers = [];

        $searchDomains = self::extractSearchList($output);
        $adapterBlocks = self::splitAdapterBlocks($output);
        foreach ($adapterBlocks as $block) {
            if (self::isDisconnected($block)) {
                continue;
            }

            foreach (self::extractDnsServers($block) as $server) {
                if (isset($seenNameservers[$server])) {
                    continue;
                }

                $seenNameservers[$server] = true;
                $nameservers[] = new Nameserver($server);
            }

            $suffix = self::extractConnectionSuffix($block);
            if ($suffix !== null && !in_array($suffix, $searchDomains, true)) {
                $searchDomains[] = $suffix;
            }
        }

        return new Settings($nameservers, $searchDomains);
    }

    /**
     * @return list<string>
     */
    private static function extractSearchList(string $output): array
    {
        $domains = [];
        $matches = null;

        if (!preg_match('/DNS Suffix Search List[. ]*:[^\S\n]*(\S+)/i', $output, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $domains[] = trim($matches[1][0], self::WHITESPACE);
        $pos = (int) $matches[0][1] + strlen($matches[0][0]);
        $remaining = substr($output, $pos);
        $lines = explode("\n", $remaining);
        $started = false;
        foreach ($lines as $line) {
            $trimmed = trim($line, self::WHITESPACE);
            if ($trimmed === '') {
                if ($started) {
                    break;
                }

                continue;
            }

            if (preg_match('/^\S+$/', $trimmed) && str_contains($trimmed, '.') && !str_contains($trimmed, ':')) {
                $domains[] = $trimmed;
                $started = true;
            } else {
                break;
            }
        }

        return array_values($domains);
    }

    /**
     * @return list<string>
     */
    private static function splitAdapterBlocks(string $output): array
    {
        $blocks = preg_split('/\n(?=\S.*adapter\s)/i', $output);
        if (false === $blocks) {
            return [];
        }

        $result = [];
        foreach ($blocks as $block) {
            if (!preg_match('/adapter\s/i', $block)) {
                continue;
            }

            $result[] = $block;
        }

        return $result;
    }

    /**
     * Check whether the adapter block reports a disconnected media state.
     */
    private static function isDisconnected(string $block): bool
    {
        return (bool) preg_match('/Media State.*disconnected/i', $block);
    }

    /**
     * @return list<non-empty-string>
     */
    private static function extractDnsServers(string $block): array
    {
        $servers = [];
        $matches = null;
        if (!preg_match('/^\s+DNS Servers[. ]*:[^\S\n]*(\S+)/im', $block, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $server = trim($matches[1][0], self::WHITESPACE);
        if (self::isIpAddress($server)) {
            $servers[] = $server;
        }

        $pos = (int) $matches[0][1] + strlen($matches[0][0]);
        $remaining = substr($block, $pos);
        $lines = explode("\n", $remaining);
        $started = false;
        foreach ($lines as $line) {
            $trimmed = trim($line, self::WHITESPACE);

            if ($trimmed === '') {
                if ($started) {
                    break;
                }

                continue;
            }

            if (self::isIpAddress($trimmed)) {
                $servers[] = $trimmed;
                $started = true;
            } else {
                if ($started) {
                    break;
                }
            }
        }

        return array_values($servers);
    }

    /**
     * Extract the connection-specific DNS suffix from an adapter block.
     */
    private static function extractConnectionSuffix(string $block): null|string
    {
        $matches = null;
        if (preg_match('/Connection-specific DNS Suffix[. ]*:[^\S\n]*(\S+)/i', $block, $matches)) {
            $suffix = trim($matches[1], self::WHITESPACE);
            if ($suffix !== '') {
                return $suffix;
            }
        }

        return null;
    }

    /**
     * Check whether the given value looks like an IPv4 or IPv6 address.
     *
     * @psalm-assert =non-empty-string $value
     */
    private static function isIpAddress(string $value): bool
    {
        if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $value)) {
            return true;
        }

        if (str_contains($value, ':') && preg_match('/^[0-9a-fA-F:]+(%\S+)?$/', $value)) {
            return true;
        }

        return false;
    }
}
