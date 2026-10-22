<?php

declare(strict_types=1);

namespace Psl\TLS\Internal;

use Psl\TLS\Exception\HandshakeFailedException;

use function min;
use function ord;
use function strlen;
use function substr;
use function unpack;

/**
 * Parses a TLS ClientHello message to extract SNI and ALPN extensions.
 *
 * This parser handles the minimum necessary to inspect the ClientHello before
 * completing the TLS handshake, enabling SNI-based certificate selection and
 * protocol detection.
 *
 * The bytes are the start of what the client sent: one or more handshake records
 * (RFC 8446 section 5.1) whose fragments, joined, carry the ClientHello message. The
 * parser checks every length it reads, and tells three cases apart: the bytes are a
 * complete ClientHello, a valid prefix of one that needs more bytes, or not a valid
 * ClientHello at all.
 *
 * @mago-expect lint:cyclomatic-complexity - Each length of the wire format has its own check and failure.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
final class ClientHelloParser
{
    /**
     * The content type of a handshake record.
     */
    private const int HANDSHAKE_RECORD = 22;

    /**
     * The major version byte of every TLS record (SSL 3.0 and TLS 1.0 to 1.3 all use 3).
     */
    private const int RECORD_MAJOR_VERSION = 3;

    /**
     * The length of a record header: content type, legacy version and fragment length.
     */
    private const int RECORD_HEADER_LENGTH = 5;

    /**
     * The position of the major version byte in a record header, after the content type.
     */
    private const int RECORD_VERSION_OFFSET = 1;

    /**
     * The position of the two-byte fragment length in a record header, after the content type and the legacy version.
     */
    private const int RECORD_LENGTH_OFFSET = 3;

    /**
     * The longest fragment a plaintext record may carry (RFC 8446 section 5.1, RFC 5246 section 6.2.1).
     */
    private const int MAXIMUM_FRAGMENT_LENGTH = 16_384;

    /**
     * The handshake type of a ClientHello.
     */
    private const int CLIENT_HELLO = 1;

    /**
     * The length of a handshake message header: handshake type and a 24-bit body length.
     */
    private const int HANDSHAKE_HEADER_LENGTH = 4;

    /**
     * The bits of a handshake message header, read as one big-endian 32-bit integer, that hold the body length: the
     * low 24, below the handshake type.
     */
    private const int MESSAGE_LENGTH_MASK = 0xFF_FF_FF;

    /**
     * The size of a one-byte length, as in front of the session identifier, the compression methods and each protocol
     * name.
     */
    private const int UINT8_LENGTH = 1;

    /**
     * The size of a two-byte length, as in front of the cipher suites, the extension block and the name lists.
     */
    private const int UINT16_LENGTH = 2;

    /**
     * The length of one cipher suite.
     */
    private const int CIPHER_SUITE_LENGTH = 2;

    /**
     * The length of one compression method.
     */
    private const int COMPRESSION_METHOD_LENGTH = 1;

    /**
     * The length of the legacy version and random fields that start a ClientHello body.
     */
    private const int VERSION_AND_RANDOM_LENGTH = 2 + 32;

    /**
     * The longest legacy session identifier (RFC 8446 section 4.1.2).
     */
    private const int MAXIMUM_SESSION_ID_LENGTH = 32;

    /**
     * The shortest ClientHello body: version and random, an empty session identifier, one cipher suite and one
     * compression method, each list behind its length.
     */
    private const int MINIMUM_BODY_LENGTH =
        self::VERSION_AND_RANDOM_LENGTH
            + self::UINT8_LENGTH
            + self::UINT16_LENGTH
            + self::CIPHER_SUITE_LENGTH
            + self::UINT8_LENGTH
            + self::COMPRESSION_METHOD_LENGTH;

    /**
     * The length of an extension header: the extension type, and the length of its data, two bytes each.
     */
    private const int EXTENSION_HEADER_LENGTH = 4;

    /**
     * The position of the data length in an extension header, after the extension type.
     */
    private const int EXTENSION_LENGTH_OFFSET = 2;

    /**
     * The extension type of server_name (RFC 6066 section 3).
     */
    private const int SERVER_NAME_EXTENSION = 0;

    /**
     * The extension type of application_layer_protocol_negotiation (RFC 7301 section 3.1).
     */
    private const int ALPN_EXTENSION = 16;

    /**
     * The server name type of a DNS host name (RFC 6066 section 3).
     */
    private const int HOST_NAME = 0;

    /**
     * The length of a server name entry header: the one-byte name type and the two-byte name length.
     */
    private const int SERVER_NAME_ENTRY_HEADER_LENGTH = 3;

    /**
     * The position of the name length in a server name entry header, after the name type.
     */
    private const int SERVER_NAME_LENGTH_OFFSET = 1;

    /**
     * Parse the start of what a client sent, from the first record header on, and extract the server name (SNI) and
     * the ALPN protocols of its ClientHello.
     *
     * While the ClientHello is not complete, the parser asks for more bytes by returning null, unless the input is
     * truncated: it is then parsed as far as it goes, and the result reports only the extensions that end inside it.
     *
     * @param string $data The bytes received so far.
     * @param bool $truncated Whether no more bytes will be inspected.
     *
     * @throws HandshakeFailedException If the bytes are not a valid ClientHello, or the start of one.
     *
     * @return null|array{server_name: ?non-empty-string, alpn_protocols: ?list<non-empty-string>} Null when more
     *                                                                                               bytes are needed.
     */
    public static function parse(string $data, bool $truncated = false): null|array
    {
        $handshake = self::joinFragments($data);
        $available = strlen($handshake);
        if ($available === 0) {
            return $truncated ? self::nothing() : null;
        }

        if (ord($handshake[0]) !== self::CLIENT_HELLO) {
            throw self::malformed('the first handshake message is not a ClientHello');
        }

        if ($available < self::HANDSHAKE_HEADER_LENGTH) {
            return $truncated ? self::nothing() : null;
        }

        $length = self::messageLength($handshake);
        if ($length < self::MINIMUM_BODY_LENGTH) {
            throw self::malformed('the ClientHello is shorter than its required fields');
        }

        $body = substr($handshake, self::HANDSHAKE_HEADER_LENGTH, $length);
        if (strlen($body) < $length && !$truncated) {
            return null;
        }

        return self::parseBody($body, $length);
    }

    /**
     * Join the fragments of the leading handshake records, checking each record header.
     *
     * Joining stops once the ClientHello is complete. The last record may be incomplete: its available bytes are
     * joined too.
     *
     * @throws HandshakeFailedException If a record header is invalid, or a record of another type comes first.
     */
    private static function joinFragments(string $data): string
    {
        $length = strlen($data);
        $handshake = '';
        $offset = 0;
        while ($offset < $length) {
            if (ord($data[$offset]) !== self::HANDSHAKE_RECORD) {
                throw self::malformed('the client sent a record that is not a handshake record');
            }

            $version = $offset + self::RECORD_VERSION_OFFSET;
            if ($version < $length && ord($data[$version]) !== self::RECORD_MAJOR_VERSION) {
                throw self::malformed('a record has an unknown version');
            }

            if (($offset + self::RECORD_HEADER_LENGTH) > $length) {
                break;
            }

            $fragmentLength = self::uint16($data, $offset + self::RECORD_LENGTH_OFFSET);
            if ($fragmentLength === 0 || $fragmentLength > self::MAXIMUM_FRAGMENT_LENGTH) {
                throw self::malformed('a record has an invalid length');
            }

            $handshake .= substr($data, $offset + self::RECORD_HEADER_LENGTH, $fragmentLength);
            $offset += self::RECORD_HEADER_LENGTH + $fragmentLength;

            // Whatever follows a complete ClientHello is the handshaker's to read.
            $joined = strlen($handshake);
            if (
                $joined >= self::HANDSHAKE_HEADER_LENGTH
                && $joined >= (self::HANDSHAKE_HEADER_LENGTH + self::messageLength($handshake))
            ) {
                break;
            }
        }

        return $handshake;
    }

    /**
     * Parse a ClientHello body, or the start of one when the input was truncated.
     *
     * @param string $body The body, or the part of it that was received.
     * @param int $length The body length the handshake header declares.
     *
     * @throws HandshakeFailedException If a field is invalid, or does not fit in the declared length.
     *
     * @return array{server_name: ?non-empty-string, alpn_protocols: ?list<non-empty-string>}
     */
    private static function parseBody(string $body, int $length): array
    {
        $available = strlen($body);
        $offset = self::VERSION_AND_RANDOM_LENGTH;

        // Legacy session identifier, behind a one-byte length.
        if (($offset + self::UINT8_LENGTH) > $available) {
            return self::cutShort($available, $length);
        }

        $sessionIdLength = ord($body[$offset]);
        if ($sessionIdLength > self::MAXIMUM_SESSION_ID_LENGTH) {
            throw self::malformed('the session identifier is too long');
        }

        $offset += self::UINT8_LENGTH + $sessionIdLength;

        // Cipher suites, behind a two-byte length.
        if (($offset + self::UINT16_LENGTH) > $available) {
            return self::cutShort($available, $length);
        }

        $cipherSuitesLength = self::uint16($body, $offset);
        if ($cipherSuitesLength === 0 || ($cipherSuitesLength % self::CIPHER_SUITE_LENGTH) !== 0) {
            throw self::malformed('the cipher suite list has an invalid length');
        }

        $offset += self::UINT16_LENGTH + $cipherSuitesLength;

        // Compression methods, behind a one-byte length.
        if (($offset + self::UINT8_LENGTH) > $available) {
            return self::cutShort($available, $length);
        }

        $compressionMethodsLength = ord($body[$offset]);
        if ($compressionMethodsLength === 0) {
            throw self::malformed('the compression method list is empty');
        }

        $offset += self::UINT8_LENGTH + $compressionMethodsLength;
        if ($offset > $length) {
            throw self::malformed('the ClientHello fields exceed its length');
        }

        if ($offset === $length) {
            // No extensions.
            return self::nothing();
        }

        // Extensions, behind a two-byte length that must cover the rest of the body exactly.
        if (($offset + self::UINT16_LENGTH) > $available) {
            return self::cutShort($available, $length);
        }

        $extensionsLength = self::uint16($body, $offset);
        $offset += self::UINT16_LENGTH;
        if (($offset + $extensionsLength) !== $length) {
            throw self::malformed('the extension block does not end with the ClientHello');
        }

        $end = min($length, $available);
        $seen = [];
        $serverName = null;
        $alpnProtocols = null;
        while (($offset + self::EXTENSION_HEADER_LENGTH) <= $end) {
            $type = self::uint16($body, $offset);
            $dataLength = self::uint16($body, $offset + self::EXTENSION_LENGTH_OFFSET);
            $offset += self::EXTENSION_HEADER_LENGTH;
            $extensionEnd = $offset + $dataLength;
            if ($extensionEnd > $length) {
                throw self::malformed('an extension exceeds the extension block');
            }

            if (isset($seen[$type])) {
                throw self::malformed('an extension appears twice');
            }

            $seen[$type] = true;
            if ($extensionEnd > $available) {
                // The input was truncated inside this extension.
                return ['server_name' => $serverName, 'alpn_protocols' => $alpnProtocols];
            }

            if ($type === self::SERVER_NAME_EXTENSION) {
                $serverName = self::parseServerName($body, $offset, $extensionEnd);
            } elseif ($type === self::ALPN_EXTENSION) {
                $alpnProtocols = self::parseAlpnProtocols($body, $offset, $extensionEnd);
            }

            $offset = $extensionEnd;
        }

        if ($offset < $length && $available >= $length) {
            throw self::malformed('an extension header is incomplete');
        }

        return ['server_name' => $serverName, 'alpn_protocols' => $alpnProtocols];
    }

    /**
     * Parse the server_name extension data, and return its first host name.
     *
     * @throws HandshakeFailedException If the server name list is invalid.
     *
     * @return null|non-empty-string
     */
    private static function parseServerName(string $data, int $offset, int $end): null|string
    {
        if (
            ($offset + self::UINT16_LENGTH) >= $end
            || ($offset + self::UINT16_LENGTH + self::uint16($data, $offset)) !== $end
        ) {
            throw self::malformed('the server name list has an invalid length');
        }

        $offset += self::UINT16_LENGTH;
        $hostName = null;
        while ($offset < $end) {
            if (($offset + self::SERVER_NAME_ENTRY_HEADER_LENGTH) > $end) {
                throw self::malformed('a server name entry is incomplete');
            }

            $nameType = ord($data[$offset]);
            $nameLength = self::uint16($data, $offset + self::SERVER_NAME_LENGTH_OFFSET);
            $offset += self::SERVER_NAME_ENTRY_HEADER_LENGTH;
            if ($nameLength === 0 || ($offset + $nameLength) > $end) {
                throw self::malformed('a server name has an invalid length');
            }

            if ($nameType === self::HOST_NAME && null === $hostName) {
                /** @var non-empty-string */
                $hostName = substr($data, $offset, $nameLength);
            }

            $offset += $nameLength;
        }

        return $hostName;
    }

    /**
     * Parse the application_layer_protocol_negotiation extension data.
     *
     * @throws HandshakeFailedException If the protocol name list is invalid.
     *
     * @return list<non-empty-string>
     */
    private static function parseAlpnProtocols(string $data, int $offset, int $end): array
    {
        if (
            ($offset + self::UINT16_LENGTH) >= $end
            || ($offset + self::UINT16_LENGTH + self::uint16($data, $offset)) !== $end
        ) {
            throw self::malformed('the protocol name list has an invalid length');
        }

        $offset += self::UINT16_LENGTH;
        $protocols = [];
        while ($offset < $end) {
            $protocolLength = ord($data[$offset]);
            $offset += self::UINT8_LENGTH;
            if ($protocolLength === 0 || ($offset + $protocolLength) > $end) {
                throw self::malformed('a protocol name has an invalid length');
            }

            /** @var non-empty-string $protocol */
            $protocol = substr($data, $offset, $protocolLength);
            $protocols[] = $protocol;
            $offset += $protocolLength;
        }

        return $protocols;
    }

    /**
     * The result for a body whose next field was not received: nothing, unless the whole body was, in which case the
     * field overruns it.
     *
     * @throws HandshakeFailedException If the whole body was received.
     *
     * @return array{server_name: null, alpn_protocols: null}
     */
    private static function cutShort(int $available, int $length): array
    {
        if ($available >= $length) {
            throw self::malformed('the ClientHello fields exceed its length');
        }

        return self::nothing();
    }

    /**
     * The result for a ClientHello that names no server and no protocol, or whose extensions were not received.
     *
     * @return array{server_name: null, alpn_protocols: null}
     */
    private static function nothing(): array
    {
        return ['server_name' => null, 'alpn_protocols' => null];
    }

    /**
     * The body length a handshake message header declares.
     *
     * @param string $handshake Bytes that start with a whole handshake message header.
     */
    private static function messageLength(string $handshake): int
    {
        return self::uint32($handshake, 0) & self::MESSAGE_LENGTH_MASK;
    }

    /**
     * Read a big-endian 16-bit integer.
     */
    private static function uint16(string $data, int $offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('n', $data, $offset);

        return $value[1];
    }

    /**
     * Read a big-endian 32-bit integer.
     */
    private static function uint32(string $data, int $offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('N', $data, $offset);

        return $value[1];
    }

    /**
     * The exception for bytes that are not a valid ClientHello.
     */
    private static function malformed(string $reason): HandshakeFailedException
    {
        return new HandshakeFailedException('Failed to parse TLS ClientHello: ' . $reason . '.');
    }
}
