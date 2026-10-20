<?php

declare(strict_types=1);

namespace Psl\DNS\System\HostsFile\Internal;

use Psl\DNS\System\HostsFile\HostsFile;
use Psl\IP\Address;
use Psl\IP\Exception;

use function count;
use function explode;
use function preg_split;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Parses hosts file content.
 *
 * @codeCoverageIgnore
 *
 * Format: each line is `<ip> <hostname> [<alias> ...]`
 * Lines starting with `#` are comments. Empty lines are ignored.
 * Inline comments after entries (starting with `#`) are stripped.
 *
 * @internal
 */
final class Parser
{
    /**
     * The whitespace characters matched by `\s` in the patterns below.
     */
    private const string WHITESPACE = " \t\n\r\x0B\x0C";

    /**
     * @param string $content Raw content of a hosts file.
     */
    public static function parse(string $content): HostsFile
    {
        /** @var array<string, list<Address>> $entries */
        $entries = [];

        foreach (explode("\n", $content) as $line) {
            $line = trim($line, self::WHITESPACE);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $commentPos = strpos($line, '#');
            if ($commentPos !== false) {
                $line = trim(substr($line, 0, $commentPos), self::WHITESPACE);
                if ($line === '') {
                    continue;
                }
            }

            /** @var list<string> $parts */
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 2) {
                continue;
            }

            $ip = $parts[0];
            if ('' === $ip) {
                continue;
            }

            try {
                $address = Address::parse($ip);
            } catch (Exception\InvalidArgumentException) {
                continue;
            }

            $count = count($parts);
            for ($i = 1; $i < $count; $i++) {
                $hostname = strtolower(trim($parts[$i], self::WHITESPACE));
                if ($hostname === '') {
                    continue;
                }

                if (!isset($entries[$hostname])) {
                    $entries[$hostname] = [];
                }

                $entries[$hostname][] = $address;
            }
        }

        return new HostsFile($entries);
    }
}
