<?php

declare(strict_types=1);

namespace Psl\Crypto\Tests\Unit\Hkdf;

use PHPUnit\Framework\TestCase;
use Psl\Crypto\Exception;
use Psl\Crypto\Hkdf;
use Psl\Hash\Hmac;
use Psl\SecureRandom;
use Psl\Str\Byte;

use function hash_hkdf;
use function hex2bin;

/**
 * @see https://tools.ietf.org/html/rfc5869#appendix-A
 */
final class FunctionsTest extends TestCase
{
    public function testRfc5869TestCase1(): void
    {
        $ikm = hex2bin('0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b');
        $salt = hex2bin('000102030405060708090a0b0c');
        $info = hex2bin('f0f1f2f3f4f5f6f7f8f9');
        $length = 42;

        $expectedPrk = hex2bin('077709362c2e32df0ddc3f0dc47bba6390b6c73bb50f9c3122ec844ad7c2b3e5');
        $expectedOkm = hex2bin('3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865');

        $prk = Hkdf\extract($ikm, $salt, Hmac\Algorithm::Sha256);
        static::assertSame($expectedPrk, $prk);

        $okm = Hkdf\expand($prk, $info, $length, Hmac\Algorithm::Sha256);
        static::assertSame($expectedOkm, $okm);

        $derived = Hkdf\derive($ikm, $salt, $info, $length, Hmac\Algorithm::Sha256);
        static::assertSame($expectedOkm, $derived);
    }

    public function testRfc5869TestCase2(): void
    {
        $ikm = hex2bin(
            '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f202122232425262728292a2b2c2d2e2f303132333435363738393a3b3c3d3e3f404142434445464748494a4b4c4d4e4f',
        );
        $salt = hex2bin(
            '606162636465666768696a6b6c6d6e6f707172737475767778797a7b7c7d7e7f808182838485868788898a8b8c8d8e8f909192939495969798999a9b9c9d9e9fa0a1a2a3a4a5a6a7a8a9aaabacadaeaf',
        );
        $info = hex2bin(
            'b0b1b2b3b4b5b6b7b8b9babbbcbdbebfc0c1c2c3c4c5c6c7c8c9cacbcccdcecfd0d1d2d3d4d5d6d7d8d9dadbdcdddedfe0e1e2e3e4e5e6e7e8e9eaebecedeeeff0f1f2f3f4f5f6f7f8f9fafbfcfdfeff',
        );
        $length = 82;

        $expectedPrk = hex2bin('06a6b88c5853361a06104c9ceb35b45cef760014904671014a193f40c15fc244');
        $expectedOkm = hex2bin(
            'b11e398dc80327a1c8e7f78c596a49344f012eda2d4efad8a050cc4c19afa97c59045a99cac7827271cb41c65e590e09da3275600c2f09b8367793a9aca3db71cc30c58179ec3e87c14c01d5c1f3434f1d87',
        );

        $prk = Hkdf\extract($ikm, $salt, Hmac\Algorithm::Sha256);
        static::assertSame($expectedPrk, $prk);

        $okm = Hkdf\expand($prk, $info, $length, Hmac\Algorithm::Sha256);
        static::assertSame($expectedOkm, $okm);
    }

    public function testRfc5869TestCase3(): void
    {
        $ikm = hex2bin('0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b');
        $salt = '';
        $info = '';
        $length = 42;

        $expectedPrk = hex2bin('19ef24a32c717b167f33a91d6f648bdf96596776afdb6377ac434c1c293ccb04');
        $expectedOkm = hex2bin('8da4e775a563c18f715f802a063c5a31b8a11f5c5ee1879ec3454e5f3c738d2d9d201395faa4b61a96c8');

        $prk = Hkdf\extract($ikm, $salt, Hmac\Algorithm::Sha256);
        static::assertSame($expectedPrk, $prk);

        $okm = Hkdf\expand($prk, $info, $length, Hmac\Algorithm::Sha256);
        static::assertSame($expectedOkm, $okm);
    }

    public function testRfc5869TestCase4(): void
    {
        $ikm = hex2bin('0b0b0b0b0b0b0b0b0b0b0b');
        $salt = hex2bin('000102030405060708090a0b0c');
        $info = hex2bin('f0f1f2f3f4f5f6f7f8f9');
        $expectedPrk = hex2bin('9b6c18c432a7bf8f0e71c8eb88f4b30baa2ba243');
        $expectedOkm = hex2bin('085a01ea1b10f36933068b56efa5ad81a4f14b822f5b091568a9cdd4f155fda2c22e422478d305f3f896');

        $prk = Hkdf\extract($ikm, $salt, Hmac\Algorithm::Sha1);

        static::assertSame($expectedPrk, $prk);
        static::assertSame($expectedOkm, Hkdf\expand($prk, $info, 42, Hmac\Algorithm::Sha1));
        static::assertSame($expectedOkm, Hkdf\derive($ikm, $salt, $info, 42, Hmac\Algorithm::Sha1));
    }

    public function testRfc5869TestCase7(): void
    {
        $ikm = hex2bin('0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c');
        $expectedPrk = hex2bin('2adccada18779e7c2077ad2eb19d3f3e731385dd');
        $expectedOkm = hex2bin('2c91117204d745f3500d636a62f64f0ab3bae548aa53d423b0d1f27ebba6f5e5673a081d70cce7acfc48');

        $prk = Hkdf\extract($ikm, algorithm: Hmac\Algorithm::Sha1);

        static::assertSame($expectedPrk, $prk);
        static::assertSame($expectedOkm, Hkdf\expand($prk, length: 42, algorithm: Hmac\Algorithm::Sha1));
    }

    public function testSha1MaximumLength(): void
    {
        static::assertSame(
            hash_hkdf('sha1', 'input key', 255 * 20, 'context'),
            Hkdf\derive('input key', info: 'context', length: 255 * 20, algorithm: Hmac\Algorithm::Sha1),
        );
    }

    public function testSha1RejectsMoreThan255Blocks(): void
    {
        $prk = Hkdf\extract('input key', algorithm: Hmac\Algorithm::Sha1);

        $this->expectException(Exception\RuntimeException::class);
        Hkdf\expand($prk, length: (255 * 20) + 1, algorithm: Hmac\Algorithm::Sha1);
    }

    public function testSha3512MaximumLength(): void
    {
        static::assertSame(
            hash_hkdf('sha3-512', 'input key', 255 * 64, 'context'),
            Hkdf\derive('input key', info: 'context', length: 255 * 64, algorithm: Hmac\Algorithm::Sha3512),
        );
    }

    public function testSha3512RejectsMoreThan255Blocks(): void
    {
        $prk = Hkdf\extract('input key', algorithm: Hmac\Algorithm::Sha3512);

        $this->expectException(Exception\RuntimeException::class);
        Hkdf\expand($prk, length: (255 * 64) + 1, algorithm: Hmac\Algorithm::Sha3512);
    }

    public function testExpandExceedingMaxLengthThrows(): void
    {
        $prk = SecureRandom\bytes(32);

        $this->expectException(Exception\RuntimeException::class);
        Hkdf\expand($prk, '', (255 * 32) + 1, Hmac\Algorithm::Sha256);
    }

    public function testDeriveMatchesExtractThenExpand(): void
    {
        $ikm = SecureRandom\bytes(32);
        $salt = SecureRandom\bytes(16);

        $prk = Hkdf\extract($ikm, $salt, Hmac\Algorithm::Sha256);
        $expanded = Hkdf\expand($prk, 'test-context', 48, Hmac\Algorithm::Sha256);
        $derived = Hkdf\derive($ikm, $salt, 'test-context', 48, Hmac\Algorithm::Sha256);

        static::assertSame($expanded, $derived);
    }

    public function testSha384Derivation(): void
    {
        $ikm = SecureRandom\bytes(32);

        $derived = Hkdf\derive($ikm, 'sha384-salt', 'sha384-info', 48, Hmac\Algorithm::Sha384);
        static::assertSame(48, Byte\length($derived));
    }

    public function testSha512Derivation(): void
    {
        $ikm = SecureRandom\bytes(32);

        $derived = Hkdf\derive($ikm, 'sha512-salt', 'sha512-info', 64, Hmac\Algorithm::Sha512);
        static::assertSame(64, Byte\length($derived));
    }

    public function testDifferentInfoProducesDifferentOutput(): void
    {
        $ikm = SecureRandom\bytes(32);

        $key1 = Hkdf\derive($ikm, 'salt', 'info-one', 32, Hmac\Algorithm::Sha256);
        $key2 = Hkdf\derive($ikm, 'salt', 'info-two', 32, Hmac\Algorithm::Sha256);

        static::assertNotSame($key1, $key2);
    }

    public function testDifferentSaltProducesDifferentOutput(): void
    {
        $ikm = SecureRandom\bytes(32);

        $key1 = Hkdf\derive($ikm, 'salt-one', 'info', 32, Hmac\Algorithm::Sha256);
        $key2 = Hkdf\derive($ikm, 'salt-two', 'info', 32, Hmac\Algorithm::Sha256);

        static::assertNotSame($key1, $key2);
    }

    public function testDifferentAlgorithmsProduceDifferentOutput(): void
    {
        $ikm = SecureRandom\bytes(32);

        $sha256 = Hkdf\derive($ikm, 'salt', 'info', 32, Hmac\Algorithm::Sha256);
        $sha512 = Hkdf\derive($ikm, 'salt', 'info', 32, Hmac\Algorithm::Sha512);

        static::assertNotSame($sha256, $sha512);
    }

    public function testExtractIsDeterministic(): void
    {
        $ikm = SecureRandom\bytes(32);

        $prk1 = Hkdf\extract($ikm, 'det-salt', Hmac\Algorithm::Sha256);
        $prk2 = Hkdf\extract($ikm, 'det-salt', Hmac\Algorithm::Sha256);

        static::assertSame($prk1, $prk2);
    }

    public function testExpandIsDeterministic(): void
    {
        $prk = SecureRandom\bytes(32);

        $okm1 = Hkdf\expand($prk, 'det-info', 32, Hmac\Algorithm::Sha256);
        $okm2 = Hkdf\expand($prk, 'det-info', 32, Hmac\Algorithm::Sha256);

        static::assertSame($okm1, $okm2);
    }

    public function testVariousLengths(): void
    {
        $ikm = SecureRandom\bytes(32);

        foreach ([16, 32, 48, 64, 128] as $length) {
            $derived = Hkdf\derive($ikm, '', '', $length, Hmac\Algorithm::Sha256);
            static::assertSame($length, Byte\length($derived), "Failed for length {$length}");
        }
    }

    public function testExpandMaxLengthSha512(): void
    {
        $prk = SecureRandom\bytes(64);

        $this->expectException(Exception\RuntimeException::class);
        Hkdf\expand($prk, '', (255 * 64) + 1, Hmac\Algorithm::Sha512);
    }
}
