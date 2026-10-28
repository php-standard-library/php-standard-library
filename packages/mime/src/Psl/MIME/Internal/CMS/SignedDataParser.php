<?php

declare(strict_types=1);

namespace Psl\MIME\Internal\CMS;

use Psl\DateTime;
use Psl\DateTime\DateTimeInterface;
use Psl\MIME\Exception\CMSException;
use Psl\MIME\Exception\SMIMEException;
use Psl\MIME\SMIME\DigestAlgorithm;
use Psl\MIME\SMIME\VerificationResult;

use function bin2hex;
use function count;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function mkdir;
use function openssl_cms_verify;
use function openssl_digest;
use function openssl_verify;
use function openssl_x509_checkpurpose;
use function openssl_x509_export;
use function openssl_x509_parse;
use function random_bytes;
use function rmdir;
use function strlen;
use function sys_get_temp_dir;
use function time;
use function unlink;

use const OPENSSL_ALGO_SHA1;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_ALGO_SHA384;
use const OPENSSL_ALGO_SHA512;
use const OPENSSL_CMS_BINARY;
use const OPENSSL_CMS_NOSIGS;
use const OPENSSL_CMS_NOVERIFY;
use const OPENSSL_ENCODING_DER;
use const X509_PURPOSE_SMIME_SIGN;

/**
 * Parses and verifies CMS SignedData structures per RFC 5652 section 5.
 *
 * Decodes a DER-encoded ContentInfo containing SignedData, verifies the signature
 * against the embedded signer certificate, checks the message digest, and optionally
 * validates the certificate chain against trusted CAs.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc5652#section-5
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class SignedDataParser
{
    /**
     * Parse and verify a CMS SignedData ContentInfo.
     *
     * @param string $der DER-encoded ContentInfo.
     * @param list<string> $trustedCertificatesPem Trusted CA certificates in PEM.
     * @param bool $noverify If true, skip certificate trust verification.
     *
     * @throws SMIMEException If verification fails.
     * @throws CMSException If the CMS structure is malformed.
     */
    public static function parse(
        string $der,
        array $trustedCertificatesPem = [],
        bool $noverify = false,
    ): VerificationResult {
        $contentInfo = DERDecoder::parseSequence($der);
        $elements = DERDecoder::parseAll($contentInfo);

        if (count($elements) < 2) {
            throw CMSException::forMalformedStructure('ContentInfo missing fields');
        }

        if ($elements[0][0] !== 0x06) {
            throw CMSException::forMalformedStructure('ContentInfo missing OID');
        }

        if ($elements[0][1] !== OID::SIGNED_DATA) {
            throw CMSException::forMalformedStructure('not SignedData content type');
        }

        if ($elements[1][0] !== 0xA0) {
            throw CMSException::forMalformedStructure('ContentInfo missing [0] EXPLICIT');
        }

        $signedData = DERDecoder::parseSequence($elements[1][1]);
        $sdElements = DERDecoder::parseAll($signedData);

        if (count($sdElements) < 3) {
            throw CMSException::forMalformedStructure('SignedData too short');
        }

        $content = '';
        $certificatesDer = '';
        $signerInfosDer = '';

        foreach ($sdElements as $i => $elem) {
            if ($i === 2) {
                $content = self::extractContent($elem[1]);
            } elseif ($elem[0] === 0xA0 && $i > 2) {
                $certificatesDer = $elem[1];
            } elseif ($elem[0] === 0x31 && $i > 2) {
                $signerInfosDer = $elem[1];
            }
        }

        if ($signerInfosDer === '') {
            throw CMSException::forMalformedStructure('no SignerInfo found');
        }

        $signerResult = self::verifySignerInfo(
            $signerInfosDer,
            $content,
            $certificatesDer,
            $trustedCertificatesPem,
            $noverify,
        );

        return new VerificationResult(
            $content,
            $signerResult['valid'],
            $signerResult['signerCertificate'],
            $signerResult['digestAlgorithm'],
            $signerResult['signingTime'],
        );
    }

    /**
     * Extract the encapsulated content from the EncapsulatedContentInfo structure.
     *
     * Returns the raw content bytes from the OCTET STRING inside the [0] EXPLICIT tag,
     * or an empty string if no content is present (detached signature).
     *
     * @param string $encapContentInfo The inner bytes of the EncapsulatedContentInfo SEQUENCE.
     *
     * @throws CMSException If the content is present but not an OCTET STRING.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc5652#section-5.2
     */
    private static function extractContent(string $encapContentInfo): string
    {
        $elements = DERDecoder::parseAll($encapContentInfo);
        if (count($elements) < 2) {
            return '';
        }

        if ($elements[1][0] !== 0xA0) {
            return '';
        }

        [$tag, $octetContent] = DERDecoder::parse($elements[1][1]);
        if ($tag !== 0x04) {
            throw CMSException::forMalformedStructure('content not an OCTET STRING');
        }

        return $octetContent;
    }

    /**
     * Verify the first SignerInfo in the SignedData structure.
     *
     * Extracts the digest algorithm, signed attributes, and signature value,
     * then verifies the signature and optionally the certificate chain.
     *
     * @param string $signerInfosDer Raw DER bytes from the SignerInfos SET.
     * @param string $content The original content that was signed.
     * @param string $certificatesDer Raw DER bytes from the certificates [0] IMPLICIT field.
     * @param list<string> $trustedCertificatesPem Trusted CA certificates for chain verification.
     * @param bool $noverify If true, skip certificate trust and validity checks.
     *
     * @throws SMIMEException If signature verification or digest comparison fails.
     * @throws CMSException If the SignerInfo structure is malformed.
     *
     * @return array{valid: bool, signerCertificate: null|string, digestAlgorithm: null|DigestAlgorithm, signingTime: null|DateTimeInterface}
     */
    private static function verifySignerInfo(
        string $signerInfosDer,
        string $content,
        string $certificatesDer,
        array $trustedCertificatesPem,
        bool $noverify,
    ): array {
        [$tag, $signerInfoContent] = DERDecoder::parse($signerInfosDer);
        if ($tag !== 0x30) {
            throw CMSException::forMalformedStructure('SignerInfo not a SEQUENCE');
        }

        $siElements = DERDecoder::parseAll($signerInfoContent);
        if (count($siElements) < 5) {
            throw CMSException::forMalformedStructure('SignerInfo too short');
        }

        $signedAttrsDer = '';
        $signedAttrsContent = '';
        $signatureValue = '';
        $digestAlgorithm = null;

        if ($siElements[2][0] === 0x30) {
            $digestAlgElements = DERDecoder::parseAll($siElements[2][1]);
            if ($digestAlgElements !== [] && $digestAlgElements[0][0] === 0x06) {
                $digestAlgorithm = self::oidToDigestAlgorithm($digestAlgElements[0][1]);
            }
        }

        if ($digestAlgorithm === null) {
            throw CMSException::forMalformedStructure('missing or unsupported digest algorithm in SignerInfo');
        }

        foreach ($siElements as $i => $elem) {
            if ($elem[0] === 0xA0 && $i >= 3) {
                $signedAttrsDer = DEREncoder::set($elem[1]);
                $signedAttrsContent = $elem[1];
            } elseif ($elem[0] === 0x04 && $i >= 4) {
                $signatureValue = $elem[1];
            }
        }

        if ($signedAttrsDer === '' || $signatureValue === '') {
            throw CMSException::forMalformedStructure('missing signed attributes or signature');
        }

        ['opensslDigestName' => $opensslDigestName, 'opensslAlgo' => $opensslAlgo] =
            self::digestAlgorithmToOpenSsl($digestAlgorithm);

        $digest = openssl_digest($content, $opensslDigestName, true);
        if ($digest === false) {
            throw SMIMEException::forVerificationFailure();
        }

        $signingTime = null;
        if ($signedAttrsContent !== '') {
            self::verifyMessageDigest($signedAttrsContent, $digest);
            $signingTime = self::extractSigningTime($signedAttrsContent);
        }

        $certificates = self::extractCertificatesFromDer($certificatesDer);
        $certPem = self::findSignerCertificate(
            $certificatesDer,
            DEREncoder::sequence($signerInfoContent),
            DEREncoder::sequence($siElements[2][1]),
            $siElements[1][0] === 0x80 ? "\x03" : "\x01",
        );
        if ($certPem === null) {
            throw CMSException::forMalformedStructure('no certificate matches the SignerInfo identifier');
        }

        $result = openssl_verify($signedAttrsDer, $signatureValue, $certPem, $opensslAlgo);

        if ($result === 1 && !$noverify) {
            self::verifyCertificateValidity($certPem);
            self::verifyCertificateChain($certPem, $trustedCertificatesPem, $certificates);
        }

        return [
            'valid' => $result === 1,
            'signerCertificate' => $certPem,
            'digestAlgorithm' => $digestAlgorithm,
            'signingTime' => $signingTime,
        ];
    }

    /**
     * Verify that the messageDigest signed attribute matches the expected content digest.
     *
     * Searches the signed attributes for the messageDigest OID ({@see OID::MESSAGE_DIGEST})
     * and compares its value to the expected digest.
     *
     * @param string $signedAttrsContent The inner bytes of the signed attributes SET.
     * @param string $expectedDigest The expected raw digest bytes.
     *
     * @throws SMIMEException If the digest does not match.
     * @throws CMSException If the messageDigest attribute is not found.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc5652#section-11.2
     */
    private static function verifyMessageDigest(string $signedAttrsContent, string $expectedDigest): void
    {
        $attrs = DERDecoder::parseAll($signedAttrsContent);
        foreach ($attrs as [$tag, $attrContent]) {
            if ($tag !== 0x30) {
                continue;
            }

            $attrElements = DERDecoder::parseAll($attrContent);
            if (count($attrElements) < 2 || $attrElements[0][0] !== 0x06) {
                continue;
            }

            if ($attrElements[0][1] !== OID::MESSAGE_DIGEST) {
                continue;
            }

            if ($attrElements[1][0] !== 0x31) {
                continue;
            }

            [$innerTag, $digestValue] = DERDecoder::parse($attrElements[1][1]);
            if ($innerTag !== 0x04) {
                continue;
            }

            if ($digestValue !== $expectedDigest) {
                throw SMIMEException::forVerificationFailure();
            }

            return;
        }

        throw CMSException::forMalformedStructure('messageDigest attribute not found');
    }

    /**
     * Map a raw OID value to the corresponding {@see DigestAlgorithm} enum case.
     *
     * @param string $oid Raw OID bytes (from a parsed OBJECT IDENTIFIER element).
     *
     * @return null|DigestAlgorithm The matched algorithm, or null if unrecognized.
     */
    private static function oidToDigestAlgorithm(string $oid): null|DigestAlgorithm
    {
        return match ($oid) {
            OID::SHA1 => DigestAlgorithm::Sha1,
            OID::SHA256 => DigestAlgorithm::Sha256,
            OID::SHA384 => DigestAlgorithm::Sha384,
            OID::SHA512 => DigestAlgorithm::Sha512,
            default => null,
        };
    }

    /**
     * Map a {@see DigestAlgorithm} to the corresponding OpenSSL digest name and algorithm constant.
     *
     * @return array{opensslDigestName: string, opensslAlgo: int}
     */
    private static function digestAlgorithmToOpenSsl(DigestAlgorithm $algorithm): array
    {
        return match ($algorithm) {
            DigestAlgorithm::Sha1 => ['opensslDigestName' => 'sha1', 'opensslAlgo' => OPENSSL_ALGO_SHA1],
            DigestAlgorithm::Sha256 => ['opensslDigestName' => 'sha256', 'opensslAlgo' => OPENSSL_ALGO_SHA256],
            DigestAlgorithm::Sha384 => ['opensslDigestName' => 'sha384', 'opensslAlgo' => OPENSSL_ALGO_SHA384],
            DigestAlgorithm::Sha512 => ['opensslDigestName' => 'sha512', 'opensslAlgo' => OPENSSL_ALGO_SHA512],
        };
    }

    /**
     * Extract the signing time from the signed attributes, if present.
     *
     * Searches for the signingTime OID ({@see OID::SIGNING_TIME}) and parses
     * the UTCTime (tag 0x17) or GeneralizedTime (tag 0x18) value.
     *
     * @param string $signedAttrsContent The inner bytes of the signed attributes SET.
     *
     * @return null|DateTimeInterface The parsed signing time, or null if not found or unparseable.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc5652#section-11.3
     */
    private static function extractSigningTime(string $signedAttrsContent): null|DateTimeInterface
    {
        $attrs = DERDecoder::parseAll($signedAttrsContent);
        foreach ($attrs as [$tag, $attrContent]) {
            if ($tag !== 0x30) {
                continue;
            }

            $attrElements = DERDecoder::parseAll($attrContent);
            if (count($attrElements) < 2 || $attrElements[0][0] !== 0x06) {
                continue;
            }

            if ($attrElements[0][1] !== OID::SIGNING_TIME) {
                continue;
            }

            if ($attrElements[1][0] !== 0x31) {
                continue;
            }

            [$innerTag, $timeValue] = DERDecoder::parse($attrElements[1][1]);
            if ($innerTag !== 0x17 && $innerTag !== 0x18) {
                continue;
            }

            try {
                if ($innerTag === 0x17) {
                    return DateTime\DateTime::parse($timeValue, 'yyMMddHHmmssX');
                }

                return DateTime\DateTime::parse($timeValue, 'yyyyMMddHHmmssX');
            } catch (DateTime\Exception\RuntimeException) {
                return null;
            }
        }

        return null;
    }

    /**
     * Verify the signer certificate is within its validity period.
     *
     * @throws CMSException If the certificate is expired or not yet valid.
     */
    private static function verifyCertificateValidity(string $certPem): void
    {
        $info = openssl_x509_parse($certPem);
        if ($info === false) {
            throw CMSException::forInvalidCertificate('failed to parse certificate');
        }

        $now = time();
        if ($now < $info['validFrom_time_t']) {
            throw CMSException::forInvalidCertificate('certificate is not yet valid');
        }

        if ($now > $info['validTo_time_t']) {
            throw CMSException::forInvalidCertificate('certificate has expired');
        }
    }

    /**
     * Validate the signing certificate and its chain against the given trust anchors.
     *
     * @param list<string> $trustedCertificatesPem
     * @param list<string> $certificatesPem Untrusted certificates embedded in SignedData.
     *
     * @throws CMSException If the chain is not trusted or its files cannot be written.
     */
    private static function verifyCertificateChain(
        string $certPem,
        array $trustedCertificatesPem,
        array $certificatesPem,
    ): void {
        if ($trustedCertificatesPem === []) {
            throw CMSException::forUntrustedCertificate();
        }

        foreach ($trustedCertificatesPem as $index => $trustedCertificate) {
            $normalized = '';
            if (!@openssl_x509_export($trustedCertificate, $normalized)) {
                throw CMSException::forInvalidCertificate('failed to parse trust anchor');
            }

            // Explicit S/MIME trust lets OpenSSL stop at an intermediate CA.
            $trust = DEREncoder::sequence(DEREncoder::sequence(DEREncoder::objectIdentifier(
                "\x2B\x06\x01\x05\x05\x07\x03\x04",
            )));
            $trustedCertificatesPem[$index] = DEREncoder::pemEncode(
                DEREncoder::pemDecode($normalized) . $trust,
                'TRUSTED CERTIFICATE',
            );
        }

        // OpenSSL's PHP chain verifier requires files. A private directory also
        // prevents PHP from adding the system CA directory to the trust store.
        $directory = self::createCertificateDirectory();

        $trustedPath = $directory . '/trusted.pem';
        $untrustedPath = $directory . '/untrusted.pem';
        try {
            $trusted = implode("\n", $trustedCertificatesPem);
            $untrusted = implode("\n", $certificatesPem);
            if (
                @file_put_contents($trustedPath, $trusted) !== strlen($trusted)
                || @file_put_contents($untrustedPath, $untrusted) !== strlen($untrusted)
            ) {
                throw CMSException::forInvalidCertificate('cannot write certificate chain');
            }

            if (
                @openssl_x509_checkpurpose(
                    $certPem,
                    X509_PURPOSE_SMIME_SIGN,
                    [$trustedPath, $directory],
                    $untrustedPath,
                ) !== true
            ) {
                throw CMSException::forUntrustedCertificate();
            }
        } finally {
            @unlink($trustedPath);
            @unlink($untrustedPath);
            @rmdir($directory);
        }
    }

    /**
     * @return list<string> Embedded X.509 certificates in PEM format.
     */
    private static function extractCertificatesFromDer(string $certificatesDer): array
    {
        $certificates = [];
        foreach (DERDecoder::parseAll($certificatesDer) as [$tag, $content]) {
            if ($tag !== 0x30) {
                continue;
            }

            $certificates[] = DEREncoder::pemEncode(DEREncoder::sequence($content), 'CERTIFICATE');
        }

        return $certificates;
    }

    /**
     * Let OpenSSL match the first signer using X.509 name comparison rules.
     *
     * The temporary CMS holds no plaintext. Signature and trust checks use the
     * original data after OpenSSL selects the certificate.
     */
    private static function findSignerCertificate(
        string $certificatesDer,
        string $signerInfoDer,
        string $digestAlgorithmDer,
        string $version,
    ): null|string {
        $signedData = DEREncoder::sequence(
            DEREncoder::integer($version)
                . DEREncoder::set($digestAlgorithmDer)
                . DEREncoder::sequence(
                    DEREncoder::objectIdentifier(OID::DATA) . DEREncoder::contextTag(0, DEREncoder::octetString('')),
                )
                . DEREncoder::contextTag(0, $certificatesDer)
                . DEREncoder::set($signerInfoDer),
        );
        $metadata = DEREncoder::sequence(
            DEREncoder::objectIdentifier(OID::SIGNED_DATA) . DEREncoder::contextTag(0, $signedData),
        );
        $directory = self::createCertificateDirectory();
        $inputPath = $directory . '/signer.der';
        $outputPath = $directory . '/signer.pem';

        try {
            if (@file_put_contents($inputPath, $metadata) !== strlen($metadata)) {
                throw CMSException::forInvalidCertificate('cannot write signer metadata');
            }

            // Without the binary flag, PHP on Windows opens the DER input in text
            // mode, which stops reading at the first 0x1A byte and folds CR LF.
            if (!@openssl_cms_verify(
                $inputPath,
                OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_NOSIGS,
                $outputPath,
                encoding: OPENSSL_ENCODING_DER,
            )) {
                return null;
            }

            $certificate = @file_get_contents($outputPath);
            if ($certificate === false) {
                throw CMSException::forInvalidCertificate('cannot read signer certificate');
            }

            return $certificate;
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
            @rmdir($directory);
        }
    }

    /** @return non-empty-string */
    private static function createCertificateDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/psl-cms-' . bin2hex(random_bytes(16));
        if (!@mkdir($directory, 0o700)) {
            throw CMSException::forInvalidCertificate('cannot create certificate directory');
        }

        return $directory;
    }
}
