<?php

declare(strict_types=1);

namespace Psl\Terminal\Internal;

use Psl\Terminal\Event;

use function chr;
use function count;
use function explode;
use function str_contains;

/**
 * Maps CSI and SS3 escape sequences to key events.
 *
 * @internal
 */
final class CsiKeyMap
{
    private function __construct() {}

    /**
     * Map a CSI sequence (params + final byte) to a Key event.
     */
    public static function map(string $params, string $final): null|Event\Key
    {
        if ($params === '') {
            return self::mapSimple($final);
        }

        if ($final === '~') {
            return self::mapTilde($params);
        }

        // Kitty keyboard protocol: \e[codepoint u (no modifier)
        if ($final === 'u' && !str_contains($params, ';')) {
            return self::mapKittyKey((int) $params, 1);
        }

        return self::mapModified($params, $final);
    }

    private static function mapSimple(string $final): null|Event\Key
    {
        return match ($final) {
            'A' => Event\Key::named('up'),
            'B' => Event\Key::named('down'),
            'C' => Event\Key::named('right'),
            'D' => Event\Key::named('left'),
            'H' => Event\Key::named('home'),
            'F' => Event\Key::named('end'),
            'Z' => Event\Key::named('shift+tab'),
            default => null,
        };
    }

    private static function mapTilde(string $params): null|Event\Key
    {
        // Simple tilde keys
        $simple = match ($params) {
            '1' => 'home',
            '2' => 'insert',
            '3' => 'delete',
            '4' => 'end',
            '5' => 'page_up',
            '6' => 'page_down',
            '15' => 'f5',
            '17' => 'f6',
            '18' => 'f7',
            '19' => 'f8',
            '20' => 'f9',
            '21' => 'f10',
            '23' => 'f11',
            '24' => 'f12',
            default => null,
        };

        if ($simple !== null) {
            return Event\Key::named($simple);
        }

        // Modified tilde keys: e.g., "5;5" for Ctrl+PageUp
        $parts = explode(';', $params);
        if (count($parts) !== 2) {
            return null;
        }

        $baseKey = match ($parts[0]) {
            '5' => 'page_up',
            '6' => 'page_down',
            '1' => 'home',
            '4' => 'end',
            '2' => 'insert',
            '3' => 'delete',
            default => null,
        };

        if ($baseKey === null) {
            return null;
        }

        return Event\Key::named(self::modifierPrefix((int) $parts[1]) . $baseKey);
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

    private static function mapModified(string $params, string $final): null|Event\Key
    {
        $parts = explode(';', $params);
        if (count($parts) !== 2) {
            return null;
        }

        // Kitty keyboard protocol: \e[codepoint;modifiers u
        if ($final === 'u') {
            return self::mapKittyKey((int) $parts[0], (int) $parts[1]);
        }

        $baseKey = match ($final) {
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
            default => null,
        };

        if ($baseKey === null) {
            return null;
        }

        return Event\Key::named(self::modifierPrefix((int) $parts[1]) . $baseKey);
    }

    private static function mapKittyKey(int $codepoint, int $modifier): null|Event\Key
    {
        $prefix = self::modifierPrefix($modifier);

        $baseKey = match ($codepoint) {
            13 => 'enter',
            9 => 'tab',
            27 => 'escape',
            127 => 'backspace',
            default => null,
        };

        if ($baseKey !== null) {
            return Event\Key::named($prefix . $baseKey);
        }

        // Printable character
        if ($codepoint >= 32 && $codepoint <= 126) {
            $char = chr($codepoint);
            if ($prefix === '') {
                return Event\Key::char($char);
            }

            return Event\Key::named($prefix . $char);
        }

        return null;
    }

    private static function modifierPrefix(int $modifier): string
    {
        return match ($modifier) {
            2 => 'shift+',
            3 => 'alt+',
            4 => 'shift+alt+',
            5 => 'ctrl+',
            6 => 'ctrl+shift+',
            7 => 'ctrl+alt+',
            8 => 'ctrl+shift+alt+',
            default => '',
        };
    }
}
