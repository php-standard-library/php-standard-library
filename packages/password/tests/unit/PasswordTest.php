<?php

declare(strict_types=1);

namespace Psl\Password\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Password;
use Psl\SecureRandom;
use Psl\Str;
use SensitiveParameter;

use const PASSWORD_ARGON2I;
use const PASSWORD_ARGON2ID;
use const PASSWORD_BCRYPT;
use const PASSWORD_DEFAULT;

final class PasswordTest extends TestCase
{
    #[DataProvider('providePasswords')]
    public function testDefault(#[SensitiveParameter] string $password): void
    {
        $hash = Password\hash($password, Password\Algorithm::default(), [
            'cost' => 4,
        ]);

        static::assertTrue(Password\verify($password, $hash));

        static::assertFalse(Password\needs_rehash($hash, Password\Algorithm::default(), [
            'cost' => 4,
        ]));
    }

    #[DataProvider('providePasswords')]
    public function testBcrypt(#[SensitiveParameter] string $password): void
    {
        $hash = Password\hash($password, Password\Algorithm::Bcrypt, [
            'cost' => 4,
        ]);

        static::assertTrue(Password\verify($password, $hash));

        $information = Password\get_information($hash);
        static::assertSame(Password\Algorithm::Bcrypt, $information['algorithm']);
        static::assertSame(4, $information['options']['cost']);

        static::assertFalse(Password\needs_rehash($hash, Password\Algorithm::Bcrypt, [
            'cost' => 4,
        ]));
    }

    #[DataProvider('providePasswords')]
    public function testArgon2i(#[SensitiveParameter] string $password): void
    {
        $options = ['memory_cost' => 8192];
        $hash = Password\hash($password, Password\Algorithm::Argon2i, $options);

        static::assertTrue(Password\verify($password, $hash));

        $information = Password\get_information($hash);

        static::assertSame(Password\Algorithm::Argon2i, $information['algorithm']);

        static::assertFalse(Password\needs_rehash($hash, Password\Algorithm::Argon2i, $options));
    }

    #[DataProvider('providePasswords')]
    public function testArgon2id(#[SensitiveParameter] string $password): void
    {
        $options = ['memory_cost' => 8192];
        $hash = Password\hash($password, Password\Algorithm::Argon2id, $options);

        static::assertTrue(Password\verify($password, $hash));

        $information = Password\get_information($hash);

        static::assertSame(Password\Algorithm::Argon2id, $information['algorithm']);

        static::assertFalse(Password\needs_rehash($hash, Password\Algorithm::Argon2id, $options));
    }

    #[DataProvider('provideOtherBcryptPrefixes')]
    public function testGetInformationReportsBcryptForOtherBcryptPrefixes(string $prefix): void
    {
        $hash = $prefix . Str\slice(Password\hash('hunter2', Password\Algorithm::Bcrypt, ['cost' => 5]), 4);

        static::assertTrue(Password\verify('hunter2', $hash));

        $information = Password\get_information($hash);
        static::assertSame(Password\Algorithm::Bcrypt, $information['algorithm']);
        static::assertSame(5, $information['options']['cost']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOtherBcryptPrefixes(): iterable
    {
        yield '$2a$' => ['$2a$'];
        yield '$2b$' => ['$2b$'];
    }

    #[DataProvider('provideUnsupportedHashes')]
    public function testGetInformationThrowsForUnsupportedHashes(string $hash): void
    {
        $this->expectException(Password\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a hash created by a supported password hashing algorithm');

        Password\get_information($hash);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnsupportedHashes(): iterable
    {
        yield 'empty string' => [''];
        yield 'plain text' => ['hunter2'];
        yield 'md5-crypt' => ['$1$abcdefgh$ME42TpQ6Nwr.rR3PPKY.Y0'];
        yield 'sha512-crypt' => [
            '$6$saltsalt$qFmFH.bQmmtXzyBY0s9v7Oicd2z4XSIecDzlB5KiA2/jctKu9YterLp8wwnSq.qc.eoxqOmSuNp2xS0ktL3nh/',
        ];
        yield 'truncated bcrypt' => ['$2y$10$abc'];
        yield 'truncated $2b$ bcrypt' => ['$2b$10$abc'];
        yield '$2b$ bcrypt with a trailing line feed' => ["\$2b\$10\$" . Str\repeat('a', 53) . "\n"];
        yield '$2x$ bcrypt' => ['$2x$10$' . Str\repeat('a', 53)];
    }

    public function testArgon2idBuiltinConstantValue(): void
    {
        static::assertSame(PASSWORD_ARGON2ID, Password\Algorithm::Argon2id->getBuiltinConstantValue());
    }

    public function testAllAlgorithmBuiltinConstantValues(): void
    {
        static::assertSame(PASSWORD_DEFAULT, Password\Algorithm::Default->getBuiltinConstantValue());
        static::assertSame(PASSWORD_BCRYPT, Password\Algorithm::Bcrypt->getBuiltinConstantValue());
        static::assertSame(PASSWORD_ARGON2I, Password\Algorithm::Argon2i->getBuiltinConstantValue());
        static::assertSame(PASSWORD_ARGON2ID, Password\Algorithm::Argon2id->getBuiltinConstantValue());
    }

    public static function providePasswords(): iterable
    {
        yield ['hunter2'];
        yield [SecureRandom\string(64)];
        yield [SecureRandom\string(32, Str\ALPHABET_ALPHANUMERIC . '&"\'{([-|`\_^@])}=+£¨%!/;.,:')];
        yield [SecureRandom\string(16, Str\ALPHABET_ALPHANUMERIC . '&"\'{([-|`\_^@])}=+£¨%!/;.,:')];
    }
}
