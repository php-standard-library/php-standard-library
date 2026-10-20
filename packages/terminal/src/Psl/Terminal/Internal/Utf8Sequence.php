<?php

declare(strict_types=1);

namespace Psl\Terminal\Internal;

use function ord;
use function strlen;

/**
 * Validates the UTF-8 encoded character at the start of a byte string.
 *
 * @internal
 */
final class Utf8Sequence
{
    private function __construct() {}

    /**
     * Measure the UTF-8 character at the start of the given bytes.
     *
     * Only the well-formed byte sequences of Unicode Table 3-7 are accepted,
     * so overlong forms, surrogates and code points above U+10FFFF are
     * ill-formed.
     *
     * Returns:
     *  - the length of the character, if it is complete and well-formed;
     *  - 0, if the bytes are empty or a well-formed but incomplete prefix of
     *    a character;
     *  - the number of ill-formed bytes, negated, otherwise: a single byte that
     *    cannot start a character, or a lead byte and the continuation bytes
     *    that follow it up to, but not including, the first byte that is not
     *    valid at its position. That byte may start the next character or
     *    sequence.
     */
    public static function measure(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }

        $lead = ord($bytes[0]);
        [$needed, $min, $max] = match (true) {
            $lead >= 0xC2 && $lead <= 0xDF => [1, 0x80, 0xBF],
            $lead === 0xE0 => [2, 0xA0, 0xBF],
            $lead === 0xED => [2, 0x80, 0x9F],
            $lead >= 0xE1 && $lead <= 0xEF => [2, 0x80, 0xBF],
            $lead === 0xF0 => [3, 0x90, 0xBF],
            $lead === 0xF4 => [3, 0x80, 0x8F],
            $lead >= 0xF1 && $lead <= 0xF3 => [3, 0x80, 0xBF],
            default => [0, 0, 0],
        };

        if ($needed === 0) {
            return -1;
        }

        $length = strlen($bytes);
        for ($i = 1; $i <= $needed; $i++) {
            if ($i >= $length) {
                return 0;
            }

            $byte = ord($bytes[$i]);
            $valid = $i === 1 ? $byte >= $min && $byte <= $max : ($byte & 0xC0) === 0x80;
            if (!$valid) {
                return -$i;
            }
        }

        return $needed + 1;
    }
}
