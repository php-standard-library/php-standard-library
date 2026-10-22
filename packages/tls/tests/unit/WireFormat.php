<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use Psl\Async\CancellationTokenInterface;
use Psl\IO;

use function chr;
use function ord;
use function str_repeat;
use function strlen;
use function substr;
use function unpack;

/**
 * Reads and writes the plaintext parts of TLS records, for tests that inspect or rebuild what goes over the wire.
 */
final class WireFormat
{
    /**
     * The content type of a handshake record (RFC 8446 section 5.1).
     */
    public const int HANDSHAKE = 22;

    /**
     * The handshake type of a ClientHello (RFC 8446 section 4).
     */
    public const int CLIENT_HELLO = 1;

    /**
     * The handshake type of a ServerHello (RFC 8446 section 4).
     */
    public const int SERVER_HELLO = 2;

    /**
     * The handshake type of a NewSessionTicket (RFC 5077 section 3.3, RFC 8446 section 4).
     */
    public const int NEW_SESSION_TICKET = 4;

    /**
     * The extension that carries a TLS 1.2 session ticket (RFC 5077 section 3.2).
     */
    public const int SESSION_TICKET_EXTENSION = 35;

    /**
     * The server_name extension (RFC 6066 section 3).
     */
    public const int SERVER_NAME_EXTENSION = 0;

    /**
     * The application_layer_protocol_negotiation extension (RFC 7301 section 3.1).
     */
    public const int ALPN_EXTENSION = 16;

    /**
     * The legacy record version clients put on their first records (RFC 8446 section 5.1).
     */
    public const string TLS10_RECORD_VERSION = "\x03\x01";

    /**
     * The legacy version a ClientHello body starts with (RFC 8446 section 4.1.2).
     */
    private const string TLS12_VERSION = "\x03\x03";

    /**
     * The length of a ClientHello's random field.
     */
    private const int RANDOM_LENGTH = 32;

    /**
     * The largest value a byte holds.
     */
    private const int BYTE_MASK = 0xFF;

    /**
     * The number of bits in a byte.
     */
    private const int BITS_PER_BYTE = 8;

    /**
     * The length of a record header: type, legacy version and length.
     */
    public const int RECORD_HEADER_LENGTH = 5;

    /**
     * The position of the two-byte fragment length in a record header, after the type and the legacy version.
     */
    private const int RECORD_LENGTH_OFFSET = 3;

    /**
     * The longest fragment a plaintext record may carry (RFC 8446 section 5.1).
     */
    public const int MAXIMUM_FRAGMENT_LENGTH = 16_384;

    /**
     * The length of a handshake message header: type and a 24-bit length.
     */
    private const int HANDSHAKE_HEADER = 4;

    /**
     * The bits of a handshake message header, read as one big-endian 32-bit integer, that hold the length: the low 24,
     * below the type.
     */
    private const int MESSAGE_LENGTH_MASK = 0xFF_FF_FF;

    /**
     * The size of a one-byte length, as in front of the session identifier and the compression methods.
     */
    private const int UINT8_LENGTH = 1;

    /**
     * The size of a two-byte length, as in front of the cipher suites and the extension block.
     */
    private const int UINT16_LENGTH = 2;

    /**
     * The length of an extension header: the extension type, and the length of its data, two bytes each.
     */
    private const int EXTENSION_HEADER_LENGTH = 4;

    /**
     * The position of the data length in an extension header, after the extension type.
     */
    private const int EXTENSION_LENGTH_OFFSET = 2;

    /**
     * The name type of a DNS host name in a server_name extension (RFC 6066 section 3).
     */
    private const string HOST_NAME_TYPE = "\x00";

    /**
     * The length of the version and random fields at the start of a ClientHello or ServerHello.
     */
    private const int HELLO_PREFIX = 2 + 32;

    /**
     * The length of the cipher suite and compression method a ServerHello picks.
     */
    private const int SERVER_HELLO_CHOICES = 2 + 1;

    /**
     * Split bytes into the complete records they start with.
     *
     * @return list<array{int, string}> Each record's content type and fragment.
     */
    public static function records(string $bytes): array
    {
        $records = [];
        $offset = 0;
        while (($offset + self::RECORD_HEADER_LENGTH) <= strlen($bytes)) {
            $length = self::uint16($bytes, $offset + self::RECORD_LENGTH_OFFSET);
            if (($offset + self::RECORD_HEADER_LENGTH + $length) > strlen($bytes)) {
                break;
            }

            $records[] = [ord($bytes[$offset]), substr($bytes, $offset + self::RECORD_HEADER_LENGTH, $length)];
            $offset += self::RECORD_HEADER_LENGTH + $length;
        }

        return $records;
    }

    /**
     * Read one whole record from a stream: its header, then the fragment the header announces.
     *
     * @return string The record, header included.
     */
    public static function readRecord(IO\ReadHandleInterface $handle, CancellationTokenInterface $cancellation): string
    {
        $header = $handle->readFixedSize(self::RECORD_HEADER_LENGTH, $cancellation);

        return $header . $handle->readFixedSize(self::uint16($header, self::RECORD_LENGTH_OFFSET), $cancellation);
    }

    /**
     * The handshake messages carried by the leading handshake records, reassembled across record boundaries.
     *
     * Reassembly stops at the first record of another type: later handshake records are encrypted.
     *
     * @param list<array{int, string}> $records
     *
     * @return list<array{int, string}> Each message's handshake type and body.
     */
    public static function handshakeMessages(array $records): array
    {
        $stream = '';
        foreach ($records as [$type, $fragment]) {
            if (self::HANDSHAKE !== $type) {
                break;
            }

            $stream .= $fragment;
        }

        $messages = [];
        $offset = 0;
        while (($offset + self::HANDSHAKE_HEADER) <= strlen($stream)) {
            $length = self::uint32($stream, $offset) & self::MESSAGE_LENGTH_MASK;
            if (($offset + self::HANDSHAKE_HEADER + $length) > strlen($stream)) {
                break;
            }

            $messages[] = [ord($stream[$offset]), substr($stream, $offset + self::HANDSHAKE_HEADER, $length)];
            $offset += self::HANDSHAKE_HEADER + $length;
        }

        return $messages;
    }

    /**
     * The extension types of a ClientHello or ServerHello body, in order.
     *
     * @return list<int>
     */
    public static function helloExtensionTypes(string $body, bool $clientHello): array
    {
        $offset = self::HELLO_PREFIX;
        $offset += self::UINT8_LENGTH + ord($body[$offset]);
        if ($clientHello) {
            $offset += self::UINT16_LENGTH + self::uint16($body, $offset);
            $offset += self::UINT8_LENGTH + ord($body[$offset]);
        } else {
            $offset += self::SERVER_HELLO_CHOICES;
        }

        if (($offset + self::UINT16_LENGTH) > strlen($body)) {
            return [];
        }

        $end = $offset + self::UINT16_LENGTH + self::uint16($body, $offset);
        $offset += self::UINT16_LENGTH;
        $types = [];
        while (($offset + self::EXTENSION_HEADER_LENGTH) <= $end) {
            $types[] = self::uint16($body, $offset);
            $offset += self::EXTENSION_HEADER_LENGTH + self::uint16($body, $offset + self::EXTENSION_LENGTH_OFFSET);
        }

        return $types;
    }

    /**
     * Build a ClientHello handshake message, header included.
     *
     * @param null|list<array{int, string}> $extensions Each extension's type and data, or null for a ClientHello
     *                                                 without an extension block.
     */
    public static function clientHello(
        null|array $extensions,
        string $sessionId = '',
        string $cipherSuites = "\x13\x01",
        string $compressionMethods = "\x00",
    ): string {
        $body =
            self::TLS12_VERSION
            . str_repeat("\x00", self::RANDOM_LENGTH)
            . chr(strlen($sessionId))
            . $sessionId
            . self::encodeUint16(strlen($cipherSuites))
            . $cipherSuites
            . chr(strlen($compressionMethods))
            . $compressionMethods;
        if (null !== $extensions) {
            $block = '';
            foreach ($extensions as [$type, $data]) {
                $block .= self::encodeUint16($type) . self::encodeUint16(strlen($data)) . $data;
            }

            $body .= self::encodeUint16(strlen($block)) . $block;
        }

        return self::handshakeMessage(self::CLIENT_HELLO, $body);
    }

    /**
     * Build a handshake message: its type, its 24-bit length and its body.
     */
    public static function handshakeMessage(int $type, string $body): string
    {
        $length = strlen($body);

        return (
            chr($type)
            . chr(($length >> (2 * self::BITS_PER_BYTE)) & self::BYTE_MASK)
            . self::encodeUint16($length)
            . $body
        );
    }

    /**
     * The data of a server_name extension that names the given host.
     *
     * @return array{int, string}
     */
    public static function serverName(string $hostName): array
    {
        $entry = self::HOST_NAME_TYPE . self::encodeUint16(strlen($hostName)) . $hostName;

        return [self::SERVER_NAME_EXTENSION, self::encodeUint16(strlen($entry)) . $entry];
    }

    /**
     * The data of an application_layer_protocol_negotiation extension that offers the given protocols.
     *
     * @param list<string> $protocols
     *
     * @return array{int, string}
     */
    public static function alpn(array $protocols): array
    {
        $list = '';
        foreach ($protocols as $protocol) {
            $list .= chr(strlen($protocol)) . $protocol;
        }

        return [self::ALPN_EXTENSION, self::encodeUint16(strlen($list)) . $list];
    }

    /**
     * Frame a handshake byte stream as handshake records of at most the given fragment length.
     *
     * @param positive-int $fragmentLength
     */
    public static function frameHandshake(
        string $stream,
        int $fragmentLength,
        string $legacyVersion = self::TLS10_RECORD_VERSION,
    ): string {
        $records = '';
        $length = strlen($stream);
        for ($offset = 0; $offset < $length; $offset += $fragmentLength) {
            $fragment = substr($stream, $offset, $fragmentLength);
            $records .= chr(self::HANDSHAKE) . $legacyVersion . self::encodeUint16(strlen($fragment)) . $fragment;
        }

        return $records;
    }

    /**
     * Read a big-endian 16-bit integer.
     */
    private static function uint16(string $bytes, int $offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('n', $bytes, $offset);

        return $value[1];
    }

    /**
     * Read a big-endian 32-bit integer.
     */
    private static function uint32(string $bytes, int $offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('N', $bytes, $offset);

        return $value[1];
    }

    /**
     * Write the low 16 bits of an integer, big-endian.
     */
    public static function encodeUint16(int $value): string
    {
        return chr(($value >> self::BITS_PER_BYTE) & self::BYTE_MASK) . chr($value & self::BYTE_MASK);
    }
}
