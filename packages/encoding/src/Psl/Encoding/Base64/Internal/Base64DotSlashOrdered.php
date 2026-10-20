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
 * https://github.com/paragonie/constant_time_encoding/blob/198317fa6db951dd791be0740915dae878f34b3a/src/Base64DotSlashOrdered.php
 *
 * Code subject to MIT License (https://github.com/paragonie/constant_time_encoding/blob/198317fa6db951dd791be0740915dae878f34b3a/LICENSE.txt)
 *
 * Copyright (c) 2016 - 2022 Paragon Initiative Enterprises
 *
 * @internal
 */
final class Base64DotSlashOrdered extends Base64
{
    /**
     * @throws Exception\RangeException
     *
     * @pure
     */
    #[Override]
    protected static function checkRange(string $base64): void
    {
        if (preg_match('%^[a-zA-Z.-9]*={0,2}$%D', $base64) !== 1) {
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
        $bin += 0x2e;
        // if ($bin > 0x39) $bin += 0x41 - 0x3a; // 7
        $bin += ((0x39 - $bin) >> 8) & 7;
        // if ($bin > 0x5a) $bin += 0x61 - 0x5b; // 6
        $bin += ((0x5a - $bin) >> 8) & 6;

        return pack('C', $bin);
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
        // if ($base64 > 0x2d && $base64 < 0x3a) ret += $base64 - 0x2e + 1; // -45
        $ret += (((0x2d - $base64) & ($base64 - 0x3a)) >> 8) & ($base64 - 45);
        // if ($base64 > 0x40 && $base64 < 0x5b) ret += $base64 - 0x41 + 12 + 1; // -52
        $ret += (((0x40 - $base64) & ($base64 - 0x5b)) >> 8) & ($base64 - 52);
        // if ($base64 > 0x60 && $base64 < 0x7b) ret += $base64 - 0x61 + 38 + 1; // -58
        $ret += (((0x60 - $base64) & ($base64 - 0x7b)) >> 8) & ($base64 - 58);

        return $ret;
    }
}
