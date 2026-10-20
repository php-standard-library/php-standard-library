<?php

declare(strict_types=1);

namespace Psl\Terminal\Internal;

use Psl\Terminal\Event;

use function chr;
use function count;
use function explode;
use function ord;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

/**
 * Parses raw stdin bytes into terminal Event objects.
 *
 * Handles ANSI escape sequences for keys, mouse (SGR format), and bracketed paste.
 *
 * @internal
 */
final class EventParser
{
    private string $buffer = '';
    private bool $inPaste = false;
    private string $pasteBuffer = '';

    /**
     * Whether the buffer held an incomplete UTF-8 character at the last
     * {@see flushPending()} call, with no input fed since.
     */
    private bool $incompleteCharacterFlushed = false;

    private const string PASTE_START = "\e[200~";
    private const string PASTE_END = "\e[201~";
    private const int PASTE_START_LEN = 6;
    private const int PASTE_END_LEN = 6;

    /**
     * Flush any pending incomplete sequences as final events.
     *
     * Call this periodically (e.g. on each render tick) to resolve
     * ambiguous input. A lone ESC byte that hasn't been completed by
     * subsequent bytes will be emitted as an Escape key event.
     *
     * A UTF-8 character can be split across two reads, so its leading bytes
     * are kept through one flush; they are discarded by the next flush if
     * no input arrived in between.
     *
     * @return list<Event\Key>
     */
    public function flushPending(): array
    {
        if ($this->buffer === "\e") {
            $this->buffer = '';
            return [Event\Key::named('escape')];
        }

        // feed() only leaves a buffer starting with a non-ASCII byte behind when it
        // holds the valid prefix of a UTF-8 character still waiting for its remaining bytes.
        if ($this->buffer !== '' && ord($this->buffer[0]) >= 0x80) {
            if (!$this->incompleteCharacterFlushed) {
                $this->incompleteCharacterFlushed = true;

                return [];
            }

            $this->buffer = '';
            $this->incompleteCharacterFlushed = false;
        }

        return [];
    }

    /**
     * Feed raw bytes from stdin and return parsed events.
     *
     * @return list<Event\Key|Event\Mouse|Event\Paste|Event\Resize|Event\Focus>
     */
    public function feed(string $data): array
    {
        $this->incompleteCharacterFlushed = false;
        $this->buffer .= $data;
        $events = [];

        while ($this->buffer !== '') {
            $event = $this->parseNext();
            if ($event === false) {
                break;
            }

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Parse the next event from the buffer.
     *
     * @return Event\Key|Event\Mouse|Event\Paste|Event\Resize|Event\Focus|null|false null if byte was skipped, false if incomplete
     */
    private function parseNext(): Event\Key|Event\Mouse|Event\Paste|Event\Resize|Event\Focus|null|false
    {
        if ($this->inPaste) {
            return $this->parsePasteContent();
        }

        if (str_starts_with($this->buffer, self::PASTE_START)) {
            $this->buffer = substr($this->buffer, self::PASTE_START_LEN);
            $this->inPaste = true;
            return null;
        }

        if (self::PASTE_START_LEN > strlen($this->buffer) && str_starts_with(self::PASTE_START, $this->buffer)) {
            return false;
        }

        if ($this->buffer[0] === "\e") {
            return $this->parseEscapeSequence();
        }

        return $this->parseSingleByte();
    }

    private function parsePasteContent(): Event\Paste|null|false
    {
        $endPos = strpos($this->buffer, self::PASTE_END);
        if ($endPos === false) {
            $this->pasteBuffer .= $this->buffer;
            $this->buffer = '';
            return false;
        }

        $this->pasteBuffer .= substr($this->buffer, 0, $endPos);
        $this->buffer = substr($this->buffer, $endPos + self::PASTE_END_LEN);
        $result = new Event\Paste($this->pasteBuffer);
        $this->pasteBuffer = '';
        $this->inPaste = false;
        return $result;
    }

    private function parseSingleByte(): Event\Key|null|false
    {
        $byte = $this->buffer[0];
        $ord = ord($byte);
        if ($ord >= 0x80) {
            return $this->parseUtf8();
        }

        $this->buffer = substr($this->buffer, 1);

        return match (true) {
            $ord === 0x0D => Event\Key::named('enter'),
            $ord === 0x09 => Event\Key::named('tab'),
            $ord === 0x7F, $ord === 0x08 => Event\Key::named('backspace'),
            $ord >= 0x01 && $ord <= 0x1A => self::controlCharKey($ord),
            $ord === 0x00 => Event\Key::named('ctrl+space'),
            $ord >= 0x20 => Event\Key::char($byte),
            default => null,
        };
    }

    private static function controlCharKey(int $ord): Event\Key
    {
        $letter = chr($ord + 0x60);
        return Event\Key::named('ctrl+' . $letter);
    }

    /**
     * Decode the UTF-8 character at the start of the buffer.
     *
     * Ill-formed bytes (see {@see Utf8Sequence::measure()}) are dropped. The
     * byte that ended an ill-formed sequence is left in the buffer to be
     * parsed on its own, so a key or escape sequence following a broken
     * character is never swallowed.
     *
     * @return Event\Key|null|false null if ill-formed bytes were dropped, false if the rest of the character has not been received yet
     */
    private function parseUtf8(): Event\Key|null|false
    {
        $buffer = $this->buffer;
        $length = Utf8Sequence::measure($buffer);
        if ($length === 0) {
            return false;
        }

        if ($length < 0) {
            $this->buffer = substr($buffer, -$length);
            return null;
        }

        $this->buffer = substr($buffer, $length);

        return Event\Key::char(substr($buffer, 0, $length));
    }

    /**
     * @return Event\Key|Event\Mouse|Event\Resize|Event\Focus|null|false
     */
    private function parseEscapeSequence(): Event\Key|Event\Mouse|Event\Resize|Event\Focus|null|false
    {
        if (strlen($this->buffer) < 2) {
            return false;
        }

        $second = $this->buffer[1];

        if ($second === '[') {
            return $this->parseCsiSequence();
        }

        if (ord($second) >= 0x20 && ord($second) <= 0x7E) {
            $this->buffer = substr($this->buffer, 2);
            return Event\Key::named('alt+' . $second);
        }

        $this->buffer = substr($this->buffer, 1);
        return Event\Key::named('escape');
    }

    /**
     * @return Event\Key|Event\Mouse|Event\Resize|Event\Focus|null|false
     */
    private function parseCsiSequence(): Event\Key|Event\Mouse|Event\Resize|Event\Focus|null|false
    {
        $len = strlen($this->buffer);

        for ($i = 2; $i < $len; $i++) {
            $c = $this->buffer[$i];

            if ($i === 2 && $c === '<') {
                return $this->parseSgrMouse();
            }

            if ($c >= 'A' && $c <= 'Z' || $c === '~' || $c >= 'a' && $c <= 'z') {
                $paramLen = $i - 2;
                $params = substr($this->buffer, 2, $paramLen);
                $this->buffer = substr($this->buffer, $i + 1);

                if ($params === '' && $c === 'I') {
                    return new Event\Focus(true);
                }

                if ($params === '' && $c === 'O') {
                    return new Event\Focus(false);
                }

                // In-band resize: \e[48;rows;cols;height_px;width_px t
                if ($c === 't') {
                    $resize = self::parseInBandResize($params);
                    if ($resize !== null) {
                        return $resize;
                    }
                }

                return CsiKeyMap::map($params, $c);
            }
        }

        return false;
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

    /**
     * @return Event\Mouse|null|false
     */
    private function parseSgrMouse(): Event\Mouse|null|false
    {
        $len = strlen($this->buffer);

        for ($i = 3; $i < $len; $i++) {
            $c = $this->buffer[$i];
            if ($c === 'M' || $c === 'm') {
                $paramLen = $i - 3;
                $params = substr($this->buffer, 3, $paramLen);
                $isRelease = $c === 'm';
                $this->buffer = substr($this->buffer, $i + 1);

                return SgrMouseParser::parse($params, $isRelease);
            }
        }

        return false;
    }
}
