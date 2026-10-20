<?php

declare(strict_types=1);

namespace Psl\Terminal\Internal;

use Psl\Terminal\Event;

use function count;
use function explode;

/**
 * Parses SGR mouse protocol parameters into Mouse events.
 *
 * @internal
 */
final class SgrMouseParser
{
    private function __construct() {}

    public static function parse(string $params, bool $isRelease): null|Event\Mouse
    {
        $parts = explode(';', $params);
        if (count($parts) !== 3) {
            return null;
        }

        $btn = (int) $parts[0];
        $col = (int) $parts[1] - 1;
        $row = (int) $parts[2] - 1;

        $kind = self::kindFromButton($btn, $isRelease);
        $button = self::buttonFromBits($btn, $kind);

        return new Event\Mouse($kind, $col, $row, $button, new Event\MouseModifiers($btn & 0b1_1100));
    }

    private static function kindFromButton(int $btn, bool $isRelease): Event\MouseKind
    {
        if ($isRelease) {
            return Event\MouseKind::Release;
        }

        if (($btn & 0b10_0000) !== 0) {
            return ($btn & 0b11) === 0b11 ? Event\MouseKind::Move : Event\MouseKind::Drag;
        }

        // Wheel "buttons" 4-7 (64-67): up, down, left, right.
        if (($btn & 0b100_0000) !== 0) {
            return match ($btn & 0b11) {
                0b00 => Event\MouseKind::ScrollUp,
                0b01 => Event\MouseKind::ScrollDown,
                0b10 => Event\MouseKind::ScrollLeft,
                default => Event\MouseKind::ScrollRight,
            };
        }

        return Event\MouseKind::Press;
    }

    private static function buttonFromBits(int $btn, Event\MouseKind $kind): Event\MouseButton
    {
        if (
            $kind === Event\MouseKind::ScrollUp
            || $kind === Event\MouseKind::ScrollDown
            || $kind === Event\MouseKind::ScrollLeft
            || $kind === Event\MouseKind::ScrollRight
        ) {
            return Event\MouseButton::None;
        }

        return match ($btn & 0b11) {
            0b00 => Event\MouseButton::Left,
            0b01 => Event\MouseButton::Middle,
            0b10 => Event\MouseButton::Right,
            default => Event\MouseButton::None,
        };
    }
}
