<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Psl\DNSSEC\Exception\SignatureFailedException;
use Psl\DNSSEC\Internal\DerEncoder;
use Psl\Str;
use Psl\Str\Byte;
use RuntimeException;

use function is_string;
use function openssl_pkey_get_details;
use function openssl_pkey_get_public;
use function openssl_pkey_new;

use const OPENSSL_KEYTYPE_EC;
use const OPENSSL_KEYTYPE_RSA;

final class DerEncoderTest extends TestCase
{
    public function testRsaPublicKeyToPemProducesValidPem(): void
    {
        $exponent = "\x01\x00\x01";
        $modulus = Str\repeat("\xAB", 128);
        $rawKey = "\x03" . $exponent . $modulus;

        $pem = DerEncoder::rsaPublicKeyToPem($rawKey);

        static::assertStringStartsWith("-----BEGIN PUBLIC KEY-----\n", $pem);
        static::assertStringEndsWith("-----END PUBLIC KEY-----\n", $pem);

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new RuntimeException('Failed to load RSA PEM with OpenSSL');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Failed to get key details from loaded RSA PEM');
        }

        static::assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
    }

    public function testRsaPublicKeyToPemWithLongExponent(): void
    {
        $exponent = Str\repeat("\x01", 300);
        $modulus = Str\repeat("\xCD", 128);
        $rawKey = "\x00\x01\x2C" . $exponent . $modulus;

        $pem = DerEncoder::rsaPublicKeyToPem($rawKey);

        static::assertStringStartsWith("-----BEGIN PUBLIC KEY-----\n", $pem);
        static::assertStringEndsWith("-----END PUBLIC KEY-----\n", $pem);
    }

    public function testEcPublicKeyToPemP256(): void
    {
        $point = self::generateEcRawPublicKey('prime256v1');

        $pem = DerEncoder::ecPublicKeyToPem($point, 32);

        static::assertStringStartsWith("-----BEGIN PUBLIC KEY-----\n", $pem);

        $loadedKey = openssl_pkey_get_public($pem);
        if ($loadedKey === false) {
            throw new RuntimeException('Failed to load EC P-256 PEM with OpenSSL');
        }

        $loadedDetails = openssl_pkey_get_details($loadedKey);
        if ($loadedDetails === false) {
            throw new RuntimeException('Failed to get key details from loaded EC P-256 PEM');
        }

        static::assertSame(OPENSSL_KEYTYPE_EC, $loadedDetails['type']);
    }

    public function testEcPublicKeyToPemP384(): void
    {
        $point = self::generateEcRawPublicKey('secp384r1');

        $pem = DerEncoder::ecPublicKeyToPem($point, 48);

        $loadedKey = openssl_pkey_get_public($pem);
        if ($loadedKey === false) {
            throw new RuntimeException('Failed to load EC P-384 PEM with OpenSSL');
        }

        $loadedDetails = openssl_pkey_get_details($loadedKey);
        if ($loadedDetails === false) {
            throw new RuntimeException('Failed to get key details from loaded EC P-384 PEM');
        }

        static::assertSame(OPENSSL_KEYTYPE_EC, $loadedDetails['type']);
    }

    public function testEcdsaSignatureToDerP256(): void
    {
        $r = Str\repeat("\x01", 32);
        $s = Str\repeat("\x02", 32);
        $rawSig = $r . $s;

        $der = DerEncoder::ecdsaSignatureToDer($rawSig, 32);

        static::assertSame(0x30, Byte\ord($der[0]));

        $offset = 2;
        static::assertSame(0x02, Byte\ord($der[$offset]));
    }

    public function testEcdsaSignatureToDerP384(): void
    {
        $r = Str\repeat("\x03", 48);
        $s = Str\repeat("\x04", 48);
        $rawSig = $r . $s;

        $der = DerEncoder::ecdsaSignatureToDer($rawSig, 48);

        static::assertSame(0x30, Byte\ord($der[0]));
    }

    public function testEcdsaSignatureToDerHandlesHighBit(): void
    {
        $r = "\xFF" . Str\repeat("\x01", 31);
        $s = "\x00" . Str\repeat("\x02", 31);
        $rawSig = $r . $s;

        $der = DerEncoder::ecdsaSignatureToDer($rawSig, 32);

        static::assertSame(0x30, Byte\ord($der[0]));
    }

    public function testEcdsaSignatureToDerExactSize(): void
    {
        $r = Str\repeat("\x7F", 32);
        $s = Str\repeat("\x7F", 32);
        $rawSig = $r . $s;

        $der = DerEncoder::ecdsaSignatureToDer($rawSig, 32);

        static::assertSame(0x30, Byte\ord($der[0]));

        $offset = 2;
        static::assertSame(0x02, Byte\ord($der[$offset]));

        $rLen = Byte\ord($der[$offset + 1]);
        static::assertSame(32, $rLen);
    }

    public function testEcdsaSignatureToDerLargerThanRequired(): void
    {
        $r = Str\repeat("\x01", 32);
        $s = Str\repeat("\x02", 32);
        $rawSig = $r . $s . "\xFF\xFF";

        $der = DerEncoder::ecdsaSignatureToDer($rawSig, 32);

        static::assertSame(0x30, Byte\ord($der[0]));
    }

    public function testUnsupportedCoordinateSizeThrows(): void
    {
        $this->expectException(SignatureFailedException::class);

        DerEncoder::ecPublicKeyToPem('key', 16);
    }

    public function testRsaPublicKeyToPemTooShort(): void
    {
        $this->expectException(SignatureFailedException::class);

        DerEncoder::rsaPublicKeyToPem("\x01\x02");
    }

    public function testRsaPublicKeyToPemRejectsEmptyModulus(): void
    {
        $this->expectException(SignatureFailedException::class);
        $this->expectExceptionMessage('RSA public key must have a non-empty exponent and modulus.');

        // The 2-byte exponent consumes the rest of the key, leaving no modulus.
        DerEncoder::rsaPublicKeyToPem("\x02\x01\x00");
    }

    public function testRsaPublicKeyToPemRejectsEmptyExponent(): void
    {
        $this->expectException(SignatureFailedException::class);
        $this->expectExceptionMessage('RSA public key must have a non-empty exponent and modulus.');

        // A 0x00 length byte introduces a 2-byte exponent length, here zero.
        DerEncoder::rsaPublicKeyToPem("\x00\x00\x00" . Str\repeat("\xAB", 128));
    }

    public function testEcdsaSignatureToDerTooShort(): void
    {
        $this->expectException(SignatureFailedException::class);

        DerEncoder::ecdsaSignatureToDer(Str\repeat("\x00", 32), 32);
    }

    public function testEd448PublicKeyToPemProducesValidPem(): void
    {
        $rawKey = Str\repeat("\x42", 57);
        $pem = DerEncoder::ed448PublicKeyToPem($rawKey);

        static::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pem);
    }

    private static function generateEcRawPublicKey(string $curveName): string
    {
        $coordinateSize = match ($curveName) {
            'prime256v1' => 32,
            'secp384r1' => 48,
            default => throw new RuntimeException('Unsupported curve: ' . $curveName),
        };

        $key = openssl_pkey_new(['curve_name' => $curveName, 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new RuntimeException('Failed to generate EC key pair for curve ' . $curveName);
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Failed to get EC key details');
        }

        if (!isset($details['ec']['x']) || !is_string($details['ec']['x'])) {
            throw new RuntimeException('Missing EC x coordinate in key details');
        }

        if (!isset($details['ec']['y']) || !is_string($details['ec']['y'])) {
            throw new RuntimeException('Missing EC y coordinate in key details');
        }

        $x = Byte\pad_left($details['ec']['x'], $coordinateSize, "\x00");
        $y = Byte\pad_left($details['ec']['y'], $coordinateSize, "\x00");

        return $x . $y;
    }
}
