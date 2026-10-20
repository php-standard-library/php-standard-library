<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Binary\Writer;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\DNSSEC\DigestAlgorithm;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\DSRecord;
use Psl\DNSSEC\Internal\KeyTag;
use Psl\DNSSEC\TrustChainResolver;
use Psl\Hash;
use Psl\Str;
use Psl\Str\Byte;
use ReflectionMethod;

final class DsSepBitTest extends TestCase
{
    public function testAcceptsZoneKeyWithoutSepBit(): void
    {
        $dnskey = new DNSKEYRecord(
            'example.com',
            Duration::seconds(300),
            256,
            3,
            Algorithm::RSASHA256,
            Str\repeat("\x01", 64),
        );
        $ds = self::buildMatchingDs($dnskey, 'example.com');

        static::assertTrue(self::matchDsToKey([$ds], [$dnskey], 'example.com'));
    }

    public function testAcceptsZoneKeyWithSepBit(): void
    {
        $dnskey = new DNSKEYRecord(
            'example.com',
            Duration::seconds(300),
            257,
            3,
            Algorithm::RSASHA256,
            Str\repeat("\x01", 64),
        );
        $ds = self::buildMatchingDs($dnskey, 'example.com');

        static::assertTrue(self::matchDsToKey([$ds], [$dnskey], 'example.com'));
    }

    public function testRejectsFlags0(): void
    {
        $dnskey = new DNSKEYRecord(
            'example.com',
            Duration::seconds(300),
            0,
            3,
            Algorithm::RSASHA256,
            Str\repeat("\x01", 64),
        );
        $ds = self::buildMatchingDs($dnskey, 'example.com');

        static::assertFalse(self::matchDsToKey([$ds], [$dnskey], 'example.com'));
    }

    public function testRejectsSepBitWithoutZoneKeyBit(): void
    {
        $dnskey = new DNSKEYRecord(
            'example.com',
            Duration::seconds(300),
            1,
            3,
            Algorithm::RSASHA256,
            Str\repeat("\x01", 64),
        );
        $ds = self::buildMatchingDs($dnskey, 'example.com');

        static::assertFalse(self::matchDsToKey([$ds], [$dnskey], 'example.com'));
    }

    private static function buildMatchingDs(DNSKEYRecord $dnskey, string $zone): DSRecord
    {
        $ownerWire = self::encodeName(Byte\lowercase($zone));
        $dnskeyRdata = new Writer()
            ->u16($dnskey->flags)
            ->u8($dnskey->protocol)
            ->u8($dnskey->algorithm->value)
            ->bytes($dnskey->publicKey)
            ->toString();

        $digest = Hash\hash($ownerWire . $dnskeyRdata, Hash\Algorithm::Sha256);

        return new DSRecord(
            $zone,
            Duration::seconds(300),
            KeyTag::compute($dnskey),
            $dnskey->algorithm,
            DigestAlgorithm::SHA256,
            $digest,
        );
    }

    private static function encodeName(string $name): string
    {
        $result = '';
        foreach (Byte\split($name, '.') as $label) {
            if ($label === '') {
                continue;
            }

            $result .= Byte\chr(Byte\length($label)) . $label;
        }

        return $result . "\x00";
    }

    /**
     * @param list<DSRecord>     $dsRecords
     * @param list<DNSKEYRecord> $dnskeys
     */
    private static function matchDsToKey(array $dsRecords, array $dnskeys, string $zone): bool
    {
        $method = new ReflectionMethod(TrustChainResolver::class, 'matchDsToKey');

        /** @var bool */
        return $method->invoke(null, $dsRecords, $dnskeys, $zone);
    }
}
