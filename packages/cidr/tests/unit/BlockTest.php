<?php

declare(strict_types=1);

namespace Psl\CIDR\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\CIDR\Block;
use Psl\CIDR\Exception\InvalidArgumentException;
use Psl\IP\Address;

final class BlockTest extends TestCase
{
    public function testIpv4ContainsWithinRange(): void
    {
        $block = new Block('192.168.1.0/24');

        static::assertTrue($block->contains('192.168.1.0'));
        static::assertTrue($block->contains('192.168.1.1'));
        static::assertTrue($block->contains('192.168.1.100'));
        static::assertTrue($block->contains('192.168.1.255'));
    }

    public function testIpv4DoesNotContainOutsideRange(): void
    {
        $block = new Block('192.168.1.0/24');

        static::assertFalse($block->contains('192.168.2.1'));
        static::assertFalse($block->contains('10.0.0.1'));
        static::assertFalse($block->contains('192.168.0.255'));
    }

    public function testIpv4LargeRange(): void
    {
        $block = new Block('10.0.0.0/8');

        static::assertTrue($block->contains('10.0.0.1'));
        static::assertTrue($block->contains('10.255.255.255'));
        static::assertFalse($block->contains('11.0.0.1'));
    }

    public function testIpv4SingleHost(): void
    {
        $block = new Block('192.168.1.1/32');

        static::assertTrue($block->contains('192.168.1.1'));
        static::assertFalse($block->contains('192.168.1.2'));
    }

    public function testIpv4ContainsAll(): void
    {
        $block = new Block('0.0.0.0/0');

        static::assertTrue($block->contains('0.0.0.0'));
        static::assertTrue($block->contains('255.255.255.255'));
        static::assertTrue($block->contains('192.168.1.1'));
    }

    public function testIpv6SingleHost(): void
    {
        $block = new Block('::1/128');

        static::assertTrue($block->contains('::1'));
        static::assertFalse($block->contains('::2'));
    }

    public function testIpv6Range(): void
    {
        $block = new Block('2001:db8::/32');

        static::assertTrue($block->contains('2001:db8::1'));
        static::assertTrue($block->contains('2001:db8:ffff:ffff:ffff:ffff:ffff:ffff'));
        static::assertFalse($block->contains('2001:db9::1'));
    }

    public function testIpv4MappedIpv6(): void
    {
        $block = new Block('::ffff:1.2.3.4/128');

        static::assertFalse($block->contains('1.2.3.4'));
        static::assertTrue($block->contains('::ffff:1.2.3.4'));
    }

    #[DataProvider('differentAddressFamilies')]
    public function testDoesNotContainDifferentAddressFamily(string $cidr, string $address): void
    {
        $block = new Block($cidr);

        static::assertFalse($block->contains($address));
        static::assertFalse($block->contains(Address::parse($address)));
    }

    /** @return iterable<array{non-empty-string, non-empty-string}> */
    public static function differentAddressFamilies(): iterable
    {
        yield ['::/0', '10.0.0.1'];
        yield ['::ffff:0:0/96', '10.0.0.1'];
        yield ['0.0.0.0/0', '::ffff:10.0.0.1'];
        yield ['10.0.0.0/8', '::ffff:10.0.0.1'];
        yield ['0.0.0.0/0', '::1'];
    }

    #[DataProvider('blocksWithHostBits')]
    public function testMasksHostBits(string $cidr, string $first, string $last, string $outside): void
    {
        $block = new Block($cidr);

        static::assertTrue($block->contains($first));
        static::assertTrue($block->contains($last));
        static::assertFalse($block->contains($outside));
    }

    /** @return iterable<array{non-empty-string, non-empty-string, non-empty-string, non-empty-string}> */
    public static function blocksWithHostBits(): iterable
    {
        yield ['192.168.1.5/24', '192.168.1.0', '192.168.1.255', '192.168.2.0'];
        yield ['192.168.1.200/25', '192.168.1.128', '192.168.1.255', '192.168.1.127'];
        yield ['2001:db8::1234/120', '2001:db8::1200', '2001:db8::12ff', '2001:db8::1300'];
        yield ['::ffff:192.168.1.5/120', '::ffff:192.168.1.0', '::ffff:192.168.1.255', '::ffff:192.168.2.0'];
    }

    public function testGitHubCidr(): void
    {
        $block = new Block('192.30.252.0/22');

        static::assertTrue($block->contains('192.30.252.0'));
        static::assertTrue($block->contains('192.30.253.1'));
        static::assertTrue($block->contains('192.30.255.255'));
        static::assertFalse($block->contains('192.30.251.255'));
        static::assertFalse($block->contains('192.31.0.0'));
    }

    public function testInvalidCidrNotation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Block('192.168.1.0');
    }

    public function testInvalidIpInCidr(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Block('not-an-ip/24');
    }

    public function testInvalidIpReturnsFalse(): void
    {
        $block = new Block('192.168.1.0/24');

        static::assertFalse($block->contains('not-an-ip'));
    }

    public function testCidrWithExtraSlashThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Block('::1/128/extra');
    }

    public function testNonNumericPrefixThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Block('10.0.0.0/abc');
    }

    public function testNegativePrefixThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Block('10.0.0.0/-1');
    }

    public function testPrefixWithLeadingZerosIsValid(): void
    {
        $block = new Block('10.0.0.0/08');

        static::assertTrue($block->contains('10.0.0.1'));
        static::assertFalse($block->contains('11.0.0.1'));
    }

    public function testIpv6PrefixZeroMatchesAllAddresses(): void
    {
        $block = new Block('::/0');

        static::assertTrue($block->contains('::1'));
        static::assertTrue($block->contains('2001:db8::1'));
        static::assertTrue($block->contains('fe80::1'));
        static::assertTrue($block->contains('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'));
        static::assertFalse($block->contains('192.168.1.1'));
        static::assertFalse($block->contains('255.255.255.255'));
    }

    public function testByteAlignedPrefixProducesCorrectMask(): void
    {
        // /8 for IPv4: 1 full 0xFF byte, 0 remaining bits
        $block = new Block('10.0.0.0/8');

        static::assertTrue($block->contains('10.0.0.1'));
        static::assertTrue($block->contains('10.255.255.255'));
        static::assertFalse($block->contains('11.0.0.0'));

        // /16 for IPv4: 2 full 0xFF bytes, 0 remaining bits
        $block16 = new Block('172.16.0.0/16');

        static::assertTrue($block16->contains('172.16.0.1'));
        static::assertTrue($block16->contains('172.16.255.255'));
        static::assertFalse($block16->contains('172.17.0.0'));

        // /64 for IPv6: 8 full 0xFF bytes, 0 remaining bits
        $block64 = new Block('2001:db8:abcd:1234::/64');

        static::assertTrue($block64->contains('2001:db8:abcd:1234::1'));
        static::assertTrue($block64->contains('2001:db8:abcd:1234:ffff:ffff:ffff:ffff'));
        static::assertFalse($block64->contains('2001:db8:abcd:1235::1'));
    }

    public function testPrefix25ExcludesUpperHalf(): void
    {
        $block = new Block('192.168.1.0/25');

        // Lower half (.0 - .127) should be contained
        static::assertTrue($block->contains('192.168.1.0'));
        static::assertTrue($block->contains('192.168.1.1'));
        static::assertTrue($block->contains('192.168.1.64'));
        static::assertTrue($block->contains('192.168.1.127'));

        // Upper half (.128 - .255) should NOT be contained
        static::assertFalse($block->contains('192.168.1.128'));
        static::assertFalse($block->contains('192.168.1.200'));
        static::assertFalse($block->contains('192.168.1.255'));
    }

    public function testPrefix26CorrectlyMasksSubnet(): void
    {
        $block = new Block('192.168.1.0/26');

        // First quarter (.0 - .63) should be contained
        static::assertTrue($block->contains('192.168.1.0'));
        static::assertTrue($block->contains('192.168.1.63'));

        // Remaining addresses should NOT be contained
        static::assertFalse($block->contains('192.168.1.64'));
        static::assertFalse($block->contains('192.168.1.127'));
        static::assertFalse($block->contains('192.168.1.128'));
    }

    public function testIpv6SmallPrefixContainment(): void
    {
        // /1 prefix: first bit determines membership
        $block = new Block('8000::/1');

        // Addresses with first bit = 1 should be contained
        static::assertTrue($block->contains('8000::1'));
        static::assertTrue($block->contains('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'));

        // Addresses with first bit = 0 should NOT be contained
        static::assertFalse($block->contains('::1'));
        static::assertFalse($block->contains('7fff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'));
    }

    public function testContainsWithAddressObjectV4(): void
    {
        $block = new Block('192.168.1.0/24');

        static::assertTrue($block->contains(Address::v4('192.168.1.100')));
        static::assertTrue($block->contains(Address::v4('192.168.1.0')));
        static::assertTrue($block->contains(Address::v4('192.168.1.255')));
        static::assertFalse($block->contains(Address::v4('192.168.2.1')));
        static::assertFalse($block->contains(Address::v4('10.0.0.1')));
    }

    public function testContainsWithAddressObjectV6(): void
    {
        $block = new Block('2001:db8::/32');

        static::assertTrue($block->contains(Address::v6('2001:db8::1')));
        static::assertTrue($block->contains(Address::v6('2001:db8:ffff:ffff:ffff:ffff:ffff:ffff')));
        static::assertFalse($block->contains(Address::v6('2001:db9::1')));
    }

    public function testContainsWithAddressObjectSingleHost(): void
    {
        $block = new Block('10.20.30.40/32');

        static::assertTrue($block->contains(Address::v4('10.20.30.40')));
        static::assertFalse($block->contains(Address::v4('10.20.30.41')));
    }

    public function testContainsWithParsedAddress(): void
    {
        $block = new Block('192.168.0.0/16');

        static::assertTrue($block->contains(Address::parse('192.168.1.1')));
        static::assertTrue($block->contains(Address::parse('192.168.255.255')));
        static::assertFalse($block->contains(Address::parse('192.169.0.1')));
    }

    public function testContainsWithAddressObjectFromBytes(): void
    {
        $block = new Block('192.168.1.0/24');
        $address = Address::fromBytes("\xc0\xa8\x01\x64"); // 192.168.1.100

        static::assertTrue($block->contains($address));
    }

    public function testIpv4PrefixTooLargeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid prefix length');

        new Block('10.0.0.0/33');
    }

    public function testIpv6Prefix120CorrectMasking(): void
    {
        // /120: 15 full 0xFF bytes + no remaining bits, padded to 16 (or 17)
        $block = new Block('2001:db8::100/120');

        // Addresses in the last byte range .100-.1ff should match based on /120
        static::assertTrue($block->contains('2001:db8::100'));
        static::assertTrue($block->contains('2001:db8::1ff'));

        // Addresses outside the range should NOT match
        static::assertFalse($block->contains('2001:db8::200'));
        static::assertFalse($block->contains('2001:db8::ff'));
    }
}
