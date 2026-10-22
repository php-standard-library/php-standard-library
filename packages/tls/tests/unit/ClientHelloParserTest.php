<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\TLS;
use Psl\TLS\Internal\ClientHelloParser;

use function str_repeat;
use function strlen;
use function substr;

/**
 * The ClientHello parser tells a complete ClientHello, a valid prefix of one, and invalid bytes apart, and joins a
 * ClientHello that spans several records.
 */
final class ClientHelloParserTest extends TestCase
{
    /**
     * The host name the test ClientHellos ask for.
     */
    private const string HOST = 'example.test';

    /**
     * The extension type of padding (RFC 7685), which the parser skips.
     */
    private const int PADDING_EXTENSION = 21;

    /**
     * A reserved GREASE extension type (RFC 8701), which the parser skips.
     */
    private const int GREASE_EXTENSION = 0x0A0A;

    /**
     * The shortest ClientHello body: version, random, an empty session identifier, one cipher suite and one
     * compression method.
     */
    private const int MINIMUM_BODY_LENGTH = 2 + 32 + 1 + 2 + 2 + 1 + 1;

    /**
     * The longest legacy session identifier (RFC 8446 section 4.1.2).
     */
    private const int MAXIMUM_SESSION_ID_LENGTH = 32;

    /**
     * The length of a handshake message header.
     */
    private const int HANDSHAKE_HEADER_LENGTH = 4;

    /**
     * The length of a record header.
     */
    private const int RECORD_HEADER_LENGTH = 5;

    /**
     * The longest fragment a record carries, and the most bytes the lazy acceptor inspects.
     */
    private const int MAXIMUM_FRAGMENT_LENGTH = 16_384;

    /**
     * The fragment length of the records a ClientHello is split into to put a foreign record between two of them.
     */
    private const int SMALL_FRAGMENT_LENGTH = 20;

    /**
     * The length of the padding extension's data in the test that skips unknown extensions.
     */
    private const int PADDING_LENGTH = 100;

    /**
     * How many protocols the oversized ClientHello offers, and how long each name is: together, more than 16 KiB.
     */
    private const int LARGE_PROTOCOL_COUNT = 100;

    private const int LARGE_PROTOCOL_LENGTH = 200;

    /**
     * How many bytes of the oversized ClientHello end before its server name extension does.
     */
    private const int BEFORE_SERVER_NAME = 60;

    /**
     * A ClientHello as one record yields its server name and protocols.
     */
    public function testParsesACompleteClientHello(): void
    {
        static::assertSame(self::expected(), ClientHelloParser::parse(self::record(self::hello())));
    }

    /**
     * Every prefix of a ClientHello asks for more bytes, and none yields metadata.
     */
    public function testEveryPrefixNeedsMoreBytes(): void
    {
        $record = self::record(self::hello());
        for ($length = 1; $length < strlen($record); $length++) {
            static::assertNull(ClientHelloParser::parse(substr($record, 0, $length)), "Prefix of {$length} bytes.");
        }
    }

    /**
     * @return iterable<string, array{positive-int}>
     */
    public static function fragmentLengths(): iterable
    {
        yield 'one byte' => [1];
        yield 'seven bytes' => [7];
        yield 'one header and a half' => [6];
        yield '100 bytes' => [100];
    }

    /**
     * A ClientHello split across records of any size is joined, and each prefix of those records needs more bytes.
     *
     * @param positive-int $fragmentLength
     */
    #[DataProvider('fragmentLengths')]
    public function testJoinsAClientHelloSplitAcrossRecords(int $fragmentLength): void
    {
        $records = WireFormat::frameHandshake(self::hello(), $fragmentLength);

        static::assertSame(self::expected(), ClientHelloParser::parse($records));
        for ($length = 1; $length < strlen($records); $length++) {
            static::assertNull(ClientHelloParser::parse(substr($records, 0, $length)), "Prefix of {$length} bytes.");
        }
    }

    /**
     * Bytes after a complete ClientHello are left for the handshaker.
     */
    public function testIgnoresWhatFollowsACompleteClientHello(): void
    {
        $bytes = self::record(self::hello()) . "\x14\x03\x03\x00\x01\x01";

        static::assertSame(self::expected(), ClientHelloParser::parse($bytes));
    }

    /**
     * A ClientHello without extensions names nothing.
     */
    public function testParsesAClientHelloWithoutExtensions(): void
    {
        static::assertSame(
            ['server_name' => null, 'alpn_protocols' => null],
            ClientHelloParser::parse(self::record(WireFormat::clientHello(null))),
        );
    }

    /**
     * Unknown extensions are skipped.
     */
    public function testSkipsUnknownExtensions(): void
    {
        $hello = WireFormat::clientHello([
            [self::GREASE_EXTENSION, ''],
            WireFormat::serverName(self::HOST),
            [self::PADDING_EXTENSION, str_repeat("\x00", self::PADDING_LENGTH)],
            WireFormat::alpn(['h2', 'http/1.1']),
        ]);

        static::assertSame(self::expected(), ClientHelloParser::parse(self::record($hello)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedInputs(): iterable
    {
        yield 'an application data record' => ["\x17\x03\x03\x00\x05hello"];
        yield 'plain text' => ['NOT-A-TLS-HANDSHAKE'];
        yield 'an unknown record version' => ["\x16\x02\x00\x00\x05"];
        yield 'an empty record' => ["\x16\x03\x01\x00\x00"];
        yield 'a record longer than 16 KiB' => ["\x16\x03\x01\x40\x01"];
        yield 'a ServerHello' => [self::record(WireFormat::handshakeMessage(WireFormat::SERVER_HELLO, str_repeat(
            "\x00",
            self::MINIMUM_BODY_LENGTH,
        )))];
        yield 'a ClientHello shorter than its required fields' => [
            self::record(WireFormat::handshakeMessage(WireFormat::CLIENT_HELLO, str_repeat(
                "\x00",
                self::MINIMUM_BODY_LENGTH - 1,
            ))),
        ];
        yield 'a session identifier of 33 bytes' => [
            self::record(WireFormat::clientHello(null, sessionId: str_repeat(
                "\x00",
                self::MAXIMUM_SESSION_ID_LENGTH + 1,
            ))),
        ];
        yield 'an odd cipher suite list' => [self::record(WireFormat::clientHello(null, cipherSuites: "\x13\x01\x13"))];
        yield 'an empty cipher suite list' => [self::record(WireFormat::clientHello(null, cipherSuites: ''))];
        yield 'an empty compression method list' => [
            self::record(WireFormat::clientHello(null, compressionMethods: '')),
        ];
        yield 'a byte after the compression methods' => [
            self::record(WireFormat::handshakeMessage(
                WireFormat::CLIENT_HELLO,
                self::body(WireFormat::clientHello(null)) . "\x00",
            )),
        ];
        yield 'an extension block shorter than the rest' => [
            self::record(WireFormat::handshakeMessage(
                WireFormat::CLIENT_HELLO,
                self::body(self::hello()) . "\x00\x00\x00\x00",
            )),
        ];
        yield 'an extension past the extension block' => [
            self::record(self::corruptLastExtensionLength(WireFormat::clientHello([[
                self::PADDING_EXTENSION,
                "\x00\x00",
            ]]))),
        ];
        yield 'an extension twice' => [
            self::record(WireFormat::clientHello([WireFormat::serverName(self::HOST), WireFormat::serverName('b')])),
        ];
        yield 'a server name list that overruns its extension' => [
            self::record(WireFormat::clientHello([[WireFormat::SERVER_NAME_EXTENSION, "\x00\x09\x00\x00\x01a"]])),
        ];
        yield 'an empty server name' => [
            self::record(WireFormat::clientHello([[WireFormat::SERVER_NAME_EXTENSION, "\x00\x03\x00\x00\x00"]])),
        ];
        yield 'an empty protocol name' => [
            self::record(WireFormat::clientHello([[WireFormat::ALPN_EXTENSION, "\x00\x03\x02h2\x00"]])),
        ];
        yield 'an empty protocol list' => [
            self::record(WireFormat::clientHello([[WireFormat::ALPN_EXTENSION, "\x00\x00"]])),
        ];
        yield 'a record of another type between two fragments' => [
            (static function (): string {
                $records = WireFormat::frameHandshake(self::hello(), self::SMALL_FRAGMENT_LENGTH);
                $first = self::RECORD_HEADER_LENGTH + self::SMALL_FRAGMENT_LENGTH;

                return substr($records, 0, $first) . "\x14\x03\x03\x00\x01\x01" . substr($records, $first);
            })(),
        ];
    }

    /**
     * Invalid bytes fail, whether they are complete or a prefix.
     */
    #[DataProvider('malformedInputs')]
    public function testRejectsMalformedInput(string $bytes): void
    {
        $this->expectException(TLS\Exception\HandshakeFailedException::class);
        $this->expectExceptionMessage('Failed to parse TLS ClientHello');

        ClientHelloParser::parse($bytes);
    }

    /**
     * A truncated ClientHello reports the extensions that end inside what was inspected, and no others.
     */
    public function testTruncatedClientHelloReportsTheExtensionsThatEndInside(): void
    {
        $protocols = [];
        for ($i = 0; $i < self::LARGE_PROTOCOL_COUNT; $i++) {
            $protocols[] = str_repeat('p', self::LARGE_PROTOCOL_LENGTH);
        }

        $hello = WireFormat::clientHello([WireFormat::serverName(self::HOST), WireFormat::alpn($protocols)]);
        $records = WireFormat::frameHandshake($hello, self::MAXIMUM_FRAGMENT_LENGTH);
        $inspected = substr($records, 0, self::MAXIMUM_FRAGMENT_LENGTH);

        static::assertNull(ClientHelloParser::parse($inspected));
        static::assertSame(
            ['server_name' => self::HOST, 'alpn_protocols' => null],
            ClientHelloParser::parse($inspected, truncated: true),
        );
        static::assertSame(
            ['server_name' => null, 'alpn_protocols' => null],
            ClientHelloParser::parse(substr($records, 0, self::BEFORE_SERVER_NAME), truncated: true),
        );
        static::assertSame(
            ['server_name' => self::HOST, 'alpn_protocols' => $protocols],
            ClientHelloParser::parse($records),
        );
    }

    /**
     * A ClientHello that asks for the test host and offers h2 and http/1.1.
     */
    private static function hello(): string
    {
        return WireFormat::clientHello([WireFormat::serverName(self::HOST), WireFormat::alpn(['h2', 'http/1.1'])]);
    }

    /**
     * What the parser returns for {@see hello()}.
     *
     * @return array{server_name: string, alpn_protocols: list<string>}
     */
    private static function expected(): array
    {
        return ['server_name' => self::HOST, 'alpn_protocols' => ['h2', 'http/1.1']];
    }

    /**
     * Frame a handshake message as one record.
     */
    private static function record(string $message): string
    {
        return WireFormat::frameHandshake($message, strlen($message));
    }

    /**
     * The body of a handshake message.
     */
    private static function body(string $message): string
    {
        return substr($message, self::HANDSHAKE_HEADER_LENGTH);
    }

    /**
     * Make the last extension of a ClientHello message, two bytes of data, claim one byte more than that.
     */
    private static function corruptLastExtensionLength(string $message): string
    {
        $data = 2;
        $offset = strlen($message) - $data - 2;

        return substr($message, 0, $offset) . WireFormat::encodeUint16($data + 1) . substr($message, $offset + 2);
    }
}
