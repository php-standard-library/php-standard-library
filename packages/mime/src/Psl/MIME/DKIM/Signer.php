<?php

declare(strict_types=1);

namespace Psl\MIME\DKIM;

use Psl\MIME\Exception\DKIMException;

use function array_pop;
use function base64_decode;
use function base64_encode;
use function chunk_split;
use function count;
use function explode;
use function hash;
use function implode;
use function in_array;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_sign;
use function preg_replace;
use function rtrim;
use function sodium_crypto_sign_detached;
use function str_contains;
use function str_replace;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function time;
use function trim;

use const OPENSSL_ALGO_SHA256;
use const SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;

/**
 * DKIM message signer that prepends a DKIM-Signature header to raw MIME messages.
 *
 * Operates directly on raw message strings without requiring a message abstraction layer.
 * Supports both RSA-SHA256 (RFC 6376, with minimum 1024-bit keys per RFC 8301) and
 * Ed25519-SHA256 (RFC 8463) signing algorithms.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc6376 RFC 6376 - DomainKeys Identified Mail (DKIM) Signatures
 * @link https://datatracker.ietf.org/doc/html/rfc8301 RFC 8301 - Cryptographic Algorithm and Key Usage Update
 * @link https://datatracker.ietf.org/doc/html/rfc8463 RFC 8463 - Ed25519 for DKIM
 *
 * @see SignerInterface For the contract this class implements.
 * @see SigningConfiguration For the signing parameters consumed by this signer.
 *
 * @api
 */
final readonly class Signer implements SignerInterface
{
    /**
     * Create a new DKIM signer.
     *
     * @param string $privateKey The private key: PEM-encoded for RSA, or a base64-encoded 64-byte
     *                           secret key for Ed25519.
     * @param SigningConfiguration $configuration The signing parameters (domain, selector, algorithm, etc.).
     * @param null|string $passphrase Passphrase to decrypt the RSA private key, or null if unencrypted.
     *                                Ignored for Ed25519 keys.
     */
    public function __construct(
        private string $privateKey,
        private SigningConfiguration $configuration,
        private null|string $passphrase = null,
    ) {}

    /**
     * @inheritDoc
     */
    public function sign(string $message): string
    {
        [$rawHeaders, $body] = self::splitMessage($message);
        $headers = self::parseHeaders($rawHeaders);

        $headersToIgnore = [
            'return-path',
            'x-transport',
            ...$this->configuration->headersToIgnore,
        ];

        $signedHeaderNames = [];
        $canonicalizedInstances = [];

        foreach ($headers as [$name, $raw]) {
            $lowerName = strtolower($name);
            if ($lowerName === 'dkim-signature') {
                continue;
            }

            if (in_array($lowerName, $headersToIgnore, true) && $lowerName !== 'from') {
                continue;
            }

            $signedHeaderNames[] = $lowerName;
            $canonicalizedInstances[$lowerName][] = self::canonicalizeHeader(
                $name,
                $raw,
                $this->configuration->headerCanonicalization,
            );
        }

        // RFC 6376 §5.4.2: verifiers select the instances of a repeated header field from the
        // bottom of the header block up, so the n-th occurrence of a name in "h=" stands for the
        // n-th instance counted from the end, and must be hashed in that order.
        $headerCanonData = '';
        foreach ($signedHeaderNames as $lowerName) {
            $headerCanonData .= (string) array_pop($canonicalizedInstances[$lowerName]);
        }

        [$bodyHash, $bodyLength] = self::hashBody(
            $body,
            $this->configuration->bodyCanonicalization,
            $this->configuration->bodyMaxLength,
        );

        $timestamp = time();
        $params = [
            'v=1',
            'q=dns/txt',
            'a=' . $this->configuration->algorithm->value,
            'bh=' . base64_encode($bodyHash),
            'd=' . $this->configuration->domain,
            'h=' . implode(': ', $signedHeaderNames),
            'i=@' . $this->configuration->domain,
            's=' . $this->configuration->selector,
            't=' . $timestamp,
            'c='
                . $this->configuration->headerCanonicalization->value
                . '/'
                . $this->configuration->bodyCanonicalization->value,
        ];

        if ($this->configuration->includeBodyLength) {
            $params[] = 'l=' . $bodyLength;
        }

        if ($this->configuration->signatureExpirationDelay > 0) {
            $params[] = 'x=' . ($timestamp + $this->configuration->signatureExpirationDelay);
        }

        $params[] = 'b=';

        $dkimHeader = 'DKIM-Signature: ' . implode('; ', $params);

        // RFC 6376 §3.7: the DKIM-Signature header field is hashed last, exactly as it will be
        // inserted (name included, "b=" empty), canonicalized like any other header field, and
        // without its trailing CRLF.
        $headerCanonData .= substr(
            self::canonicalizeHeader('DKIM-Signature', $dkimHeader, $this->configuration->headerCanonicalization),
            0,
            -2,
        );

        $signature = $this->computeSignature($headerCanonData);
        $encodedSignature = rtrim(chunk_split(base64_encode($signature), 73, "\r\n "));

        $dkimHeader .= $encodedSignature;

        return $dkimHeader . "\r\n" . $rawHeaders . "\r\n\r\n" . $body;
    }

    /**
     * Split a raw MIME message into its header section and body.
     *
     * Searches for a blank line (CRLF or LF) separating headers from body.
     * Returns the body as empty if no separator is found.
     *
     * @return array{string, string} [headers, body]
     */
    private static function splitMessage(string $message): array
    {
        $separator = str_contains($message, "\r\n\r\n") ? "\r\n\r\n" : "\n\n";
        $pos = strpos($message, $separator);
        if ($pos === false) {
            return [$message, ''];
        }

        return [substr($message, 0, $pos), substr($message, $pos + strlen($separator))];
    }

    /**
     * Parse a raw header block into name/raw-line pairs.
     *
     * Handles continuation lines (lines starting with whitespace) by appending
     * them to the preceding header's raw line with CRLF.
     *
     * @return list<array{string, string}> List of [name, raw-line] pairs.
     */
    private static function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $lines = explode("\n", str_replace("\r\n", "\n", $rawHeaders));
        $current = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $headers[$current][1] .= "\r\n" . $line;
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }

            $current = count($headers);
            $name = rtrim(substr($line, 0, $colonPos), " \t");
            $value = $line;
            $headers[] = [$name, $value];
        }

        /** @var list<array{string, string}> */
        return $headers;
    }

    /**
     * Canonicalize a single header line according to the specified DKIM canonicalization mode.
     *
     * Simple mode returns the raw line with CRLF. Relaxed mode lowercases the header name,
     * unfolds continuation lines, collapses whitespace, and removes whitespace around the colon.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc6376#section-3.4.2
     */
    private static function canonicalizeHeader(string $name, string $rawLine, Canonicalization $mode): string
    {
        if ($mode === Canonicalization::Simple) {
            return $rawLine . "\r\n";
        }

        // Relaxed: lowercase name, unfold, collapse whitespace, strip WSP around the colon.
        $name = rtrim($name, " \t");
        $colonPos = strpos($rawLine, ':');
        $value = $colonPos !== false ? substr($rawLine, $colonPos + 1) : '';
        $value = str_replace("\r\n", '', $value);
        /** @var string $value */
        $value = preg_replace('/[ \t]+/', ' ', $value);

        return strtolower($name) . ':' . trim($value, " \t") . "\r\n";
    }

    /**
     * Canonicalize the body and compute its SHA-256 hash for the "bh=" tag.
     *
     * Optionally truncates the canonicalized body to the specified maximum length
     * before hashing, for use with the "l=" body length tag.
     *
     * @param int<0, max> $maxLength 0 = no limit.
     *
     * @return array{string, int<0, max>} [binaryHash, bodyLength]
     */
    private static function hashBody(string $body, Canonicalization $mode, int $maxLength): array
    {
        $canon = self::canonicalizeBody($body, $mode);

        if ($maxLength > 0 && strlen($canon) > $maxLength) {
            $canon = substr($canon, 0, $maxLength);
        }

        $length = strlen($canon);

        return [hash('sha256', $canon, true), $length];
    }

    /**
     * Canonicalize the message body according to the specified DKIM canonicalization mode.
     *
     * Simple mode normalizes line endings to CRLF and strips trailing empty lines.
     * Relaxed mode additionally collapses whitespace and strips trailing whitespace per line.
     */
    private static function canonicalizeBody(string $body, Canonicalization $mode): string
    {
        if ($body === '') {
            return "\r\n";
        }

        // Normalize line endings
        $body = str_replace("\r\n", "\n", $body);
        $body = str_replace("\r", "\n", $body);
        $lines = explode("\n", $body);

        $result = '';
        if ($mode === Canonicalization::Simple) {
            $result = implode("\r\n", $lines);
        } else {
            // Relaxed: strip trailing whitespace per line, collapse spaces
            $canonLines = [];
            foreach ($lines as $line) {
                /** @var string $line */
                $line = preg_replace('/[ \t]+/', ' ', $line);
                $canonLines[] = rtrim($line, " \t");
            }

            $result = implode("\r\n", $canonLines);
        }

        // Strip trailing empty lines, then add single CRLF
        return rtrim($result, "\r\n") . "\r\n";
    }

    /**
     * Compute the digital signature over the canonicalized header data.
     *
     * Dispatches to the appropriate signing method based on the configured algorithm.
     *
     * @throws DKIMException If the signing operation fails.
     */
    private function computeSignature(string $data): string
    {
        if ($this->configuration->algorithm === Algorithm::Ed25519Sha256) {
            return $this->signEd25519($data);
        }

        return $this->signRsa($data);
    }

    /**
     * Sign the data using RSA-SHA256.
     *
     * Loads the RSA private key, validates it is at least 1024 bits per RFC 8301,
     * and produces an RSA signature using the SHA-256 digest.
     *
     * @throws DKIMException If the key is invalid or the signing operation fails.
     */
    private function signRsa(string $data): string
    {
        $key = openssl_pkey_get_private($this->privateKey, $this->passphrase ?? '');
        if ($key === false) {
            throw DKIMException::forInvalidKey('unable to load RSA private key');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['bits']) || $details['bits'] < 1024) {
            throw DKIMException::forInvalidKey('RSA key must be at least 1024 bits per RFC 8301');
        }

        $signature = '';
        $result = openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
        if ($result === false || $signature === '') {
            throw DKIMException::forSigningFailure('RSA-SHA256 signature operation failed');
        }

        return $signature;
    }

    /**
     * Sign the data using Ed25519-SHA256.
     *
     * Hashes the data with SHA-256, then produces an Ed25519 detached signature
     * using the base64-decoded secret key.
     *
     * @throws DKIMException If the key is invalid or has an incorrect length.
     */
    private function signEd25519(string $data): string
    {
        $hash = hash('sha256', $data, true);

        $key = base64_decode($this->privateKey, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw DKIMException::forInvalidKey(
                'Ed25519 key must be ' . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES . ' bytes (base64-encoded)',
            );
        }

        return sodium_crypto_sign_detached($hash, $key);
    }
}
