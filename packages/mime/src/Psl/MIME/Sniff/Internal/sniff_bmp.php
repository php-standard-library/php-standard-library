<?php

declare(strict_types=1);

namespace Psl\MIME\Sniff\Internal;

use function in_array;
use function strlen;
use function substr;
use function unpack;

/**
 * Confirm a "BM" signature by checking the size of the BMP info header that follows the file header.
 *
 * The two-byte "BM" magic alone matches any text that starts with those letters. A BMP file starts
 * with a 14-byte file header followed by a DIB (info) header whose first field, a little-endian
 * 32-bit integer at offset 14, is the size of that header. Only the sizes of the known header
 * versions are accepted: BITMAPCOREHEADER (12), OS/2 BITMAPCOREHEADER2 (16 or 64),
 * BITMAPINFOHEADER (40), BITMAPV2INFOHEADER (52), BITMAPV3INFOHEADER (56), BITMAPV4HEADER (108)
 * and BITMAPV5HEADER (124).
 *
 * Returns `image/bmp`, or null if the content is too short or the header size is unknown.
 *
 * @internal
 */
function sniff_bmp(string $content): null|string
{
    if (strlen($content) < 18) {
        return null;
    }

    /** @var array{1: int} $size */
    $size = unpack('V', substr($content, 14, 4));

    return in_array($size[1], [12, 16, 40, 52, 56, 64, 108, 124], true) ? 'image/bmp' : null;
}
