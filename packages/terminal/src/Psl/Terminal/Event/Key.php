<?php

declare(strict_types=1);

namespace Psl\Terminal\Event;

use Psl\Str;

/**
 * Represents a keyboard event.
 *
 * Key events are key presses (and auto-repeats); key releases reported by
 * the kitty keyboard protocol are not turned into events.
 *
 * @immutable
 *
 * @api
 */
final readonly class Key
{
    /**
     * @param string|null $char The printable character, or null for non-printable keys.
     * @param string $name The key name (e.g., 'enter', 'backspace', 'ctrl+c', 'a', 'A').
     */
    private function __construct(
        public null|string $char,
        public string $name,
    ) {}

    /**
     * Check if this key event matches the given key name.
     *
     * Supports: named keys (enter, backspace, tab, escape, delete), arrow
     * keys (up, down, left, right), modifier combos (ctrl+c, ctrl+up,
     * ctrl+space), function keys (f1-f12, and f13-f35 with the kitty keyboard
     * protocol), and page keys (page_up, page_down, home, end).
     *
     * Modifiers are named in the order ctrl, shift, alt, super, hyper, meta
     * (e.g. "ctrl+shift+up"); super, hyper and meta are only reported by
     * terminals that send them, such as with the kitty keyboard protocol.
     * A plain space is the printable character " ", and space combined with
     * modifiers other than ctrl keeps that character (e.g. "alt+ ").
     */
    public function is(string $key): bool
    {
        return Str\lowercase($this->name) === Str\lowercase($key);
    }

    /**
     * Create a key event for a printable character.
     */
    public static function char(string $char): self
    {
        return new self($char, $char);
    }

    /**
     * Create a key event for a named key (non-printable).
     */
    public static function named(string $name): self
    {
        return new self(null, $name);
    }
}
