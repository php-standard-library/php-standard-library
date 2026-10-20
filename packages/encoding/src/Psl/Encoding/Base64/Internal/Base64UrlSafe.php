<?php

declare(strict_types=1);

namespace Psl\Encoding\Base64\Internal;

use Override;
use Psl\Encoding\Exception;

use function pack;
use function preg_match;

/**
 * The following class was derived from code of Paragon Initiative Enterprises.
 *
 * https://github.com/paragonie/constant_time_encoding/blob/198317fa6db951dd791be0740915dae878f34b3a/src/Base64UrlSafe.php
 *
 * Code subject to MIT License (https://github.com/paragonie/constant_time_encoding/blob/198317fa6db951dd791be0740915dae878f34b3a/LICENSE.txt)
 *
 * Copyright (c) 2016 - 2022 Paragon Initiative Enterprises
 *
 * @internal
 */
final class Base64UrlSafe extends Base64
{
    /**
     * @throws Exception\RangeException
     *
     * @pure
     */
    #[Override]
    protected static function checkRange(string $base64): void
    {
        if (preg_match('%^[a-zA-Z0-9-_]*={0,2}$%D', $base64) !== 1) {
            throw new Exception\RangeException(
                'The given string contains characters outside the base64 range for the current variant.',
            );
        }
    }

    /**
     * Uses bitwise operators instead of table-lookups to turn 8-bit integers
     * into 6-bit integers.
     *
     * @pure
     */
    #[Override]
    protected static function encode6Bits(int $bin): string
    {
        $diff = 0x41;
        // if ($bin > 25) $diff += 0x61 - 0x41 - 26; // 6
        $diff += ((25 - $bin) >> 8) & 6;
        // if ($bin > 51) $diff += 0x30 - 0x61 - 26; // -75
        $diff -= ((51 - $bin) >> 8) & 75;
        // if ($bin > 61) $diff += 0x2d - 0x30 - 10; // -13
        $diff -= ((61 - $bin) >> 8) & 13;
        // if ($bin > 62) $diff += 0x5f - 0x2b - 1; // 3
        $diff += ((62 - $bin) >> 8) & 49;

        return pack('C', $bin + $diff);
    }

    /**
     * Uses bitwise operators instead of table-lookups to turn 6-bit integers
     * into 8-bit integers.
     *
     * @pure
     */
    #[Override]
    protected static function decode6Bits(int $base64): int
    {
        $ret = -1;
        // if ($base64 > 0x40 && $base64 < 0x5b) $ret += $base64 - 0x41 + 1; // -64
        $ret += (((0x40 - $base64) & ($base64 - 0x5b)) >> 8) & ($base64 - 64);
        // if ($base64 > 0x60 && $base64 < 0x7b) $ret += $base64 - 0x61 + 26 + 1; // -70
        $ret += (((0x60 - $base64) & ($base64 - 0x7b)) >> 8) & ($base64 - 70);
        // if ($base64 > 0x2f && $base64 < 0x3a) $ret += $base64 - 0x30 + 52 + 1; // 5
        $ret += (((0x2f - $base64) & ($base64 - 0x3a)) >> 8) & ($base64 + 5);
        // if ($base64 == 0x2c) $ret += 62 + 1;
        $ret += (((0x2c - $base64) & ($base64 - 0x2e)) >> 8) & 63;
        // if ($base64 == 0x5f) $ret += 63 + 1;
        $ret += (((0x5e - $base64) & ($base64 - 0x60)) >> 8) & 64;

        return $ret;
    }
}
