<?php

declare(strict_types=1);

namespace Psl\MIME\Tests\Unit\SMIME;

use PHPUnit\Framework\TestCase;
use Psl\MIME\Exception\CMSException;
use Psl\MIME\Exception\RuntimeException;
use Psl\MIME\Exception\SMIMEException;
use Psl\MIME\SMIME\CipherAlgorithm;
use Psl\MIME\SMIME\Decryptor;
use Psl\MIME\SMIME\DecryptorInterface;
use Psl\MIME\SMIME\Encoding;
use Psl\MIME\SMIME\Encryptor;
use Psl\MIME\SMIME\EncryptorInterface;
use Psl\MIME\SMIME\Signer;
use Psl\MIME\SMIME\SignerInterface;
use Psl\MIME\SMIME\VerificationResult;
use Psl\MIME\SMIME\Verifier;
use Psl\MIME\SMIME\VerifierInterface;
use Psl\MIME\Tests\Fixture\Certificates;
use Psl\Str;
use ReflectionClass;

final class SMIMETest extends TestCase
{
    public function testSignerImplementsInterface(): void
    {
        $signer = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY);

        static::assertInstanceOf(SignerInterface::class, $signer);
    }

    public function testVerifierImplementsInterface(): void
    {
        $verifier = new Verifier();

        static::assertInstanceOf(VerifierInterface::class, $verifier);
    }

    public function testEncryptorImplementsInterface(): void
    {
        $encryptor = new Encryptor([Certificates::PRIMARY_CERT]);

        static::assertInstanceOf(EncryptorInterface::class, $encryptor);
    }

    public function testDecryptorImplementsInterface(): void
    {
        $decryptor = new Decryptor(Certificates::PRIMARY_KEY);

        static::assertInstanceOf(DecryptorInterface::class, $decryptor);
    }

    public function testSignAndVerify(): void
    {
        $signer = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY);
        $signed = $signer->sign('Hello, World!');

        static::assertNotEmpty($signed);

        $verifier = new Verifier();
        $result = $verifier->verify($signed, verifyCertificateChain: false);

        static::assertInstanceOf(VerificationResult::class, $result);
        static::assertTrue($result->valid);
        static::assertSame('Hello, World!', $result->content);
    }

    public function testSignAndVerifySmimeEncoding(): void
    {
        $signer = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY);
        $signed = $signer->sign('SMIME encoding', Encoding::SMIME);

        static::assertNotEmpty($signed);
        static::assertStringContainsString('Content-Type:', $signed);

        $verifier = new Verifier();
        $result = $verifier->verify($signed, Encoding::SMIME, verifyCertificateChain: false);

        static::assertTrue($result->valid);
        static::assertSame('SMIME encoding', $result->content);
    }

    public function testSignAndVerifyDerEncoding(): void
    {
        $signer = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY);
        $signed = $signer->sign('DER encoding', Encoding::DER);

        static::assertNotEmpty($signed);

        $verifier = new Verifier();
        $result = $verifier->verify($signed, Encoding::DER, verifyCertificateChain: false);

        static::assertTrue($result->valid);
        static::assertSame('DER encoding', $result->content);
    }

    public function testSignEmptyContent(): void
    {
        $signer = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY);
        $signed = $signer->sign('');

        $verifier = new Verifier();
        $result = $verifier->verify($signed, verifyCertificateChain: false);

        static::assertTrue($result->valid);
        static::assertSame('', $result->content);
    }

    public function testSignLargeContent(): void
    {
        $content = Str\repeat('A', 10_000);

        $signer = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY);
        $signed = $signer->sign($content);

        $verifier = new Verifier();
        $result = $verifier->verify($signed, verifyCertificateChain: false);

        static::assertTrue($result->valid);
        static::assertSame($content, $result->content);
    }

    public function testSignBinaryContent(): void
    {
        $content = "\x00\x01\x02\xFF\xFE\xFD";

        $signer = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY);
        $signed = $signer->sign($content);

        $verifier = new Verifier();
        $result = $verifier->verify($signed, verifyCertificateChain: false);

        static::assertTrue($result->valid);
        static::assertSame($content, $result->content);
    }

    public function testSignUtf8Content(): void
    {
        $content = "H\xc3\xa9llo W\xc3\xb6rld! \xe6\x97\xa5\xe6\x9c\xac\xe8\xaa\x9e\xe3\x83\x86\xe3\x82\xb9\xe3\x83\x88";

        $signer = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY);
        $signed = $signer->sign($content);

        $verifier = new Verifier();
        $result = $verifier->verify($signed, verifyCertificateChain: false);

        static::assertTrue($result->valid);
        static::assertSame($content, $result->content);
    }

    public function testEncryptAndDecrypt(): void
    {
        $encryptor = new Encryptor([Certificates::PRIMARY_CERT]);
        $encrypted = $encryptor->encrypt('Secret message');

        static::assertNotEmpty($encrypted);

        $decryptor = new Decryptor(Certificates::PRIMARY_KEY);
        $decrypted = $decryptor->decrypt($encrypted);

        static::assertSame('Secret message', $decrypted);
    }

    public function testEncryptAndDecryptAes128(): void
    {
        $encryptor = new Encryptor([Certificates::PRIMARY_CERT], CipherAlgorithm::Aes128Cbc);
        $encrypted = $encryptor->encrypt('AES-128 secret');

        $decryptor = new Decryptor(Certificates::PRIMARY_KEY);
        $decrypted = $decryptor->decrypt($encrypted);

        static::assertSame('AES-128 secret', $decrypted);
    }

    public function testEncryptAndDecryptAes192(): void
    {
        $encryptor = new Encryptor([Certificates::PRIMARY_CERT], CipherAlgorithm::Aes192Cbc);
        $encrypted = $encryptor->encrypt('AES-192 secret');

        $decryptor = new Decryptor(Certificates::PRIMARY_KEY);
        $decrypted = $decryptor->decrypt($encrypted);

        static::assertSame('AES-192 secret', $decrypted);
    }

    public function testEncryptAndDecryptAes256(): void
    {
        $encryptor = new Encryptor([Certificates::PRIMARY_CERT], CipherAlgorithm::Aes256Cbc);
        $encrypted = $encryptor->encrypt('AES-256 secret');

        $decryptor = new Decryptor(Certificates::PRIMARY_KEY);
        $decrypted = $decryptor->decrypt($encrypted);

        static::assertSame('AES-256 secret', $decrypted);
    }

    public function testEncryptEmptyContent(): void
    {
        $encryptor = new Encryptor([Certificates::PRIMARY_CERT]);
        $encrypted = $encryptor->encrypt('');

        $decryptor = new Decryptor(Certificates::PRIMARY_KEY);
        $decrypted = $decryptor->decrypt($encrypted);

        static::assertSame('', $decrypted);
    }

    public function testEncryptLargeContent(): void
    {
        $content = Str\repeat('B', 10_000);

        $encryptor = new Encryptor([Certificates::PRIMARY_CERT]);
        $encrypted = $encryptor->encrypt($content);

        $decryptor = new Decryptor(Certificates::PRIMARY_KEY);
        $decrypted = $decryptor->decrypt($encrypted);

        static::assertSame($content, $decrypted);
    }

    public function testEncryptBinaryContent(): void
    {
        $content = "\x00\x01\x02\xFF\xFE\xFD";

        $encryptor = new Encryptor([Certificates::PRIMARY_CERT]);
        $encrypted = $encryptor->encrypt($content);

        $decryptor = new Decryptor(Certificates::PRIMARY_KEY);
        $decrypted = $decryptor->decrypt($encrypted);

        static::assertSame($content, $decrypted);
    }

    public function testEncryptUtf8Content(): void
    {
        $content = "H\xc3\xa9llo W\xc3\xb6rld! \xe6\x97\xa5\xe6\x9c\xac\xe8\xaa\x9e\xe3\x83\x86\xe3\x82\xb9\xe3\x83\x88";

        $encryptor = new Encryptor([Certificates::PRIMARY_CERT]);
        $encrypted = $encryptor->encrypt($content);

        $decryptor = new Decryptor(Certificates::PRIMARY_KEY);
        $decrypted = $decryptor->decrypt($encrypted);

        static::assertSame($content, $decrypted);
    }

    public function testEncryptAndDecryptPemEncoding(): void
    {
        $encryptor = new Encryptor([Certificates::PRIMARY_CERT]);
        $encrypted = $encryptor->encrypt('PEM encrypted', Encoding::PEM);

        $decryptor = new Decryptor(Certificates::PRIMARY_KEY);
        $decrypted = $decryptor->decrypt($encrypted, Encoding::PEM);

        static::assertSame('PEM encrypted', $decrypted);
    }

    public function testEncryptAndDecryptDerEncoding(): void
    {
        $encryptor = new Encryptor([Certificates::PRIMARY_CERT]);
        $encrypted = $encryptor->encrypt('DER encrypted', Encoding::DER);

        $decryptor = new Decryptor(Certificates::PRIMARY_KEY);
        $decrypted = $decryptor->decrypt($encrypted, Encoding::DER);

        static::assertSame('DER encrypted', $decrypted);
    }

    public function testSignThenEncryptThenDecryptThenVerify(): void
    {
        $signer = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY);
        $signed = $signer->sign('Sign then encrypt', Encoding::DER);

        $encryptor = new Encryptor([Certificates::PRIMARY_CERT]);
        $encrypted = $encryptor->encrypt($signed);

        $decryptor = new Decryptor(Certificates::PRIMARY_KEY);
        $decrypted = $decryptor->decrypt($encrypted);

        $verifier = new Verifier();
        $result = $verifier->verify($decrypted, Encoding::DER, verifyCertificateChain: false);

        static::assertTrue($result->valid);
        static::assertSame('Sign then encrypt', $result->content);
    }

    public function testMultipleRecipientEncryption(): void
    {
        $encryptor = new Encryptor([Certificates::PRIMARY_CERT, Certificates::SECONDARY_CERT]);
        $encrypted = $encryptor->encrypt('For both recipients');

        $decryptor1 = new Decryptor(Certificates::PRIMARY_KEY);
        static::assertSame('For both recipients', $decryptor1->decrypt($encrypted));

        $decryptor2 = new Decryptor(Certificates::SECONDARY_KEY);
        static::assertSame('For both recipients', $decryptor2->decrypt($encrypted));
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $encryptor = new Encryptor([Certificates::PRIMARY_CERT]);
        $encrypted = $encryptor->encrypt('Cannot decrypt');

        $this->expectException(SMIMEException::class);

        $decryptor = new Decryptor(Certificates::SECONDARY_KEY);
        $decryptor->decrypt($encrypted);
    }

    public function testVerifyInvalidInputFails(): void
    {
        $this->expectException(RuntimeException::class);

        $verifier = new Verifier();
        $verifier->verify('not a valid smime message');
    }

    public function testDecryptInvalidInputFails(): void
    {
        $this->expectException(RuntimeException::class);

        $decryptor = new Decryptor(Certificates::PRIMARY_KEY);
        $decryptor->decrypt('not encrypted');
    }

    public function testEncodingCases(): void
    {
        $cases = Encoding::cases();

        static::assertCount(3, $cases);
        static::assertSame(Encoding::SMIME, Encoding::SMIME);
        static::assertSame(Encoding::DER, Encoding::DER);
        static::assertSame(Encoding::PEM, Encoding::PEM);
    }

    public function testCipherAlgorithmCases(): void
    {
        $cases = CipherAlgorithm::cases();

        static::assertCount(3, $cases);
        static::assertSame(CipherAlgorithm::Aes128Cbc, CipherAlgorithm::Aes128Cbc);
        static::assertSame(CipherAlgorithm::Aes192Cbc, CipherAlgorithm::Aes192Cbc);
        static::assertSame(CipherAlgorithm::Aes256Cbc, CipherAlgorithm::Aes256Cbc);
    }

    public function testVerificationResultProperties(): void
    {
        $result = new VerificationResult('content', true);

        static::assertSame('content', $result->content);
        static::assertTrue($result->valid);
    }

    public function testVerificationResultInvalid(): void
    {
        $result = new VerificationResult('', false);

        static::assertSame('', $result->content);
        static::assertFalse($result->valid);
    }

    public function testVerifyExpiredCertThrows(): void
    {
        $signer = new Signer(Certificates::EXPIRED_CERT, Certificates::EXPIRED_KEY);
        $signed = $signer->sign('Expired cert message');

        $verifier = new Verifier();

        $this->expectException(CMSException::class);
        $verifier->verify($signed, verifyCertificateChain: true);
    }

    public function testVerifyExpiredCertSkippedWithNoverify(): void
    {
        $signer = new Signer(Certificates::EXPIRED_CERT, Certificates::EXPIRED_KEY);
        $signed = $signer->sign('Expired but noverify');

        $verifier = new Verifier();
        $result = $verifier->verify($signed, verifyCertificateChain: false);

        static::assertTrue($result->valid);
        static::assertSame('Expired but noverify', $result->content);
    }

    public function testSignWithExtraCertificates(): void
    {
        $signer = new Signer(
            Certificates::PRIMARY_CERT,
            Certificates::PRIMARY_KEY,
            extraCertificates: [Certificates::SECONDARY_CERT],
        );
        $signed = $signer->sign('With extra certs');

        $verifier = new Verifier();
        $result = $verifier->verify($signed, verifyCertificateChain: false);

        static::assertTrue($result->valid);
        static::assertSame('With extra certs', $result->content);
    }

    public function testVerifyWithTrustedCA(): void
    {
        $signer = new Signer(Certificates::CA_SIGNED_CERT, Certificates::CA_SIGNED_KEY);
        $signed = $signer->sign('Trusted message');

        $verifier = new Verifier([Certificates::CA_CERT]);
        $result = $verifier->verify($signed, verifyCertificateChain: true);

        static::assertTrue($result->valid);
        static::assertSame('Trusted message', $result->content);
    }

    public function testVerifyUntrustedCertThrows(): void
    {
        $signer = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY);
        $signed = $signer->sign('Untrusted message');

        $verifier = new Verifier([Certificates::UNRELATED_CA_CERT]);

        $this->expectException(CMSException::class);
        $verifier->verify($signed, verifyCertificateChain: true);
    }

    public function testVerifyChainSkippedWhenNoverify(): void
    {
        $signer = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY);
        $signed = $signer->sign('Skip chain check');

        $verifier = new Verifier([Certificates::UNRELATED_CA_CERT]);
        $result = $verifier->verify($signed, verifyCertificateChain: false);

        static::assertTrue($result->valid);
        static::assertSame('Skip chain check', $result->content);
    }

    public function testVerifySelfSignedWithEmptyTrustedCertsThrows(): void
    {
        $signer = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY);
        $signed = $signer->sign('Self-signed message');

        $verifier = new Verifier();
        $this->expectException(CMSException::class);
        $verifier->verify($signed, verifyCertificateChain: true);
    }

    public function testVerifyNonSelfSignedWithEmptyTrustedCertsThrows(): void
    {
        $signer = new Signer(Certificates::CA_SIGNED_CERT, Certificates::CA_SIGNED_KEY);
        $signed = $signer->sign('CA-issued but no trusted CAs');

        $verifier = new Verifier();

        $this->expectException(CMSException::class);
        $verifier->verify($signed, verifyCertificateChain: true);
    }

    public function testNoOpenSslTypesInConstructors(): void
    {
        $classes = [Signer::class, Verifier::class, Encryptor::class, Decryptor::class];
        foreach ($classes as $class) {
            $ref = new ReflectionClass($class);
            $constructor = $ref->getConstructor();
            static::assertNotNull($constructor);
            foreach ($constructor->getParameters() as $param) {
                $type = (string) $param->getType();
                static::assertStringNotContainsString('OpenSSL', $type, "{$class} should not use OpenSSL types");
            }
        }
    }

    public function testVerifyDefaultChainCheckIsTrue(): void
    {
        $signer = new Signer(Certificates::CA_SIGNED_CERT, Certificates::CA_SIGNED_KEY);
        $signed = $signer->sign('Default chain check');

        $verifier = new Verifier();

        $this->expectException(CMSException::class);
        $verifier->verify($signed);
    }
}
