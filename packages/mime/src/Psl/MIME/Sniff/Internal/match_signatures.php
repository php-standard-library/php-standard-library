<?php

declare(strict_types=1);

namespace Psl\MIME\Sniff\Internal;

use function ord;
use function strlen;
use function substr;

/**
 * Match the given content against the {@see SIGNATURES} table of known magic byte patterns.
 *
 * Iterates through all registered signatures, comparing bytes at the specified offset
 * either exactly or through a bitmask. When a RIFF signature is matched, delegates
 * to {@see sniff_riff()} for sub-format detection. When the "BM" signature is matched,
 * {@see sniff_bmp()} must confirm it, otherwise matching continues with the next signature.
 *
 * Returns the matched MIME type string, or null if no signature matches.
 *
 * @internal
 */
function match_signatures(string $content): null|string
{
    $length = strlen($content);

    foreach (namespace\SIGNATURES as [$offset, $magic, $mask, $type]) {
        $magicLen = strlen($magic);
        if ($length < ($offset + $magicLen)) {
            continue;
        }

        $chunk = substr($content, $offset, $magicLen);

        if ($mask !== null) {
            $match = true;
            for ($i = 0; $i < $magicLen; $i++) {
                if ((ord($chunk[$i]) & ord($mask[$i])) === ord($magic[$i])) {
                    continue;
                }

                $match = false;
                break;
            }

            if (!$match) {
                continue;
            }
        } elseif ($chunk !== $magic) {
            continue;
        }

        if ($type === '__riff__') {
            return namespace\sniff_riff($content);
        }

        if ($type === '__bmp__') {
            $type = namespace\sniff_bmp($content);
            if ($type === null) {
                continue;
            }
        }

        return $type;
    }

    return null;
}
