<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Internal;

use Psl\DNSSEC\Exception\SignatureFailedException;

use function base64_encode;
use function chr;
use function chunk_split;
use function ord;
use function strlen;
use function substr;

/**
 * Converts raw DNSKEY bytes to PEM-encoded public keys and raw ECDSA
 * signatures to DER format for use with openssl_verify().
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3110
 *
 * @internal
 */
final class DerEncoder
{
    /**
     * Convert a raw RFC 3110 RSA public key to PEM format.
     *
     * RFC 3110 format:
     * - 1 byte: exponent length (if < 256)
     * - OR 1 byte 0x00 + 2 bytes: exponent length (if >= 256)
     * - N bytes: exponent
     * - Remaining bytes: modulus
     *
     * @throws SignatureFailedException If the key is too short, or its exponent or modulus is empty.
     */
    public static function rsaPublicKeyToPem(string $rawKey): string
    {
        $keyLength = strlen($rawKey);
        if ($keyLength < 3) {
            throw SignatureFailedException::forRSAKeyTooShort();
        }

        $offset = 0;
        $expLenByte = ord($rawKey[$offset]);
        $offset++;

        if ($expLenByte === 0) {
            /** @var non-negative-int $expLen */
            $expLen = (ord($rawKey[$offset]) << 8) | ord($rawKey[$offset + 1]);
            $offset += 2;
        } else {
            $expLen = $expLenByte;
        }

        if (($offset + $expLen) > $keyLength) {
            throw SignatureFailedException::forRSAExponentOverflow();
        }

        if (0 === $expLen || ($offset + $expLen) === $keyLength) {
            throw SignatureFailedException::forEmptyRSAKeyComponent();
        }

        $exponent = substr($rawKey, $offset, $expLen);
        $offset += $expLen;
        $modulus = substr($rawKey, $offset);

        $modulusDer = self::derInteger($modulus);
        $exponentDer = self::derInteger($exponent);

        $rsaKeySequence = self::derSequence($modulusDer . $exponentDer);

        $bitString = "\x00" . $rsaKeySequence;
        $bitStringDer = self::derTag(0x03, $bitString);

        // RSA OID: 1.2.840.113549.1.1.1
        $rsaOid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
        $algorithmIdentifier = self::derSequence($rsaOid . "\x05\x00");

        $subjectPublicKeyInfo = self::derSequence($algorithmIdentifier . $bitStringDer);

        return self::pemWrap($subjectPublicKeyInfo);
    }

    /**
     * Convert a raw Ed448 public key to PEM format.
     *
     * The raw key is the 57-byte public key. We wrap it in SubjectPublicKeyInfo
     * with the Ed448 OID (1.3.101.113).
     */
    public static function ed448PublicKeyToPem(string $rawKey): string
    {
        // Ed448 OID: 1.3.101.113
        $ed448Oid = "\x06\x03\x2b\x65\x71";
        $algorithmIdentifier = self::derSequence($ed448Oid);

        $bitString = "\x00" . $rawKey;
        $bitStringDer = self::derTag(0x03, $bitString);

        $subjectPublicKeyInfo = self::derSequence($algorithmIdentifier . $bitStringDer);

        return self::pemWrap($subjectPublicKeyInfo);
    }

    /**
     * Convert a raw ECDSA public key to PEM format.
     *
     * The raw key is the concatenation of x and y coordinates.
     * We prepend 0x04 (uncompressed point) and wrap in SubjectPublicKeyInfo.
     *
     * @param int $coordinateSize The size of each coordinate in bytes (32 for P-256, 48 for P-384).
     *
     * @throws SignatureFailedException If the coordinate size is unsupported.
     */
    public static function ecPublicKeyToPem(string $rawKey, int $coordinateSize): string
    {
        $curveOid = match ($coordinateSize) {
            // P-256: 1.2.840.10045.3.1.7
            32 => "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07",
            // P-384: 1.3.132.0.34
            48 => "\x06\x05\x2b\x81\x04\x00\x22",
            default => throw SignatureFailedException::forUnsupportedCoordinateSize($coordinateSize),
        };

        // EC public key OID: 1.2.840.10045.2.1
        $ecOid = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
        $algorithmIdentifier = self::derSequence($ecOid . $curveOid);

        // Uncompressed point: 0x04 || x || y
        $point = "\x04" . $rawKey;
        $bitString = "\x00" . $point;
        $bitStringDer = self::derTag(0x03, $bitString);

        $subjectPublicKeyInfo = self::derSequence($algorithmIdentifier . $bitStringDer);

        return self::pemWrap($subjectPublicKeyInfo);
    }

    /**
     * Convert a raw ECDSA signature (r || s) to DER SEQUENCE { INTEGER r, INTEGER s }.
     *
     * @param non-negative-int $coordinateSize The size of each coordinate in bytes (32 for P-256, 48 for P-384).
     *
     * @throws SignatureFailedException If the signature is too short.
     */
    public static function ecdsaSignatureToDer(string $rawSig, int $coordinateSize): string
    {
        if (strlen($rawSig) < (2 * $coordinateSize)) {
            throw SignatureFailedException::forECDSASignatureTooShort(2 * $coordinateSize);
        }

        $r = substr($rawSig, 0, $coordinateSize);
        $s = substr($rawSig, $coordinateSize, $coordinateSize);

        return self::derSequence(self::derInteger($r) . self::derInteger($s));
    }

    /**
     * Encode an integer in DER format, adding a leading zero byte if the
     * high bit is set to ensure the integer is treated as positive.
     */
    private static function derInteger(string $bytes): string
    {
        // Strip leading zero bytes
        $i = 0;
        $length = strlen($bytes);
        while ($i < ($length - 1) && ord($bytes[$i]) === 0) {
            $i++;
        }

        $bytes = substr($bytes, $i);

        // Add leading zero if high bit is set (to keep it positive)
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::derTag(0x02, $bytes);
    }

    /**
     * Wrap content in a DER SEQUENCE.
     */
    private static function derSequence(string $content): string
    {
        return self::derTag(0x30, $content);
    }

    /**
     * Encode a DER tag with length.
     */
    private static function derTag(int $tag, string $content): string
    {
        $length = strlen($content);

        if ($length < 128) {
            return chr($tag) . chr($length) . $content;
        }

        if ($length < 256) {
            return chr($tag) . "\x81" . chr($length) . $content;
        }

        return chr($tag) . "\x82" . chr(($length >> 8) & 0xFF) . chr($length & 0xFF) . $content;
    }

    /**
     * Wrap DER-encoded SubjectPublicKeyInfo in PEM format.
     */
    private static function pemWrap(string $der): string
    {
        $base64 = base64_encode($der);
        $lines = chunk_split($base64, 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n" . $lines . "-----END PUBLIC KEY-----\n";
    }
}
