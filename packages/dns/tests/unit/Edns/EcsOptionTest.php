<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit\Edns;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Binary\Writer;
use Psl\DNS\EDNS\ECSOption;
use Psl\DNS\Exception\InvalidArgumentException;
use Psl\DNS\Internal\EDNS\EDNSCodec;
use Psl\IP\Address;
use Psl\IP\Family;

final class EcsOptionTest extends TestCase
{
    public function testCode(): void
    {
        $option = new ECSOption(Address::v4('198.51.100.0'), 24, 0);

        static::assertSame(8, $option->code);
    }

    public function testProperties(): void
    {
        $option = new ECSOption(Address::v4('198.51.100.0'), 24, 0);

        static::assertSame(Family::V4, $option->address->family);
        static::assertSame(24, $option->sourcePrefixLength);
        static::assertSame(0, $option->scopePrefixLength);
        static::assertSame('198.51.100.0', $option->address->toString());
    }

    public function testToWireFormatIpv4Prefix24(): void
    {
        $option = new ECSOption(Address::v4('198.51.100.0'), 24, 0);

        $expected = new Writer()
            ->u16(1)
            ->u8(24)
            ->u8(0)
            ->bytes("\xC6\x33\x64")
            ->toString();

        static::assertSame($expected, $option->toWireFormat());
    }

    public function testToWireFormatIpv4Prefix32(): void
    {
        $option = new ECSOption(Address::v4('10.0.0.1'), 32, 0);

        $expected = new Writer()
            ->u16(1)
            ->u8(32)
            ->u8(0)
            ->bytes("\x0A\x00\x00\x01")
            ->toString();

        static::assertSame($expected, $option->toWireFormat());
    }

    public function testToWireFormatIpv4Prefix16(): void
    {
        $option = new ECSOption(Address::v4('192.168.0.0'), 16, 0);

        $expected = new Writer()
            ->u16(1)
            ->u8(16)
            ->u8(0)
            ->bytes("\xC0\xA8")
            ->toString();

        static::assertSame($expected, $option->toWireFormat());
    }

    public function testToWireFormatIpv4PrefixZero(): void
    {
        $option = new ECSOption(Address::v4('0.0.0.0'), 0, 0);

        $expected = new Writer()->u16(1)->u8(0)->u8(0)->toString();

        static::assertSame($expected, $option->toWireFormat());
    }

    public function testToWireFormatIpv6Prefix48(): void
    {
        $option = new ECSOption(Address::v6('2001:db8::'), 48, 0);

        $expected = new Writer()
            ->u16(2)
            ->u8(48)
            ->u8(0)
            ->bytes("\x20\x01\x0D\xB8\x00\x00")
            ->toString();

        static::assertSame($expected, $option->toWireFormat());
    }

    public function testToWireFormatIpv6Prefix128(): void
    {
        $option = new ECSOption(Address::v6('::1'), 128, 0);

        $expected = new Writer()
            ->u16(2)
            ->u8(128)
            ->u8(0)
            ->bytes("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01")
            ->toString();

        static::assertSame($expected, $option->toWireFormat());
    }

    public function testToWireFormatIpv6WithBothSidesOfDoubleColon(): void
    {
        $option = new ECSOption(Address::v6('2001:db8::1'), 128, 0);

        $expected = new Writer()
            ->u16(2)
            ->u8(128)
            ->u8(0)
            ->bytes("\x20\x01\x0D\xB8\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01")
            ->toString();

        static::assertSame($expected, $option->toWireFormat());
    }

    public function testToWireFormatIpv6FullAddress(): void
    {
        $option = new ECSOption(Address::v6('2001:db8:85a3:0:0:8a2e:370:7334'), 128, 0);

        $expected = new Writer()
            ->u16(2)
            ->u8(128)
            ->u8(0)
            ->bytes("\x20\x01\x0D\xB8\x85\xA3\x00\x00\x00\x00\x8A\x2E\x03\x70\x73\x34")
            ->toString();

        static::assertSame($expected, $option->toWireFormat());
    }

    public function testRoundTripIpv4(): void
    {
        $original = new ECSOption(Address::v4('198.51.100.0'), 24, 0);
        $wire = EDNSCodec::encodeOptions([$original]);
        $decoded = EDNSCodec::decodeOptions($wire);

        static::assertCount(1, $decoded);
        $ecs = $decoded[0];
        static::assertInstanceOf(ECSOption::class, $ecs);
        static::assertSame(Family::V4, $ecs->address->family);
        static::assertSame(24, $ecs->sourcePrefixLength);
        static::assertSame(0, $ecs->scopePrefixLength);
        static::assertSame('198.51.100.0', $ecs->address->toString());
    }

    public function testRoundTripIpv6(): void
    {
        $original = new ECSOption(Address::v6('2001:db8::'), 48, 0);
        $wire = EDNSCodec::encodeOptions([$original]);
        $decoded = EDNSCodec::decodeOptions($wire);

        static::assertCount(1, $decoded);
        $ecs = $decoded[0];
        static::assertInstanceOf(ECSOption::class, $ecs);
        static::assertSame(Family::V6, $ecs->address->family);
        static::assertSame(48, $ecs->sourcePrefixLength);
        static::assertSame(0, $ecs->scopePrefixLength);
        static::assertSame('2001:db8::', $ecs->address->toString());
    }

    public function testTrailingBitsZeroedForIpv4(): void
    {
        $option = new ECSOption(Address::v4('198.51.100.255'), 20, 0);

        $expected = new Writer()
            ->u16(1)
            ->u8(20)
            ->u8(0)
            ->bytes("\xC6\x33\x60")
            ->toString();

        static::assertSame($expected, $option->toWireFormat());
    }

    public function testTrailingBitsZeroedForIpv6(): void
    {
        $option = new ECSOption(Address::v6('2001:dbff::'), 20, 0);

        $expected = new Writer()
            ->u16(2)
            ->u8(20)
            ->u8(0)
            ->bytes("\x20\x01\xd0")
            ->toString();

        static::assertSame($expected, $option->toWireFormat());
    }

    public function testToWireFormatIpv4NonMultipleOfEightPrefix(): void
    {
        $option = new ECSOption(Address::v4('198.51.100.0'), 25, 0);

        $expected = new Writer()
            ->u16(1)
            ->u8(25)
            ->u8(0)
            ->bytes("\xC6\x33\x64\x00")
            ->toString();

        static::assertSame($expected, $option->toWireFormat());
    }

    public function testRoundTripWithScopePrefix(): void
    {
        $original = new ECSOption(Address::v4('198.51.100.0'), 24, 20);
        $wire = EDNSCodec::encodeOptions([$original]);
        $decoded = EDNSCodec::decodeOptions($wire);

        static::assertCount(1, $decoded);
        $ecs = $decoded[0];
        static::assertInstanceOf(ECSOption::class, $ecs);
        static::assertSame(24, $ecs->sourcePrefixLength);
        static::assertSame(20, $ecs->scopePrefixLength);
    }

    #[DataProvider('provideSourcePrefixLengthExceedingAddressLength')]
    public function testToWireFormatRejectsSourcePrefixLengthExceedingAddressLength(
        Address $address,
        int $sourcePrefixLength,
        int $addressBits,
    ): void {
        $option = new ECSOption($address, $sourcePrefixLength, 0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Failed to encode ECS option: source prefix length '
            . $sourcePrefixLength
            . ' exceeds the '
            . $addressBits
            . '-bit address.',
        );

        $option->toWireFormat();
    }

    public static function provideSourcePrefixLengthExceedingAddressLength(): iterable
    {
        yield 'ipv4, whole extra byte' => [Address::v4('198.51.100.0'), 40, 32];
        yield 'ipv4, partial extra byte' => [Address::v4('198.51.100.0'), 33, 32];
        yield 'ipv6, partial extra byte' => [Address::v6('2001:db8::'), 129, 128];
        yield 'ipv6, max u8' => [Address::v6('2001:db8::'), 255, 128];
    }
}
