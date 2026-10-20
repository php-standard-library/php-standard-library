<?php

declare(strict_types=1);

namespace Psl\Terminal\Internal;

use Psl\Terminal\Event;

use function count;
use function explode;
use function str_starts_with;
use function substr;

/**
 * Maps CSI sequences to terminal events.
 *
 * @internal
 */
final class CsiEventMap
{
    private function __construct() {}

    /**
     * Map a CSI sequence (params + final byte) to an event.
     *
     * Handles focus reports, SGR mouse reports and in-band resize
     * notifications; everything else is mapped by {@see CsiKeyMap::map()}.
     *
     * @return Event\Key|Event\Mouse|Event\Resize|Event\Focus|null
     */
    public static function map(string $params, string $final): Event\Key|Event\Mouse|Event\Resize|Event\Focus|null
    {
        if ($params === '' && $final === 'I') {
            return new Event\Focus(true);
        }

        if ($params === '' && $final === 'O') {
            return new Event\Focus(false);
        }

        // SGR mouse: \e[<button;col;row M (press) or m (release)
        if (($final === 'M' || $final === 'm') && str_starts_with($params, '<')) {
            return SgrMouseParser::parse(substr($params, 1), $final === 'm');
        }

        // In-band resize: \e[48;rows;cols;height_px;width_px t
        if ($final === 't') {
            $resize = self::parseInBandResize($params);
            if ($resize !== null) {
                return $resize;
            }
        }

        return CsiKeyMap::map($params, $final);
    }

    /**
     * Parse in-band resize notification (mode 2048).
     *
     * Format: \e[48;rows;cols;height_px;width_px t
     */
    private static function parseInBandResize(string $params): null|Event\Resize
    {
        $parts = explode(';', $params);
        if (count($parts) < 3 || $parts[0] !== '48') {
            return null;
        }

        $rows = (int) $parts[1];
        $cols = (int) $parts[2];
        if ($cols <= 0 || $rows <= 0) {
            return null;
        }

        return new Event\Resize($cols, $rows);
    }
}
