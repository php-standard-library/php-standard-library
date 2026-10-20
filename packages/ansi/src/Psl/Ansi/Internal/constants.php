<?php

declare(strict_types=1);

namespace Psl\Ansi\Internal;

/**
 * Pattern matching one 7-bit ECMA-48 escape sequence.
 *
 * After ESC, the alternatives are:
 *  - a control sequence: `[`, parameter bytes 0x30-0x3F, intermediate bytes
 *    0x20-0x2F and a final byte 0x40-0x7E;
 *  - a control string (OSC `]`, DCS `P`, SOS `X`, PM `^`, APC `_`) terminated
 *    by BEL or ST;
 *  - a single shift (SS2 `N`, SS3 `O`) and the character it applies to;
 *  - any other escape sequence: intermediate bytes 0x20-0x2F and a final
 *    byte 0x30-0x7E, or a final byte alone, excluding the bytes that start
 *    the sequences above.
 *
 * Shared by {@see \Psl\Ansi\strip()} and {@see \Psl\Ansi\contains()}.
 *
 * @internal
 */
const ESCAPE_SEQUENCE_PATTERN = '/\e(?:\[[\x30-\x3F]*[\x20-\x2F]*[\x40-\x7E]|[\]PX^_][^\x07\e]*(?:\x07|\e\\\\)|[NO][\x20-\x7E]|[\x20-\x2F]+[\x30-\x7E]|[\x30-\x4D\x51-\x57\x59\x5A\x5C\x60-\x7E])/';
