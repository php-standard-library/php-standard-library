<?php

declare(strict_types=1);

namespace Psl\Terminal\Internal;

use Psl\Terminal\Event;

use function count;
use function explode;
use function mb_chr;
use function str_contains;
use function strlen;
use function strspn;

/**
 * Maps CSI and SS3 escape sequences to key events.
 *
 * @internal
 */
final class CsiKeyMap
{
    /**
     * Keys sent as `CSI number [; modifiers] ~`, by number.
     *
     * 7/8 (Home/End) and 11-14 (F1-F4) are the rxvt forms, also used by the
     * kitty keyboard protocol.
     */
    private const array TILDE_KEYS = [
        '1' => 'home',
        '2' => 'insert',
        '3' => 'delete',
        '4' => 'end',
        '5' => 'page_up',
        '6' => 'page_down',
        '7' => 'home',
        '8' => 'end',
        '11' => 'f1',
        '12' => 'f2',
        '13' => 'f3',
        '14' => 'f4',
        '15' => 'f5',
        '17' => 'f6',
        '18' => 'f7',
        '19' => 'f8',
        '20' => 'f9',
        '21' => 'f10',
        '23' => 'f11',
        '24' => 'f12',
    ];

    /**
     * Keys sent as `CSI [1 ; modifiers] letter`, by final byte.
     */
    private const array LETTER_KEYS = [
        'A' => 'up',
        'B' => 'down',
        'C' => 'right',
        'D' => 'left',
        'H' => 'home',
        'F' => 'end',
        'P' => 'f1',
        'Q' => 'f2',
        'R' => 'f3',
        'S' => 'f4',
    ];

    /**
     * Kitty keyboard protocol key codes (`CSI code u`) of keys that have a name.
     *
     * Codes from 57344 (U+E000) on are the functional keys the protocol
     * assigns to the Unicode Private Use Area; the keypad keys are mapped to
     * the keys they stand for.
     */
    private const array KITTY_NAMED_KEYS = [
        9 => 'tab',
        13 => 'enter',
        27 => 'escape',
        127 => 'backspace',
        57_414 => 'enter',
        57_417 => 'left',
        57_418 => 'right',
        57_419 => 'up',
        57_420 => 'down',
        57_421 => 'page_up',
        57_422 => 'page_down',
        57_423 => 'home',
        57_424 => 'end',
        57_425 => 'insert',
        57_426 => 'delete',
    ];

    /**
     * Kitty keyboard protocol key codes of the keypad keys that produce text.
     */
    private const array KITTY_KEYPAD_CHARS = [
        57_399 => '0',
        57_400 => '1',
        57_401 => '2',
        57_402 => '3',
        57_403 => '4',
        57_404 => '5',
        57_405 => '6',
        57_406 => '7',
        57_407 => '8',
        57_408 => '9',
        57_409 => '.',
        57_410 => '/',
        57_411 => '*',
        57_412 => '-',
        57_413 => '+',
        57_415 => '=',
        57_416 => ',',
    ];

    /**
     * Kitty keyboard protocol key codes of F1 and F35; the codes in between are F2-F34.
     */
    private const int KITTY_F1 = 57_364;
    private const int KITTY_F35 = 57_398;

    /**
     * Kitty keyboard protocol event type of a key release.
     */
    private const string KITTY_RELEASE = '3';

    private function __construct() {}

    /**
     * Map a CSI sequence (params + final byte) to a Key event.
     *
     * Handles the legacy encodings (`CSI letter`, `CSI 1 ; modifiers letter`,
     * `CSI number [; modifiers] ~`) and the kitty keyboard protocol
     * (`CSI code[:alternates] [; modifiers[:event-type] [; text]] u`). The
     * kitty event type also applies to the legacy forms: key releases are
     * dropped, since a Key event is a key press; repeats are reported as
     * presses, like the auto-repeat of a legacy terminal.
     *
     * @see https://sw.kovidgoyal.net/kitty/keyboard-protocol/
     */
    public static function map(string $params, string $final): null|Event\Key
    {
        if ($params === '' && $final === 'Z') {
            return Event\Key::named('shift+tab');
        }

        // Key sequences only carry digits, ';' and ':'. Anything else, e.g. the
        // '?' of a kitty keyboard flags reply (CSI ? flags u), is not a key.
        if (strspn($params, '0123456789;:') !== strlen($params)) {
            return null;
        }

        $fields = explode(';', $params);
        if (count($fields) > ($final === 'u' ? 3 : 2)) {
            return null;
        }

        $modifiers = explode(':', $fields[1] ?? '');
        if (($modifiers[1] ?? '') === self::KITTY_RELEASE) {
            return null;
        }

        $prefix = self::modifierPrefix((int) $modifiers[0]);
        // The kitty protocol may append the shifted and base keys as sub-parameters.
        $number = explode(':', $fields[0])[0];

        if ($final === 'u') {
            return self::mapKittyKey((int) $number, $prefix);
        }

        $name = null;
        if ($final === '~') {
            $name = self::TILDE_KEYS[$number] ?? null;
        } elseif ($number === '' || count($fields) === 2) {
            $name = self::LETTER_KEYS[$final] ?? null;
        }

        return $name === null ? null : Event\Key::named($prefix . $name);
    }

    /**
     * Map the final byte of an SS3 sequence (ESC O final) to a Key event.
     *
     * Covers F1-F4, the cursor keys in application cursor mode (DECCKM), the
     * keypad in application keypad mode (DECKPAM), and rxvt's Ctrl+arrow keys.
     */
    public static function mapSs3(string $final): null|Event\Key
    {
        $name = match ($final) {
            'A' => 'up',
            'B' => 'down',
            'C' => 'right',
            'D' => 'left',
            'H' => 'home',
            'F' => 'end',
            'P' => 'f1',
            'Q' => 'f2',
            'R' => 'f3',
            'S' => 'f4',
            'I' => 'tab',
            'M' => 'enter',
            'a' => 'ctrl+up',
            'b' => 'ctrl+down',
            'c' => 'ctrl+right',
            'd' => 'ctrl+left',
            default => null,
        };

        if ($name !== null) {
            return Event\Key::named($name);
        }

        $char = match ($final) {
            'j' => '*',
            'k' => '+',
            'l' => ',',
            'm' => '-',
            'n' => '.',
            'o' => '/',
            'p' => '0',
            'q' => '1',
            'r' => '2',
            's' => '3',
            't' => '4',
            'u' => '5',
            'v' => '6',
            'w' => '7',
            'x' => '8',
            'y' => '9',
            'X' => '=',
            default => null,
        };

        return $char === null ? null : Event\Key::char($char);
    }

    private static function mapKittyKey(int $code, string $prefix): null|Event\Key
    {
        $name = self::KITTY_NAMED_KEYS[$code] ?? null;
        if ($code >= self::KITTY_F1 && $code <= self::KITTY_F35) {
            $name = 'f' . (string) ($code - self::KITTY_F1 + 1);
        }

        // Match the legacy encoding of Ctrl+Space (NUL), which is reported as "ctrl+space".
        // Other modifiers keep the space character, like the legacy Alt+Space (ESC space).
        if ($code === 32 && str_contains($prefix, 'ctrl+')) {
            $name = 'space';
        }

        if ($name !== null) {
            return Event\Key::named($prefix . $name);
        }

        $char = self::KITTY_KEYPAD_CHARS[$code] ?? self::textKeyChar($code);
        if ($char === null) {
            return null;
        }

        return $prefix === '' ? Event\Key::char($char) : Event\Key::named($prefix . $char);
    }

    /**
     * Encode the Unicode code point of a kitty text key as UTF-8.
     *
     * Returns null for code points that are not printable characters: C0 and
     * C1 controls, DEL, surrogates, code points above U+10FFFF, and the
     * Private Use Area block the kitty protocol reserves for functional keys
     * (U+E000-U+F8FF), so unmapped functional keys (media, lock and modifier
     * keys) are dropped instead of being reported as characters.
     */
    private static function textKeyChar(int $code): null|string
    {
        if ($code < 0x20 || $code >= 0x7F && $code <= 0x9F || $code >= 0xE000 && $code <= 0xF8FF) {
            return null;
        }

        $char = mb_chr($code, 'UTF-8');

        return $char === false ? null : $char;
    }

    /**
     * Build the key name prefix for a modifier parameter.
     *
     * The parameter is 1 plus a bit mask of the modifiers, named as in the
     * kitty keyboard protocol: shift (1), alt (2), ctrl (4), super (8),
     * hyper (16) and meta (32). xterm sends the 8 bit for its Meta modifier.
     * The Caps Lock (64) and Num Lock (128) bits are ignored, since they do
     * not change which key combination was pressed.
     */
    private static function modifierPrefix(int $modifier): string
    {
        if ($modifier < 2) {
            return '';
        }

        $bits = $modifier - 1;
        $prefix = match ($bits & 0b111) {
            0b001 => 'shift+',
            0b010 => 'alt+',
            0b011 => 'shift+alt+',
            0b100 => 'ctrl+',
            0b101 => 'ctrl+shift+',
            0b110 => 'ctrl+alt+',
            0b111 => 'ctrl+shift+alt+',
            default => '',
        };

        if (($bits & 0b00_1000) !== 0) {
            $prefix .= 'super+';
        }

        if (($bits & 0b01_0000) !== 0) {
            $prefix .= 'hyper+';
        }

        if (($bits & 0b10_0000) !== 0) {
            $prefix .= 'meta+';
        }

        return $prefix;
    }
}
