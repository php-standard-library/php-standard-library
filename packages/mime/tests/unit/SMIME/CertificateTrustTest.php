<?php

declare(strict_types=1);

namespace Psl\MIME\Tests\Unit\SMIME;

use PHPUnit\Framework\TestCase;
use Psl\MIME\Exception\CMSException;
use Psl\MIME\Internal\CMS\DERDecoder;
use Psl\MIME\Internal\CMS\DEREncoder;
use Psl\MIME\SMIME\Encoding;
use Psl\MIME\SMIME\Signer;
use Psl\MIME\SMIME\Verifier;
use Psl\MIME\Tests\Fixture\Certificates;

use function array_map;
use function fclose;
use function fwrite;
use function hex2bin;
use function implode;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_get_private;
use function openssl_x509_export;
use function openssl_x509_parse;
use function str_replace;
use function stream_get_meta_data;
use function tmpfile;

final class CertificateTrustTest extends TestCase
{
    public function testSelectsSignerAfterAnotherCertificate(): void
    {
        $signed = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY)->sign('signed', Encoding::DER);
        $signed = self::replaceCertificates($signed, [Certificates::SECONDARY_CERT, Certificates::PRIMARY_CERT]);
        $result = new Verifier([Certificates::PRIMARY_CERT])->verify($signed, Encoding::DER);

        static::assertTrue($result->valid);
        static::assertSame('signed', $result->content);
        static::assertSame(
            DEREncoder::pemDecode(Certificates::PRIMARY_CERT),
            DEREncoder::pemDecode($result->signerCertificate),
        );
    }

    public function testRejectsWrongSignerIdentifierEvenWhenSignatureMatches(): void
    {
        $signed = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY)->sign('signed', Encoding::DER);
        $signed = self::replaceSignerIdentifier(
            $signed,
            DEREncoder::sequence(DEREncoder::sequence('') . DEREncoder::integer("\x01")),
        );

        $this->expectException(CMSException::class);
        new Verifier()->verify($signed, Encoding::DER, verifyCertificateChain: false);
    }

    public function testSelectsSignerBySubjectKeyIdentifier(): void
    {
        $signed = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY)->sign('signed', Encoding::DER);
        $info = openssl_x509_parse(Certificates::PRIMARY_CERT);
        static::assertIsArray($info);
        $identifier = hex2bin(str_replace(':', '', $info['extensions']['subjectKeyIdentifier']));
        static::assertIsString($identifier);
        $signed = self::replaceSignerIdentifier($signed, DEREncoder::tag(0x80, $identifier));
        $signed = self::replaceCertificates($signed, [Certificates::SECONDARY_CERT, Certificates::PRIMARY_CERT]);

        static::assertTrue(new Verifier([Certificates::PRIMARY_CERT])->verify($signed, Encoding::DER)->valid);
    }

    public function testMatchesEquivalentIssuerStringEncodings(): void
    {
        $signed = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY)->sign('issuer', Encoding::DER);
        $certificate = DERDecoder::parseSequence(DEREncoder::pemDecode(Certificates::PRIMARY_CERT));
        $fields = DERDecoder::parseAll(DERDecoder::parseSequence($certificate));
        $issuer = '';
        foreach (DERDecoder::parseAll($fields[3][1]) as [$tag, $rdn]) {
            $attributes = '';
            foreach (DERDecoder::parseAll($rdn) as [$attributeTag, $attribute]) {
                $values = DERDecoder::parseAll($attribute);
                if ($values[1][0] === 0x0C) {
                    $values[1][0] = 0x13;
                }

                $attributes .= DEREncoder::tag($attributeTag, self::encodeFields($values));
            }

            $issuer .= DEREncoder::tag($tag, $attributes);
        }

        static::assertNotSame($fields[3][1], $issuer);
        $signed = self::replaceSignerIdentifier(
            $signed,
            DEREncoder::sequence(DEREncoder::sequence($issuer) . DEREncoder::tag(...$fields[1])),
        );

        static::assertTrue(new Verifier([Certificates::PRIMARY_CERT])->verify($signed, Encoding::DER)->valid);
    }

    public function testOnlyChecksTheFirstSignerInfo(): void
    {
        $signed = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY)->sign('first', Encoding::DER);
        $other = self::replaceSignerIdentifier(
            $signed,
            DEREncoder::sequence(DEREncoder::sequence('') . DEREncoder::integer("\x01")),
        );
        $otherOuter = DERDecoder::parseAll(DERDecoder::parseSequence($other));
        $otherFields = DERDecoder::parseAll(DERDecoder::parseSequence($otherOuter[1][1]));
        $outer = DERDecoder::parseAll(DERDecoder::parseSequence($signed));
        $fields = DERDecoder::parseAll(DERDecoder::parseSequence($outer[1][1]));
        $fields[4][1] .= $otherFields[4][1];
        $signed = DEREncoder::sequence(
            DEREncoder::tag(...$outer[0])
                . DEREncoder::contextTag(0, DEREncoder::sequence(self::encodeFields($fields))),
        );

        $result = new Verifier([Certificates::PRIMARY_CERT])->verify($signed, Encoding::DER);

        static::assertTrue($result->valid);
        static::assertSame('first', $result->content);
    }

    public function testBuildsChainThroughEmbeddedIntermediate(): void
    {
        [$root, $intermediate, $leaf] = self::chain();
        $signed = new Signer($leaf, Certificates::CA_SIGNED_KEY, extraCertificates: [$intermediate])->sign('chain');
        $result = new Verifier([$root])->verify($signed);

        static::assertTrue($result->valid);
        static::assertSame('chain', $result->content);
    }

    public function testAcceptsAnExplicitlyTrustedIntermediate(): void
    {
        [, $intermediate, $leaf] = self::chain();
        $signed = new Signer($leaf, Certificates::CA_SIGNED_KEY)->sign('trusted intermediate');
        $result = new Verifier([$intermediate])->verify($signed);

        static::assertTrue($result->valid);
        static::assertSame('trusted intermediate', $result->content);
    }

    public function testAcceptsATrustAnchorWithATextPreamble(): void
    {
        static::assertTrue(openssl_x509_export(Certificates::PRIMARY_CERT, $trusted, false));
        static::assertStringStartsWith('Certificate:', $trusted);
        $signed = new Signer(Certificates::PRIMARY_CERT, Certificates::PRIMARY_KEY)->sign('text preamble');
        $result = new Verifier([$trusted])->verify($signed);

        static::assertTrue($result->valid);
        static::assertSame('text preamble', $result->content);
    }

    public function testEmbeddedRootIsNotATrustAnchor(): void
    {
        [$root, $intermediate, $leaf] = self::chain();
        $signed = new Signer($leaf, Certificates::CA_SIGNED_KEY, extraCertificates: [$intermediate, $root])->sign(
            'chain',
        );

        $this->expectException(CMSException::class);
        new Verifier([Certificates::UNRELATED_CA_CERT])->verify($signed);
    }

    public function testRejectsIntermediateWithoutCaConstraint(): void
    {
        [$root, $intermediate, $leaf] = self::chain(false);
        $signed = new Signer($leaf, Certificates::CA_SIGNED_KEY, extraCertificates: [$intermediate])->sign('chain');

        $this->expectException(CMSException::class);
        new Verifier([$root])->verify($signed);
    }

    public function testRejectsExpiredIntermediate(): void
    {
        [$root, $intermediate, $leaf] = self::chain(true, 0);
        $signed = new Signer($leaf, Certificates::CA_SIGNED_KEY, extraCertificates: [$intermediate])->sign('chain');

        $this->expectException(CMSException::class);
        new Verifier([$root])->verify($signed);
    }

    /** @return array{string, string, string} */
    private static function chain(bool $intermediateCa = true, int $intermediateDays = 1): array
    {
        $config = tmpfile();
        static::assertIsResource($config);
        try {
            fwrite(
                $config,
                "[req]\ndistinguished_name = dn\n[dn]\n[ca]\nbasicConstraints = critical,CA:TRUE\nkeyUsage = critical,keyCertSign,cRLSign\n[leaf]\nbasicConstraints = critical,CA:FALSE\nkeyUsage = critical,digitalSignature\nextendedKeyUsage = emailProtection\n",
            );
            $path = stream_get_meta_data($config)['uri'];
            $root = self::certificate(
                'root',
                Certificates::PRIMARY_KEY,
                null,
                Certificates::PRIMARY_KEY,
                $path,
                'ca',
                1,
                1,
            );
            $intermediate = self::certificate(
                'intermediate',
                Certificates::SECONDARY_KEY,
                $root,
                Certificates::PRIMARY_KEY,
                $path,
                $intermediateCa ? 'ca' : 'leaf',
                $intermediateDays,
                2,
            );
            $leaf = self::certificate(
                'leaf',
                Certificates::CA_SIGNED_KEY,
                $intermediate,
                Certificates::SECONDARY_KEY,
                $path,
                'leaf',
                1,
                3,
            );

            return [$root, $intermediate, $leaf];
        } finally {
            fclose($config);
        }
    }

    private static function certificate(
        string $name,
        string $keyPem,
        null|string $issuer,
        string $issuerKeyPem,
        string $config,
        string $extensions,
        int $days,
        int $serial,
    ): string {
        $key = openssl_pkey_get_private($keyPem);
        static::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => $name], $key, ['config' => $config, 'digest_alg' => 'sha256']);
        static::assertNotFalse($csr);
        $certificate = openssl_csr_sign(
            $csr,
            $issuer,
            $issuerKeyPem,
            $days,
            ['config' => $config, 'x509_extensions' => $extensions, 'digest_alg' => 'sha256'],
            $serial,
        );
        static::assertNotFalse($certificate);
        static::assertTrue(openssl_x509_export($certificate, $pem));

        return $pem;
    }

    /** @param list<string> $certificates */
    private static function replaceCertificates(string $der, array $certificates): string
    {
        $outer = DERDecoder::parseAll(DERDecoder::parseSequence($der));
        $fields = DERDecoder::parseAll(DERDecoder::parseSequence($outer[1][1]));
        $fields[3] = [0xA0, implode('', array_map(DEREncoder::pemDecode(...), $certificates))];

        return DEREncoder::sequence(
            DEREncoder::tag(...$outer[0])
                . DEREncoder::contextTag(0, DEREncoder::sequence(self::encodeFields($fields))),
        );
    }

    private static function replaceSignerIdentifier(string $der, string $identifier): string
    {
        $outer = DERDecoder::parseAll(DERDecoder::parseSequence($der));
        $fields = DERDecoder::parseAll(DERDecoder::parseSequence($outer[1][1]));
        $signer = DERDecoder::parseAll(DERDecoder::parseSequence($fields[4][1]));
        [$tag, $content] = DERDecoder::parse($identifier);
        $signer[0] = [0x02, $tag === 0x80 ? "\x03" : "\x01"];
        $signer[1] = [$tag, $content];
        $fields[4] = [0x31, DEREncoder::sequence(self::encodeFields($signer))];

        return DEREncoder::sequence(
            DEREncoder::tag(...$outer[0])
                . DEREncoder::contextTag(0, DEREncoder::sequence(self::encodeFields($fields))),
        );
    }

    /** @param list<array{int, string}> $fields */
    private static function encodeFields(array $fields): string
    {
        return implode('', array_map(static fn(array $field): string => DEREncoder::tag(...$field), $fields));
    }
}
