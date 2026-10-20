<?php

declare(strict_types=1);

namespace Psl\IP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Comparison\Exception\IncomparableException;
use Psl\Comparison\Order;
use Psl\IP\Address;
use Psl\IP\Exception\InvalidArgumentException;
use Psl\IP\Family;
use Stringable;

use function strlen;

final class AddressTest extends TestCase
{
    public function testV4ParsesValidAddress(): void
    {
        $address = Address::v4('192.168.1.10');

        static::assertSame(Family::V4, $address->family);
        static::assertSame('192.168.1.10', $address->toString());
    }

    public function testV4ToBytes(): void
    {
        $address = Address::v4('10.20.30.40');

        static::assertSame("\x0a\x14\x1e\x28", $address->toBytes());
    }

    public function testV4ToBytesLength(): void
    {
        $address = Address::v4('0.0.0.0');

        static::assertSame(4, strlen($address->toBytes()));
    }

    public function testV4FromBytesRoundTrip(): void
    {
        $original = Address::v4('192.168.1.10');
        $restored = Address::fromBytes($original->toBytes());

        static::assertSame('192.168.1.10', $restored->toString());
        static::assertSame(Family::V4, $restored->family);
    }

    public function testV4ExpandedStringIsSameAsToString(): void
    {
        $address = Address::v4('10.0.0.1');

        static::assertSame($address->toString(), $address->toExpandedString());
    }

    public function testV4InvalidThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected a valid IPv4 address in dotted-decimal notation (e.g., "192.168.1.1"), got "999.999.999.999".',
        );

        Address::v4('999.999.999.999');
    }

    public function testV4InvalidTextThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected a valid IPv4 address in dotted-decimal notation (e.g., "192.168.1.1"), got "not-an-ip".',
        );

        Address::v4('not-an-ip');
    }

    public function testV6ParsesFull(): void
    {
        $address = Address::v6('2001:0db8:85a3:0000:0000:8a2e:0370:7334');

        static::assertSame(Family::V6, $address->family);
        static::assertSame('2001:db8:85a3::8a2e:370:7334', $address->toString());
    }

    public function testV6ParsesCompressed(): void
    {
        $address = Address::v6('2001:db8::1');

        static::assertSame('2001:db8::1', $address->toString());
    }

    public function testV6ParsesAllZeros(): void
    {
        $address = Address::v6('::');

        static::assertSame('::', $address->toString());
    }

    public function testV6ParsesLoopback(): void
    {
        $address = Address::v6('::1');

        static::assertSame('::1', $address->toString());
    }

    public function testV6ToBytesLength(): void
    {
        $address = Address::v6('::1');

        static::assertSame(16, strlen($address->toBytes()));
    }

    public function testV6ToBytesLoopback(): void
    {
        $address = Address::v6('::1');
        $bytes = $address->toBytes();

        static::assertSame("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01", $bytes);
    }

    public function testV6FromBytesRoundTrip(): void
    {
        $original = Address::v6('2001:db8::1');
        $restored = Address::fromBytes($original->toBytes());

        static::assertSame('2001:db8::1', $restored->toString());
        static::assertSame(Family::V6, $restored->family);
    }

    public function testV6ExpandedString(): void
    {
        $address = Address::v6('2001:db8::1');

        static::assertSame('2001:0db8:0000:0000:0000:0000:0000:0001', $address->toExpandedString());
    }

    public function testV6ExpandedStringAllZeros(): void
    {
        $address = Address::v6('::');

        static::assertSame('0000:0000:0000:0000:0000:0000:0000:0000', $address->toExpandedString());
    }

    public function testV6ExpandedStringFull(): void
    {
        $address = Address::v6('2001:0db8:85a3:0000:0000:8a2e:0370:7334');

        static::assertSame('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $address->toExpandedString());
    }

    public function testV6CompressLongestRun(): void
    {
        $address = Address::v6('2001:db8:0:0:0:0:0:1');

        static::assertSame('2001:db8::1', $address->toString());
    }

    public function testV6CompressFirstRunWins(): void
    {
        $address = Address::v6('2001:0:0:1:0:0:0:1');

        static::assertSame('2001:0:0:1::1', $address->toString());
    }

    public function testV6CompressSingleZeroNotCollapsed(): void
    {
        $address = Address::v6('2001:db8:0:1:1:8a2e:370:7334');

        static::assertSame('2001:db8:0:1:1:8a2e:370:7334', $address->toString());
    }

    public function testV6CompressTrailingZeros(): void
    {
        $address = Address::v6('1:2:3:4:5:0:0:0');

        static::assertSame('1:2:3:4:5::', $address->toString());
    }

    public function testV6CompressLeadingZeros(): void
    {
        $address = Address::v6('0:0:0:1:2:3:4:5');

        static::assertSame('::1:2:3:4:5', $address->toString());
    }

    public function testV6DoubleColonAtEnd(): void
    {
        $address = Address::v6('2001:db8:1::');

        static::assertSame('2001:db8:1::', $address->toString());
        static::assertSame('2001:0db8:0001:0000:0000:0000:0000:0000', $address->toExpandedString());
    }

    public function testV6InvalidThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected a valid IPv6 address (e.g., "2001:db8::1"), got "not-an-ipv6".');

        Address::v6('not-an-ipv6');
    }

    public function testV6Ipv4AddressThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected a valid IPv6 address (e.g., "2001:db8::1"), got "192.168.1.1".');

        Address::v6('192.168.1.1');
    }

    public function testFromBytesInvalidLengthThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected 4 bytes for an IPv4 address or 16 bytes for an IPv6 address, got 3.');

        Address::fromBytes('abc');
    }

    public function testFromBytesZeroLengthThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected 4 bytes for an IPv4 address or 16 bytes for an IPv6 address, got 0.');

        Address::fromBytes('');
    }

    public function testFromBytesFourBytesIsV4(): void
    {
        $address = Address::fromBytes("\xc0\xa8\x01\x01");

        static::assertSame(Family::V4, $address->family);
        static::assertSame('192.168.1.1', $address->toString());
    }

    public function testFromBytesSixteenBytesIsV6(): void
    {
        $address = Address::fromBytes("\x20\x01\x0d\xb8\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01");

        static::assertSame(Family::V6, $address->family);
        static::assertSame('2001:db8::1', $address->toString());
    }

    public function testEqualsTrue(): void
    {
        $a = Address::v4('10.0.0.1');
        $b = Address::v4('10.0.0.1');

        static::assertTrue($a->equals($b));
    }

    public function testEqualsFalse(): void
    {
        $a = Address::v4('10.0.0.1');
        $b = Address::v4('10.0.0.2');

        static::assertFalse($a->equals($b));
    }

    public function testEqualsAcrossFamilies(): void
    {
        $v4 = Address::v4('0.0.0.0');
        $v6 = Address::v6('::');

        static::assertFalse($v4->equals($v6));
    }

    public function testV6EqualsDifferentNotation(): void
    {
        $a = Address::v6('2001:db8::1');
        $b = Address::v6('2001:0db8:0000:0000:0000:0000:0000:0001');

        static::assertTrue($a->equals($b));
    }

    public function testV6NoZeroGroups(): void
    {
        $address = Address::v6('2001:db8:85a3:1:1:8a2e:370:7334');

        static::assertSame('2001:db8:85a3:1:1:8a2e:370:7334', $address->toString());
    }

    public function testV4AllZeros(): void
    {
        $address = Address::v4('0.0.0.0');

        static::assertSame('0.0.0.0', $address->toString());
        static::assertSame("\x00\x00\x00\x00", $address->toBytes());
    }

    public function testV4AllMax(): void
    {
        $address = Address::v4('255.255.255.255');

        static::assertSame('255.255.255.255', $address->toString());
        static::assertSame("\xff\xff\xff\xff", $address->toBytes());
    }

    public function testParseV4(): void
    {
        $address = Address::parse('192.168.1.1');

        static::assertSame(Family::V4, $address->family);
        static::assertSame('192.168.1.1', $address->toString());
    }

    public function testParseV6(): void
    {
        $address = Address::parse('2001:db8::1');

        static::assertSame(Family::V6, $address->family);
        static::assertSame('2001:db8::1', $address->toString());
    }

    public function testParseV6Full(): void
    {
        $address = Address::parse('::1');

        static::assertSame(Family::V6, $address->family);
    }

    public function testParseInvalidThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected a valid IPv4 or IPv6 address, got "not-an-ip".');

        Address::parse('not-an-ip');
    }

    public function testParseEmptyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected a valid IPv4 or IPv6 address, got "".');

        Address::parse('');
    }

    public function testToArpaNameV4(): void
    {
        $address = Address::v4('192.168.1.10');

        static::assertSame('10.1.168.192.in-addr.arpa', $address->toArpaName());
    }

    public function testToArpaNameV4Zeros(): void
    {
        $address = Address::v4('0.0.0.0');

        static::assertSame('0.0.0.0.in-addr.arpa', $address->toArpaName());
    }

    public function testToArpaNameV6(): void
    {
        $address = Address::v6('2001:db8::1');

        static::assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            $address->toArpaName(),
        );
    }

    public function testToArpaNameV6Loopback(): void
    {
        $address = Address::v6('::1');

        static::assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa',
            $address->toArpaName(),
        );
    }

    public function testIsLoopbackV4(): void
    {
        static::assertTrue(Address::v4('127.0.0.1')->isLoopback());
        static::assertTrue(Address::v4('127.255.255.255')->isLoopback());
        static::assertFalse(Address::v4('128.0.0.1')->isLoopback());
        static::assertFalse(Address::v4('10.0.0.1')->isLoopback());
    }

    public function testIsLoopbackV6(): void
    {
        static::assertTrue(Address::v6('::1')->isLoopback());
        static::assertFalse(Address::v6('::2')->isLoopback());
        static::assertFalse(Address::v6('::')->isLoopback());
        static::assertFalse(Address::v6('2001:db8::1')->isLoopback());
    }

    public function testIsPrivateV4(): void
    {
        static::assertTrue(Address::v4('10.0.0.1')->isPrivate());
        static::assertTrue(Address::v4('10.255.255.255')->isPrivate());
        static::assertTrue(Address::v4('172.16.0.1')->isPrivate());
        static::assertTrue(Address::v4('172.31.255.255')->isPrivate());
        static::assertFalse(Address::v4('172.32.0.1')->isPrivate());
        static::assertTrue(Address::v4('192.168.0.1')->isPrivate());
        static::assertTrue(Address::v4('192.168.255.255')->isPrivate());
        static::assertFalse(Address::v4('8.8.8.8')->isPrivate());
        static::assertFalse(Address::v4('1.1.1.1')->isPrivate());
        static::assertFalse(Address::v4('192.0.2.1')->isPrivate());
        static::assertFalse(Address::v4('192.1.0.1')->isPrivate());
    }

    public function testIsPrivateV6(): void
    {
        static::assertTrue(Address::v6('fc00::1')->isPrivate());
        static::assertTrue(Address::v6('fd00::1')->isPrivate());
        static::assertTrue(Address::v6('fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff')->isPrivate());
        static::assertFalse(Address::v6('fe00::1')->isPrivate());
        static::assertFalse(Address::v6('2001:db8::1')->isPrivate());
    }

    public function testIsLinkLocalV4(): void
    {
        static::assertTrue(Address::v4('169.254.0.1')->isLinkLocal());
        static::assertTrue(Address::v4('169.254.255.255')->isLinkLocal());
        static::assertFalse(Address::v4('169.255.0.1')->isLinkLocal());
        static::assertFalse(Address::v4('192.168.1.1')->isLinkLocal());
    }

    public function testIsLinkLocalV6(): void
    {
        static::assertTrue(Address::v6('fe80::1')->isLinkLocal());
        static::assertTrue(Address::v6('fe80::ffff:ffff:ffff:ffff')->isLinkLocal());
        static::assertTrue(Address::v6('fe81::1')->isLinkLocal());
        static::assertTrue(Address::v6('febf::1')->isLinkLocal());
        static::assertFalse(Address::v6('fec0::1')->isLinkLocal());
        static::assertFalse(Address::v6('2001:db8::1')->isLinkLocal());
        static::assertFalse(Address::v6('ff80::1')->isLinkLocal());
    }

    public function testIsMulticastV4(): void
    {
        static::assertTrue(Address::v4('224.0.0.1')->isMulticast());
        static::assertTrue(Address::v4('239.255.255.255')->isMulticast());
        static::assertFalse(Address::v4('223.255.255.255')->isMulticast());
        static::assertFalse(Address::v4('240.0.0.1')->isMulticast());
    }

    public function testIsMulticastV6(): void
    {
        static::assertTrue(Address::v6('ff00::1')->isMulticast());
        static::assertTrue(Address::v6('ff02::1')->isMulticast());
        static::assertTrue(Address::v6('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff')->isMulticast());
        static::assertFalse(Address::v6('fe80::1')->isMulticast());
        static::assertFalse(Address::v6('2001:db8::1')->isMulticast());
    }

    public function testIsUnspecifiedV4(): void
    {
        static::assertTrue(Address::v4('0.0.0.0')->isUnspecified());
        static::assertFalse(Address::v4('0.0.0.1')->isUnspecified());
    }

    public function testIsUnspecifiedV6(): void
    {
        static::assertTrue(Address::v6('::')->isUnspecified());
        static::assertFalse(Address::v6('::1')->isUnspecified());
    }

    public function testIsGlobalUnicastV4(): void
    {
        static::assertTrue(Address::v4('8.8.8.8')->isGlobalUnicast());
        static::assertTrue(Address::v4('1.1.1.1')->isGlobalUnicast());
        static::assertFalse(Address::v4('127.0.0.1')->isGlobalUnicast());
        static::assertFalse(Address::v4('10.0.0.1')->isGlobalUnicast());
        static::assertFalse(Address::v4('169.254.1.1')->isGlobalUnicast());
        static::assertFalse(Address::v4('224.0.0.1')->isGlobalUnicast());
        static::assertFalse(Address::v4('0.0.0.0')->isGlobalUnicast());
        static::assertFalse(Address::v4('192.0.2.1')->isGlobalUnicast());
    }

    public function testIsGlobalUnicastV6(): void
    {
        static::assertTrue(Address::v6('2001:4860:4860::8888')->isGlobalUnicast());
        static::assertFalse(Address::v6('::1')->isGlobalUnicast());
        static::assertFalse(Address::v6('fc00::1')->isGlobalUnicast());
        static::assertFalse(Address::v6('fe80::1')->isGlobalUnicast());
        static::assertFalse(Address::v6('ff02::1')->isGlobalUnicast());
        static::assertFalse(Address::v6('::')->isGlobalUnicast());
        static::assertFalse(Address::v6('2001:db8::1')->isGlobalUnicast());
    }

    public function testIsDocumentationV4(): void
    {
        static::assertTrue(Address::v4('192.0.2.1')->isDocumentation());
        static::assertTrue(Address::v4('192.0.2.255')->isDocumentation());
        static::assertTrue(Address::v4('198.51.100.0')->isDocumentation());
        static::assertTrue(Address::v4('198.51.100.255')->isDocumentation());
        static::assertTrue(Address::v4('203.0.113.0')->isDocumentation());
        static::assertTrue(Address::v4('203.0.113.255')->isDocumentation());
        static::assertFalse(Address::v4('192.0.3.1')->isDocumentation());
        static::assertFalse(Address::v4('8.8.8.8')->isDocumentation());
        static::assertFalse(Address::v4('1.0.2.1')->isDocumentation());
        static::assertFalse(Address::v4('1.51.100.1')->isDocumentation());
        static::assertFalse(Address::v4('1.1.100.1')->isDocumentation());
        static::assertFalse(Address::v4('1.0.113.1')->isDocumentation());
        static::assertFalse(Address::v4('8.8.113.1')->isDocumentation());
    }

    public function testIsDocumentationV6(): void
    {
        static::assertTrue(Address::v6('2001:db8::1')->isDocumentation());
        static::assertTrue(Address::v6('2001:db8:ffff:ffff:ffff:ffff:ffff:ffff')->isDocumentation());
        static::assertFalse(Address::v6('2001:db9::1')->isDocumentation());
        static::assertFalse(Address::v6('2001:4860:4860::8888')->isDocumentation());
        static::assertFalse(Address::v6('1:db8::1')->isDocumentation());
        static::assertFalse(Address::v6('ff00:db8::1')->isDocumentation());
    }

    public function testCompareEqualV4(): void
    {
        $a = Address::v4('10.0.0.1');
        $b = Address::v4('10.0.0.1');

        static::assertSame(Order::Equal, $a->compare($b));
    }

    public function testCompareLessThanV4(): void
    {
        $a = Address::v4('10.0.0.1');
        $b = Address::v4('10.0.0.2');

        static::assertSame(Order::Less, $a->compare($b));
    }

    public function testCompareGreaterThanV4(): void
    {
        $a = Address::v4('10.0.0.2');
        $b = Address::v4('10.0.0.1');

        static::assertSame(Order::Greater, $a->compare($b));
    }

    public function testCompareEqualV6(): void
    {
        $a = Address::v6('2001:db8::1');
        $b = Address::v6('2001:0db8:0000:0000:0000:0000:0000:0001');

        static::assertSame(Order::Equal, $a->compare($b));
    }

    public function testCompareLessThanV6(): void
    {
        $a = Address::v6('2001:db8::1');
        $b = Address::v6('2001:db8::2');

        static::assertSame(Order::Less, $a->compare($b));
    }

    public function testCompareGreaterThanV6(): void
    {
        $a = Address::v6('2001:db8::2');
        $b = Address::v6('2001:db8::1');

        static::assertSame(Order::Greater, $a->compare($b));
    }

    /**
     * Some IPv4 addresses pack to numeric strings ("0012", "  12", "9   ", "10  "), which `<=>`
     * would compare as numbers instead of bytes.
     */
    #[DataProvider('provideNumericStringBytes')]
    public function testCompareAddressesWhoseBytesAreNumericStrings(string $lower, string $higher): void
    {
        $a = Address::v4($lower);
        $b = Address::v4($higher);

        static::assertSame(Order::Less, $a->compare($b));
        static::assertSame(Order::Greater, $b->compare($a));
        static::assertSame(Order::Equal, $a->compare(Address::v4($lower)));
    }

    public static function provideNumericStringBytes(): iterable
    {
        yield 'leading spaces vs leading zeros' => ['32.32.49.50', '48.48.49.50'];
        yield 'trailing spaces' => ['49.48.32.32', '57.32.32.32'];
        yield 'exponent' => ['49.101.49.48', '49.101.50.32'];
    }

    public function testCompareAcrossFamilies(): void
    {
        $v4 = Address::v4('0.0.0.1');
        $v6 = Address::v6('::1');

        // Different byte lengths produce a defined ordering via raw byte comparison.
        $result = $v4->compare($v6);
        static::assertNotSame(Order::Equal, $result);
    }

    public function testCompareNonAddressThrows(): void
    {
        $this->expectException(IncomparableException::class);

        $address = Address::v4('10.0.0.1');
        $address->compare('not an address');
    }

    public function testEqualsNonAddressReturnsFalse(): void
    {
        $address = Address::v4('10.0.0.1');

        static::assertFalse($address->equals('not an address'));
        static::assertFalse($address->equals(42));
        static::assertFalse($address->equals(null));
    }

    public function testToStringV4(): void
    {
        $address = Address::v4('192.168.1.1');

        static::assertSame('192.168.1.1', (string) $address);
    }

    public function testToStringV6(): void
    {
        $address = Address::v6('2001:db8::1');

        static::assertSame('2001:db8::1', (string) $address);
    }

    public function testStringableInterface(): void
    {
        $address = Address::v4('10.0.0.1');

        static::assertInstanceOf(Stringable::class, $address);
        static::assertSame($address->toString(), (string) $address);
    }
}
