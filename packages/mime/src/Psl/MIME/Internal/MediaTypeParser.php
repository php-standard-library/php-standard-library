<?php

declare(strict_types=1);

namespace Psl\MIME\Internal;

use Psl\MIME\Exception\MediaTypeParsingException;
use Psl\MIME\Exception\ParameterParsingException;

use function array_is_list;
use function chr;
use function ctype_xdigit;
use function intval;
use function ksort;
use function mb_convert_encoding;
use function mb_strtolower;
use function ord;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Stateful parser for media type strings per RFC 2045 / RFC 6838.
 *
 * Handles:
 * - Type/subtype extraction with case normalization
 * - Parameter parsing (token and quoted-string values)
 * - RFC 2231 encoded parameters (charset'language'value)
 * - RFC 2231 continuations (param*0, param*1, ...)
 * - Whitespace handling (OWS around `;`)
 *
 * @link https://datatracker.ietf.org/doc/html/rfc2045#section-5.1
 * @link https://datatracker.ietf.org/doc/html/rfc6838#section-4.2
 * @link https://datatracker.ietf.org/doc/html/rfc2231
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class MediaTypeParser
{
    /**
     * The raw media type string being parsed.
     */
    private string $input;

    /**
     * Current byte offset in the input string.
     */
    private int $position;

    /**
     * Total byte length of the input string.
     */
    private int $length;

    /**
     * Initialize the parser with the given input string.
     */
    private function __construct(string $input)
    {
        $this->input = $input;
        $this->position = 0;
        $this->length = strlen($input);
    }

    /**
     * Parse a media type string into its components.
     *
     * @return array{string, string, list<array{string, string}>} [type, subtype, parameters]
     *
     * @throws MediaTypeParsingException If the input is not a valid media type.
     */
    public static function parse(string $input, bool $allowWildcards = false): array
    {
        $parser = new self(trim($input, " \t\r\n"));
        if ($parser->length === 0) {
            throw MediaTypeParsingException::forInvalidMediaType($input);
        }

        $type = $parser->parseToken();
        if ($type === '' || !$parser->consumeChar('/')) {
            throw MediaTypeParsingException::forInvalidMediaType($input);
        }

        $subtype = $parser->parseToken();
        if ($subtype === '') {
            throw MediaTypeParsingException::forInvalidMediaType($input);
        }

        $type = mb_strtolower($type);
        $subtype = mb_strtolower($subtype);

        self::validateType($type, $input, $allowWildcards);
        self::validateSubtype($subtype, $input, $allowWildcards);

        $parser->skipWhitespace();

        /** @var list<array{non-empty-lowercase-string, string}> $parameters */
        $parameters = [];
        while ($parser->consumeChar(';')) {
            $parser->skipWhitespace();

            if ($parser->isAtEnd()) {
                break;
            }

            $param = $parser->parseParameter($input);
            if ($param !== null) {
                $parameters[] = $param;
            }
        }

        if (!$parser->isAtEnd()) {
            throw MediaTypeParsingException::forInvalidMediaType($input);
        }

        $parameters = self::assembleRfc2231Parameters($parameters);

        return [$type, $subtype, $parameters];
    }

    /**
     * Parse only the parameter portion of a media type string.
     *
     * @param string $input Parameter string starting with or without leading `;`.
     *
     * @return list<array{non-empty-lowercase-string, string}>
     *
     * @throws ParameterParsingException If any parameter is malformed.
     */
    public static function parseParameters(string $input): array
    {
        $trimmed = trim($input, " \t\r\n");
        if ($trimmed === '') {
            return [];
        }

        $parser = new self($trimmed);
        $parser->skipWhitespace();

        if ($parser->peek() === ';') {
            $parser->advance();
            $parser->skipWhitespace();
        }

        /** @var list<list{non-empty-lowercase-string, string}> $parameters */
        $parameters = [];
        while (!$parser->isAtEnd()) {
            $param = $parser->parseParameter($input);
            if ($param !== null) {
                $parameters[] = $param;
            }

            $parser->skipWhitespace();

            if (!$parser->consumeChar(';')) {
                break;
            }

            $parser->skipWhitespace();
        }

        if (!$parser->isAtEnd()) {
            throw ParameterParsingException::forInvalidParameter($input);
        }

        return self::assembleRfc2231Parameters($parameters);
    }

    /**
     * Parse a single name=value parameter pair at the current position.
     *
     * @return null|array{non-empty-lowercase-string, string}
     *
     * @throws ParameterParsingException If the parameter name or value is malformed.
     */
    private function parseParameter(string $originalInput): null|array
    {
        $name = $this->parseToken();
        if ($name === '') {
            throw ParameterParsingException::forInvalidParameter($originalInput);
        }

        $name = strtolower($name);
        $this->skipWhitespace();

        if (!$this->consumeChar('=')) {
            throw ParameterParsingException::forInvalidParameter($originalInput);
        }

        $this->skipWhitespace();
        $value = $this->parseParameterValue($originalInput);

        return [$name, $value];
    }

    /**
     * Parse a parameter value, dispatching to quoted-string or token parsing.
     *
     * @throws ParameterParsingException If a quoted-string is malformed.
     */
    private function parseParameterValue(string $originalInput): string
    {
        if ($this->peek() === '"') {
            return $this->parseQuotedString($originalInput);
        }

        return $this->parseToken();
    }

    /**
     * Parse a quoted-string value per RFC 2045, handling backslash escapes.
     *
     * @throws ParameterParsingException If the quoted-string is unterminated or malformed.
     */
    private function parseQuotedString(string $originalInput): string
    {
        if (!$this->consumeChar('"')) {
            throw ParameterParsingException::forInvalidParameter($originalInput);
        }

        $value = '';
        while (!$this->isAtEnd()) {
            $char = $this->current();

            if ($char === '"') {
                $this->advance();
                return $value;
            }

            if ($char === '\\') {
                $this->advance();
                if ($this->isAtEnd()) {
                    throw ParameterParsingException::forInvalidParameter($originalInput);
                }

                $value .= $this->current();
                $this->advance();
                continue;
            }

            $value .= $char;
            $this->advance();
        }

        throw ParameterParsingException::forInvalidParameter($originalInput);
    }

    /**
     * Parse a token per RFC 2045: any CHAR except SPACE, CTLs, or tspecials.
     *
     * tspecials: ( ) < > @ , ; : \ " / [ ] ? =
     */
    private function parseToken(): string
    {
        $start = $this->position;
        while (!$this->isAtEnd()) {
            $char = $this->current();
            if (!self::isTokenChar($char)) {
                break;
            }

            $this->advance();
        }

        /** @var non-negative-int $length */
        $length = $this->position - $start;

        return substr($this->input, $start, $length);
    }

    /**
     * Determine whether a character is valid within an RFC 2045 token.
     *
     * Token characters are any printable ASCII character (0x21-0x7E) except tspecials.
     */
    private static function isTokenChar(string $char): bool
    {
        $ord = ord($char);

        if ($ord <= 32 || $ord >= 127) {
            return false;
        }

        return !str_contains('()<>@,;:\\"/?=[]', $char);
    }

    /**
     * Validate the top-level type token per RFC 6838 section 4.2 naming rules.
     *
     * @throws MediaTypeParsingException If the type contains invalid characters or exceeds 127 bytes.
     */
    private static function validateType(string $type, string $originalInput, bool $allowWildcards): void
    {
        if ($allowWildcards && $type === '*') {
            return;
        }

        if ($type === '' || strlen($type) > 127) {
            throw MediaTypeParsingException::forInvalidMediaType($originalInput);
        }

        for ($i = 0, $len = strlen($type); $i < $len; $i++) {
            $ord = ord($type[$i]);

            $valid =
                $ord >= 0x61 && $ord <= 0x7A || // a-z
                $ord >= 0x30 && $ord <= 0x39
                // 0-9
                || str_contains('!#$&-^_.+', $type[$i]);

            if (!$valid) {
                throw MediaTypeParsingException::forInvalidMediaType($originalInput);
            }
        }
    }

    /**
     * Validate the subtype token per RFC 6838 section 4.2 naming rules.
     *
     * @throws MediaTypeParsingException If the subtype contains invalid characters or exceeds 127 bytes.
     */
    private static function validateSubtype(string $subtype, string $originalInput, bool $allowWildcards): void
    {
        if ($allowWildcards && $subtype === '*') {
            return;
        }

        if ($subtype === '' || strlen($subtype) > 127) {
            throw MediaTypeParsingException::forInvalidMediaType($originalInput);
        }

        for ($i = 0, $len = strlen($subtype); $i < $len; $i++) {
            $ord = ord($subtype[$i]);

            $valid =
                $ord >= 0x61 && $ord <= 0x7A || // a-z
                $ord >= 0x30 && $ord <= 0x39 || // 0-9
                str_contains('!#$&-^_.+', $subtype[$i]);

            if (!$valid) {
                throw MediaTypeParsingException::forInvalidMediaType($originalInput);
            }
        }
    }

    /**
     * Assemble RFC 2231 encoded and continuation parameters.
     *
     * RFC 2231 supports:
     * - Encoded values: `param*=charset'language'encoded-value`
     * - Continuations: `param*0=val1; param*1=val2`
     * - Combined: `param*0*=charset'language'encoded; param*1*=more-encoded`
     *
     * @param list<array{non-empty-lowercase-string, string}> $parameters
     *
     * @throws ParameterParsingException
     *
     * @return list<list{non-empty-lowercase-string, string}>
     *
     * @link https://datatracker.ietf.org/doc/html/rfc2231
     */
    private static function assembleRfc2231Parameters(array $parameters): array
    {
        /** @var array<non-empty-lowercase-string, array{encoded: bool, parts: array<int, array{string, bool}>}> $continuations */
        $continuations = [];
        /** @var list<array{non-empty-lowercase-string, string}> $result */
        $result = [];

        foreach ($parameters as [$name, $value]) {
            if (str_contains($name, '*')) {
                $baseName = $name;
                $encoded = false;

                if (str_ends_with($name, '*')) {
                    /** @var non-negative-int $nameLen */
                    $nameLen = strlen($name) - 1;
                    /** @var non-empty-lowercase-string $baseName */
                    $baseName = substr($name, 0, $nameLen);
                    $encoded = true;
                }

                $sectionIndex = null;
                $starPos = strpos($baseName, '*');
                if ($starPos !== false) {
                    $sectionStr = substr($baseName, $starPos + 1);
                    /** @var non-empty-lowercase-string $baseName */
                    $baseName = substr($baseName, 0, $starPos);
                    if ($sectionStr !== '' && preg_match('/^(?:0|[1-9]\d*)$/', $sectionStr)) {
                        $sectionIndex = (int) $sectionStr;
                    }
                }

                if ($sectionIndex !== null) {
                    if (!isset($continuations[$baseName])) {
                        $continuations[$baseName] = ['encoded' => $encoded, 'parts' => []];
                    }

                    $continuations[$baseName]['parts'][$sectionIndex] = [$value, $encoded];
                    if ($encoded) {
                        $continuations[$baseName]['encoded'] = true;
                    }
                } elseif ($encoded) {
                    $result[] = [$baseName, self::decodeRfc2231Value($value)];
                } else {
                    $result[] = [$name, $value];
                }
            } else {
                $result[] = [$name, $value];
            }
        }

        foreach ($continuations as $name => $info) {
            ksort($info['parts']);
            if (!array_is_list($info['parts'])) {
                throw ParameterParsingException::forInvalidParameter(
                    'RFC 2231 continuation for "' . $name . '" has non-sequential section indices',
                );
            }

            $assembled = '';
            $firstEncoded = false;

            foreach ($info['parts'] as $index => [$partValue, $partEncoded]) {
                if ($index === 0 && $partEncoded) {
                    $firstEncoded = true;
                }

                $assembled .= $partValue;
            }

            if ($firstEncoded) {
                $result[] = [$name, self::decodeRfc2231Value($assembled)];
            } else {
                $result[] = [$name, $assembled];
            }
        }

        return $result;
    }

    /**
     * Decode an RFC 2231 encoded value: charset'language'percent-encoded-value.
     *
     * @throws ParameterParsingException If the value does not contain the required structure.
     */
    private static function decodeRfc2231Value(string $value): string
    {
        $firstQuote = strpos($value, "'");
        if ($firstQuote === false) {
            throw ParameterParsingException::forInvalidParameter($value);
        }

        $secondQuote = strpos($value, "'", $firstQuote + 1);
        if ($secondQuote === false) {
            throw ParameterParsingException::forInvalidParameter($value);
        }

        $charset = mb_strtolower(substr($value, 0, $firstQuote));
        $encoded = substr($value, $secondQuote + 1);
        $decoded = self::percentDecode($encoded);

        if ($charset !== '' && $charset !== 'utf-8' && $charset !== 'utf8') {
            $sourceEncoding = self::charsetToEncoding($charset);
            $decoded = (string) mb_convert_encoding($decoded, 'UTF-8', $sourceEncoding);
        }

        return $decoded;
    }

    /**
     * Map a MIME charset name to an mbstring encoding name.
     *
     * Only charsets supported by mbstring are mapped.
     *
     * @throws ParameterParsingException If the charset is not recognized.
     */
    private static function charsetToEncoding(string $charset): string
    {
        return match ($charset) {
            'us-ascii', 'ascii' => 'ASCII',
            'utf-7', 'utf7' => 'UTF-7',
            'utf-16', 'utf16' => 'UTF-16',
            'utf-16be' => 'UTF-16BE',
            'utf-16le' => 'UTF-16LE',
            'utf-32', 'utf32' => 'UTF-32',
            'utf-32be' => 'UTF-32BE',
            'utf-32le' => 'UTF-32LE',
            'ucs-4' => 'UCS-4',
            'ucs-4be' => 'UCS-4BE',
            'ucs-4le' => 'UCS-4LE',
            'ucs-2' => 'UCS-2',
            'ucs-2be' => 'UCS-2BE',
            'ucs-2le' => 'UCS-2LE',
            'iso-8859-1', 'latin1' => 'ISO-8859-1',
            'iso-8859-2', 'latin2' => 'ISO-8859-2',
            'iso-8859-3' => 'ISO-8859-3',
            'iso-8859-4' => 'ISO-8859-4',
            'iso-8859-5' => 'ISO-8859-5',
            'iso-8859-6' => 'ISO-8859-6',
            'iso-8859-7' => 'ISO-8859-7',
            'iso-8859-8' => 'ISO-8859-8',
            'iso-8859-9' => 'ISO-8859-9',
            'iso-8859-10' => 'ISO-8859-10',
            'iso-8859-13' => 'ISO-8859-13',
            'iso-8859-14' => 'ISO-8859-14',
            'iso-8859-15', 'latin9' => 'ISO-8859-15',
            'iso-8859-16' => 'ISO-8859-16',
            'windows-1251', 'cp1251' => 'Windows-1251',
            'windows-1252', 'cp1252' => 'Windows-1252',
            'windows-1254', 'cp1254' => 'Windows-1254',
            'euc-jp', 'eucjp' => 'EUC-JP',
            'shift_jis', 'sjis' => 'SJIS',
            'cp932' => 'CP932',
            'iso-2022-jp' => 'ISO-2022-JP',
            'euc-kr', 'euckr' => 'EUC-KR',
            'uhc' => 'UHC',
            'iso-2022-kr' => 'ISO-2022-KR',
            'euc-cn', 'euccn' => 'EUC-CN',
            'gb2312', 'gbk', 'cp936' => 'CP936',
            'gb18030' => 'GB18030',
            'hz', 'hz-gb-2312' => 'HZ',
            'big5' => 'BIG-5',
            'cp950' => 'CP950',
            'euc-tw', 'euctw' => 'EUC-TW',
            'koi8-r', 'koi8r' => 'KOI8-R',
            'koi8-u', 'koi8u' => 'KOI8-U',
            'armscii-8' => 'ArmSCII-8',
            'cp866' => 'CP866',
            'cp850' => 'CP850',
            default => throw ParameterParsingException::forInvalidParameter('unsupported charset: "' . $charset . '"'),
        };
    }

    /**
     * Decode percent-encoded octets (%XX) in the given string.
     */
    private static function percentDecode(string $input): string
    {
        $result = '';
        $i = 0;
        $len = strlen($input);

        while ($i < $len) {
            if ($input[$i] === '%' && ($i + 2) < $len) {
                $hex = $input[$i + 1] . $input[$i + 2];
                if (ctype_xdigit($hex)) {
                    /** @var non-empty-string $hex */
                    $result .= chr(intval($hex, 16));
                    $i += 3;
                    continue;
                }
            }

            $result .= $input[$i];
            $i++;
        }

        return $result;
    }

    /**
     * Advance past any optional whitespace (SP or HTAB) at the current position.
     */
    private function skipWhitespace(): void
    {
        while ($this->position < $this->length) {
            $char = $this->input[$this->position];
            if ($char !== ' ' && $char !== "\t") {
                break;
            }

            $this->position++;
        }
    }

    /**
     * Return the character at the current position without advancing, or null at end of input.
     */
    private function peek(): null|string
    {
        if ($this->position >= $this->length) {
            return null;
        }

        return $this->input[$this->position];
    }

    /**
     * Return the character at the current position.
     *
     * Must only be called when not at end of input.
     */
    private function current(): string
    {
        return $this->input[$this->position];
    }

    /**
     * Move the position forward by one byte.
     */
    private function advance(): void
    {
        $this->position++;
    }

    /**
     * If the current character matches the expected character, consume it and return true.
     */
    private function consumeChar(string $expected): bool
    {
        if ($this->position < $this->length && $this->input[$this->position] === $expected) {
            $this->position++;
            return true;
        }

        return false;
    }

    /**
     * Check whether the parser has consumed all input.
     */
    private function isAtEnd(): bool
    {
        return $this->position >= $this->length;
    }
}
