<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Internal;

use Psl\Crypto\Signing;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\Exception\InvalidArgumentException;

use function openssl_pkey_get_public;
use function openssl_verify;

use const OPENSSL_ALGO_SHA1;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_ALGO_SHA384;
use const OPENSSL_ALGO_SHA512;

/**
 * Dispatches DNSSEC signature verification to the appropriate crypto backend.
 *
 * Ed25519 uses PSL's sodium-based Verifier. RSA and ECDSA use openssl_verify().
 *
 * @internal
 */
final class SignatureVerifier
{
    /**
     * Verify a DNSSEC signature over the given data using the provided public key.
     *
     * @param string $signature The raw binary signature.
     * @param string $data The signed data.
     * @param string $publicKey The raw binary public key from the DNSKEY record.
     *
     * @throws InvalidArgumentException If the key format is unsupported or invalid.
     * @throws \Psl\Crypto\Exception\InvalidArgumentException If the public key or signature length is invalid.
     */
    public static function verify(Algorithm $algorithm, string $signature, string $data, string $publicKey): bool
    {
        return match ($algorithm) {
            Algorithm::ED25519 => self::verifyEd25519($signature, $data, $publicKey),
            Algorithm::ED448 => self::verifyEd448($signature, $data, $publicKey),
            Algorithm::RSASHA1, Algorithm::RSASHA1_NSEC3_SHA1 => self::verifyRsa(
                $signature,
                $data,
                $publicKey,
                OPENSSL_ALGO_SHA1,
            ),
            Algorithm::RSASHA256 => self::verifyRsa($signature, $data, $publicKey, OPENSSL_ALGO_SHA256),
            Algorithm::RSASHA512 => self::verifyRsa($signature, $data, $publicKey, OPENSSL_ALGO_SHA512),
            Algorithm::ECDSAP256SHA256 => self::verifyEcdsa($signature, $data, $publicKey, 32, OPENSSL_ALGO_SHA256),
            Algorithm::ECDSAP384SHA384 => self::verifyEcdsa($signature, $data, $publicKey, 48, OPENSSL_ALGO_SHA384),
        };
    }

    /**
     * Verify an Ed448 signature using openssl_verify().
     */
    private static function verifyEd448(string $signature, string $data, string $publicKey): bool
    {
        $pem = DerEncoder::ed448PublicKeyToPem($publicKey);
        $key = openssl_pkey_get_public($pem);

        return $key !== false && openssl_verify($data, $signature, $key, 0) === 1;
    }

    /**
     * @throws \Psl\Crypto\Exception\InvalidArgumentException If the public key or signature length is invalid.
     */
    private static function verifyEd25519(string $signature, string $data, string $publicKey): bool
    {
        /** @var non-empty-string $publicKey */
        $verifier = new Signing\Verifier(new Signing\PublicKey($publicKey));

        /** @var non-empty-string $signature */
        return $verifier->verify(new Signing\Signature($signature), $data);
    }

    /**
     * @throws InvalidArgumentException If the key is too short.
     */
    private static function verifyRsa(string $signature, string $data, string $publicKey, int $opensslAlgorithm): bool
    {
        $pem = DerEncoder::rsaPublicKeyToPem($publicKey);

        return openssl_verify($data, $signature, $pem, $opensslAlgorithm) === 1;
    }

    /**
     * @param non-negative-int $coordinateSize
     */
    private static function verifyEcdsa(
        string $signature,
        string $data,
        string $publicKey,
        int $coordinateSize,
        int $opensslAlgorithm,
    ): bool {
        $pem = DerEncoder::ecPublicKeyToPem($publicKey, $coordinateSize);
        $key = openssl_pkey_get_public($pem);
        $derSignature = DerEncoder::ecdsaSignatureToDer($signature, $coordinateSize);

        return $key !== false && openssl_verify($data, $derSignature, $key, $opensslAlgorithm) === 1;
    }
}
