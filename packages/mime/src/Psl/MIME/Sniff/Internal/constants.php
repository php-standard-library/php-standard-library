<?php

declare(strict_types=1);

namespace Psl\MIME\Sniff\Internal;

/**
 * Maximum number of bytes read from a handle for MIME sniffing.
 *
 * Used by {@see \Psl\MIME\Sniff\from_handle()} to limit the read size, and by {@see sniff_text()},
 * which treats content of at least this size as possibly truncated (only a prefix of a larger
 * resource) when checking for JSON.
 *
 * @internal
 */
const SNIFF_BUFFER_SIZE = 4096;

/**
 * Magic byte signatures mapped to MIME types.
 *
 * Each entry is a tuple of [byte offset, magic bytes, bitmask (or null for exact match), MIME type string].
 * The special sentinel value `__riff__` triggers {@see sniff_riff()} for RIFF sub-format detection,
 * and `__bmp__` triggers {@see sniff_bmp()}, which confirms the weak two-byte "BM" magic (matching is
 * resumed with the next signature when it does not).
 *
 * Covers images (PNG, GIF, JPEG, BMP, ICO, TIFF), audio/video (MP3, OGG, FLAC, WebM),
 * archives (ZIP, GZIP, BZ2, XZ, Zstd, RAR, 7z), documents (PDF, MS Office, OOXML),
 * fonts (SFNT, OTF, WOFF/WOFF2), executables (ELF, Mach-O, DOS/PE), and other binary
 * formats (SQLite, WebAssembly).
 *
 * @var list<array{int, string, null|string, string}>
 *
 * @internal
 */
const SIGNATURES = [
    // Images
    [0, "\x89PNG\r\n\x1a\n",                null,       'image/png'],
    [0, 'GIF87a',                           null,       'image/gif'],
    [0, 'GIF89a',                           null,       'image/gif'],
    [0, "\xff\xd8\xff",                     null,       'image/jpeg'],
    [0, 'BM',                               null,       '__bmp__'],
    [0, 'RIFF',                             null,       '__riff__'],
    [0, "\x00\x00\x01\x00",                 null,       'image/x-icon'],
    [0, "\x00\x00\x02\x00",                 null,       'image/x-icon'],
    [0, "II\x2a\x00",                       null,       'image/tiff'],
    [0, "MM\x00\x2a",                       null,       'image/tiff'],

    // Audio/Video
    [0, 'ID3',                              null,       'audio/mpeg'],
    [0, "\xff\xfb",                         null,       'audio/mpeg'],
    [0, "\xff\xf3",                         null,       'audio/mpeg'],
    [0, "\xff\xf2",                         null,       'audio/mpeg'],
    [0, "\xff\xe2",                         "\xff\xf6", 'audio/mpeg'],
    [0, 'OggS',                             null,       'audio/ogg'],
    [0, 'fLaC',                             null,       'audio/flac'],
    [0, "\x1a\x45\xdf\xa3",                 null,       'video/webm'],

    // Archives
    [0, "PK\x03\x04",                       null,       'application/zip'],
    [0, "PK\x05\x06",                       null,       'application/zip'],
    [0, "PK\x07\x08",                       null,       'application/zip'],
    [0, "\x1f\x8b",                         null,       'application/gzip'],
    [0, 'BZh',                              null,       'application/x-bzip2'],
    [0, "\xfd\x37\x7a\x58\x5a\x00",         null,       'application/x-xz'],
    [0, "\x28\xb5\x2f\xfd",                 null,       'application/zstd'],
    [0, "Rar!\x1a\x07",                     null,       'application/vnd.rar'],
    [0, "\x37\x7a\xbc\xaf\x27\x1c",         null,       'application/x-7z-compressed'],

    // Documents
    [0, '%PDF',                             null,       'application/pdf'],
    [0, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1", null,       'application/vnd.ms-office'],
    [0, "\x50\x4b\x03\x04\x14\x00\x06\x00", null,       'application/vnd.openxmlformats-officedocument'],

    // Fonts
    [0, "\x00\x01\x00\x00\x00",             null,       'font/sfnt'],
    [0, 'OTTO',                             null,       'font/otf'],
    [0, 'wOFF',                             null,       'font/woff'],
    [0, 'wOF2',                             null,       'font/woff2'],
    [0, "\x01\x00\x00",                     null,       'application/x-font-type1'],

    // Executables
    [0, "\x7fELF",                          null,       'application/x-elf'],
    [0, "\xca\xfe\xba\xbe",                 null,       'application/x-mach-binary'],
    [0, "\xfe\xed\xfa",                     null,       'application/x-mach-binary'],
    [0, "\xcf\xfa\xed\xfe",                 null,       'application/x-mach-binary'],
    [0, 'MZ',                               null,       'application/x-dosexec'],

    // Other binary
    [0, "SQLite format 3\x00",              null,       'application/x-sqlite3'],
    [0, "\x00asm",                          null,       'application/wasm'],
];

/**
 * RIFF container sub-format identifiers mapped to their MIME types.
 *
 * The 4-byte FourCC code at offset 8 in a RIFF file identifies the sub-format.
 * Recognized formats: WebP images, WAV audio, and AVI video.
 *
 * @var array<string, string>
 *
 * @internal
 */
const RIFF_SUBTYPES = [
    'WEBP' => 'image/webp',
    'WAVE' => 'audio/wav',
    'AVI ' => 'video/x-msvideo',
];

/**
 * ISO Base Media File Format (ISO 14496-12) major brand codes mapped to their MIME types.
 *
 * When an ftyp box is detected at byte offset 4, the 4-byte major brand at offset 8
 * is looked up in this map to distinguish MP4 video, MP4/M4A audio, and QuickTime containers.
 *
 * @var array<string, string>
 *
 * @internal
 */
const FTYP_BRANDS = [
    'isom' => 'video/mp4',
    'iso2' => 'video/mp4',
    'mp41' => 'video/mp4',
    'mp42' => 'video/mp4',
    'M4V ' => 'video/mp4',
    'M4A ' => 'audio/mp4',
    'M4B ' => 'audio/mp4',
    'qt  ' => 'video/quicktime',
    'avc1' => 'video/mp4',
    'dash' => 'video/mp4',
];
