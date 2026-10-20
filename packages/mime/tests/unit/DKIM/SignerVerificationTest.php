<?php

declare(strict_types=1);

namespace Psl\MIME\Tests\Unit\DKIM;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\MIME\DKIM\Algorithm;
use Psl\MIME\DKIM\Canonicalization;
use Psl\MIME\DKIM\Signer;
use Psl\MIME\DKIM\SigningConfiguration;

use function array_pad;
use function base64_decode;
use function base64_encode;
use function chunk_split;
use function count;
use function explode;
use function hash;
use function openssl_verify;
use function preg_replace;
use function rtrim;
use function sodium_crypto_sign_secretkey;
use function sodium_crypto_sign_seed_keypair;
use function sodium_crypto_sign_verify_detached;
use function str_ends_with;
use function str_replace;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use const OPENSSL_ALGO_SHA256;

/**
 * Verifies signatures produced by {@see Signer} with an independent RFC 6376 verifier.
 *
 * The verifier below is written from the specification, not from the signer, and is itself
 * checked against the example message of RFC 8463 Appendix A. The signing keys are the RFC 8463
 * Appendix A.1 keys; the public keys are the Appendix A.2 DNS record "p=" values.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc6376#section-6.1
 * @link https://datatracker.ietf.org/doc/html/rfc8463#appendix-A
 */
final class SignerVerificationTest extends TestCase
{
    private const string RSA_PRIVATE_KEY = <<<'PEM'
    -----BEGIN RSA PRIVATE KEY-----
    MIICXQIBAAKBgQDkHlOQoBTzWRiGs5V6NpP3idY6Wk08a5qhdR6wy5bdOKb2jLQi
    Y/J16JYi0Qvx/byYzCNb3W91y3FutACDfzwQ/BC/e/8uBsCR+yz1Lxj+PL6lHvqM
    KrM3rG4hstT5QjvHO9PzoxZyVYLzBfO2EeC3Ip3G+2kryOTIKT+l/K4w3QIDAQAB
    AoGAH0cxOhFZDgzXWhDhnAJDw5s4roOXN4OhjiXa8W7Y3rhX3FJqmJSPuC8N9vQm
    6SVbaLAE4SG5mLMueHlh4KXffEpuLEiNp9Ss3O4YfLiQpbRqE7Tm5SxKjvvQoZZe
    zHorimOaChRL2it47iuWxzxSiRMv4c+j70GiWdxXnxe4UoECQQDzJB/0U58W7RZy
    6enGVj2kWF732CoWFZWzi1FicudrBFoy63QwcowpoCazKtvZGMNlPWnC7x/6o8Gc
    uSe0ga2xAkEA8C7PipPm1/1fTRQvj1o/dDmZp243044ZNyxjg+/OPN0oWCbXIGxy
    WvmZbXriOWoSALJTjExEgraHEgnXssuk7QJBALl5ICsYMu6hMxO73gnfNayNgPxd
    WFV6Z7ULnKyV7HSVYF0hgYOHjeYe9gaMtiJYoo0zGN+L3AAtNP9huqkWlzECQE1a
    licIeVlo1e+qJ6Mgqr0Q7Aa7falZ448ccbSFYEPD6oFxiOl9Y9se9iYHZKKfIcst
    o7DUw1/hz2Ck4N5JrgUCQQCyKveNvjzkkd8HjYs0SwM0fPjK16//5qDZ2UiDGnOe
    uEzxBDAr518Z8VFbR41in3W4Y3yCDgQlLlcETrS+zYcL
    -----END RSA PRIVATE KEY-----
    PEM;

    private const string RSA_PUBLIC_KEY = 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDkHlOQoBTzWRiGs5V6NpP3idY6Wk08a5qhdR6wy5bdOKb2jLQiY/J16JYi0Qvx/byYzCNb3W91y3FutACDfzwQ/BC/e/8uBsCR+yz1Lxj+PL6lHvqMKrM3rG4hstT5QjvHO9PzoxZyVYLzBfO2EeC3Ip3G+2kryOTIKT+l/K4w3QIDAQAB';

    private const string ED25519_SEED = 'nWGxne/9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2A=';

    private const string ED25519_PUBLIC_KEY = '11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo=';

    private const string RFC8463_MESSAGE =
        "DKIM-Signature: v=1; a=ed25519-sha256; c=relaxed/relaxed;\r\n"
            . " d=football.example.com; i=@football.example.com;\r\n"
            . " q=dns/txt; s=brisbane; t=1528637909; h=from : to :\r\n"
            . " subject : date : message-id : from : subject : date;\r\n"
            . " bh=2jUSOH9NhtVGCQWNr9BrIAPreKQjO6Sn7XIkfJVOzv8=;\r\n"
            . " b=/gCrinpcQOoIfuHNQIbq4pgh9kyIK3AQUdt9OdqQehSwhEIug4D11Bus\r\n"
            . " Fa3bT3FY5OsU7ZbnKELq+eXdp1Q1Dw==\r\n"
            . "DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed;\r\n"
            . " d=football.example.com; i=@football.example.com;\r\n"
            . " q=dns/txt; s=test; t=1528637909; h=from : to : subject :\r\n"
            . " date : message-id : from : subject : date;\r\n"
            . " bh=2jUSOH9NhtVGCQWNr9BrIAPreKQjO6Sn7XIkfJVOzv8=;\r\n"
            . " b=F45dVWDfMbQDGHJFlXUNB2HKfbCeLRyhDXgFpEL8GwpsRe0IeIixNTe3\r\n"
            . " DhCVlUrSjV4BwcVcOF6+FF3Zo9Rpo1tFOeS9mPYQTnGdaSGsgeefOsk2Jz\r\n"
            . " dA+L10TeYt9BgDfQNZtKdN1WO//KgIqXP7OdEFE4LjFYNcUxZQ4FADY+8=\r\n"
            . self::RFC8463_UNSIGNED_MESSAGE;

    private const string RFC8463_UNSIGNED_MESSAGE =
        "From: Joe SixPack <joe@football.example.com>\r\n"
            . "To: Suzie Q <suzie@shopping.example.net>\r\n"
            . "Subject: Is dinner ready?\r\n"
            . "Date: Fri, 11 Jul 2003 21:00:37 -0700 (PDT)\r\n"
            . "Message-ID: <20030712040037.46341.5F8J@football.example.com>\r\n"
            . "\r\n"
            . "Hi.\r\n"
            . "\r\n"
            . "We lost the game.  Are you hungry yet?\r\n"
            . "\r\n"
            . "Joe.\r\n"
            . "\r\n";

    /**
     * Sanity check for the verifier itself: it must accept the RFC 8463 example signatures.
     */
    #[DataProvider('provideRfc8463Signatures')]
    public function testVerifierAcceptsRfc8463Example(int $index, string $publicKey): void
    {
        static::assertTrue(self::verify(self::RFC8463_MESSAGE, $index, $publicKey));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideRfc8463Signatures(): iterable
    {
        yield 'ed25519-sha256' => [0, self::ED25519_PUBLIC_KEY];
        yield 'rsa-sha256' => [1, self::RSA_PUBLIC_KEY];
    }

    public function testVerifierRejectsTamperedRfc8463Example(): void
    {
        $tampered = str_replace('Is dinner ready?', 'Is dinner ready!', self::RFC8463_MESSAGE);

        static::assertFalse(self::verify($tampered, 0, self::ED25519_PUBLIC_KEY));
        static::assertFalse(self::verify($tampered, 1, self::RSA_PUBLIC_KEY));
    }

    #[DataProvider('provideSignatureCases')]
    public function testSignatureVerifies(
        Algorithm $algorithm,
        Canonicalization $headerCanonicalization,
        Canonicalization $bodyCanonicalization,
        string $message,
    ): void {
        $configuration = new SigningConfiguration(
            'football.example.com',
            'test',
            $algorithm,
            $headerCanonicalization,
            $bodyCanonicalization,
        );

        $signed = self::createSigner($configuration)->sign($message);

        static::assertTrue(self::verify(self::toWireFormat($signed), 0, self::publicKey($algorithm)));
    }

    #[DataProvider('provideAlgorithms')]
    public function testSignatureWithOptionalTagsVerifies(Algorithm $algorithm): void
    {
        $configuration = new SigningConfiguration('football.example.com', 'test', $algorithm)
            ->withIncludeBodyLength()
            ->withBodyMaxLength(10)
            ->withSignatureExpirationDelay(3600)
            ->withHeadersToIgnore(['message-id']);

        $signed = self::createSigner($configuration)->sign(self::RFC8463_UNSIGNED_MESSAGE);

        static::assertTrue(self::verify($signed, 0, self::publicKey($algorithm)));
    }

    #[DataProvider('provideAlgorithms')]
    public function testSignatureWithBodyMaxLengthAloneVerifies(Algorithm $algorithm): void
    {
        $configuration = new SigningConfiguration('football.example.com', 'test', $algorithm)->withBodyMaxLength(10);

        $signed = self::createSigner($configuration)->sign(self::RFC8463_UNSIGNED_MESSAGE);

        static::assertStringContainsString('; l=10;', str_replace("\r\n", '', $signed));
        static::assertTrue(self::verify($signed, 0, self::publicKey($algorithm)));
    }

    #[DataProvider('provideAlgorithms')]
    public function testSignatureWithBodyMaxLengthAboveTheBodyLengthHasNoLengthTag(Algorithm $algorithm): void
    {
        $configuration = new SigningConfiguration('football.example.com', 'test', $algorithm)->withBodyMaxLength(
            10_000,
        );

        $signed = self::createSigner($configuration)->sign(self::RFC8463_UNSIGNED_MESSAGE);

        static::assertStringNotContainsString('l=', $signed);
        static::assertTrue(self::verify($signed, 0, self::publicKey($algorithm)));
    }

    /**
     * @return iterable<string, array{Algorithm}>
     */
    public static function provideAlgorithms(): iterable
    {
        foreach (Algorithm::cases() as $algorithm) {
            yield $algorithm->value => [$algorithm];
        }
    }

    /**
     * @return iterable<string, array{Algorithm, Canonicalization, Canonicalization, string}>
     */
    public static function provideSignatureCases(): iterable
    {
        $messages = [
            'rfc8463 message' => self::RFC8463_UNSIGNED_MESSAGE,
            'folded headers and whitespace' =>
                "From: Joe SixPack <joe@football.example.com>\r\n"
                    . "Subject:   A   folded\r\n"
                    . "\t  subject  line  \r\n"
                    . "X-Spaced-Name \t: value\r\n"
                    . "\r\n"
                    . "Body  with \t inner   whitespace  \r\n"
                    . "\r\n"
                    . "\r\n",
            'lf line endings' => "From: joe@football.example.com\nTo: suzie@shopping.example.net\n\nHello\n",
            'repeated header fields' =>
                "Received: from relay2.football.example.com by mx.example.net\r\n"
                    . "From: joe@football.example.com\r\n"
                    . "Received: from relay1.football.example.com by relay2.football.example.com\r\n"
                    . "X-Tag: first\r\n"
                    . "To: suzie@shopping.example.net\r\n"
                    . "X-Tag: second\r\n"
                    . "X-Tag: third\r\n"
                    . "\r\n"
                    . "Hello\r\n",
            'empty body' => "From: joe@football.example.com\r\nTo: suzie@shopping.example.net\r\n\r\n",
            'blank lines only body' => "From: joe@football.example.com\r\n\r\n \t \r\n\r\n\r\n",
        ];

        foreach (Algorithm::cases() as $algorithm) {
            foreach (Canonicalization::cases() as $header) {
                foreach (Canonicalization::cases() as $body) {
                    foreach ($messages as $label => $message) {
                        $name = $algorithm->value . ' ' . $header->value . '/' . $body->value . ' ' . $label;

                        yield $name => [$algorithm, $header, $body, $message];
                    }
                }
            }
        }
    }

    private static function createSigner(SigningConfiguration $configuration): Signer
    {
        if ($configuration->algorithm === Algorithm::RsaSha256) {
            return new Signer(self::RSA_PRIVATE_KEY, $configuration);
        }

        $keypair = sodium_crypto_sign_seed_keypair((string) base64_decode(self::ED25519_SEED, true));

        return new Signer(base64_encode(sodium_crypto_sign_secretkey($keypair)), $configuration);
    }

    private static function publicKey(Algorithm $algorithm): string
    {
        return $algorithm === Algorithm::RsaSha256 ? self::RSA_PUBLIC_KEY : self::ED25519_PUBLIC_KEY;
    }

    /**
     * Normalize line endings to CRLF, as SMTP transport does.
     */
    private static function toWireFormat(string $message): string
    {
        return (string) preg_replace('/\r?\n/', "\r\n", $message);
    }

    /**
     * Verify the DKIM-Signature header field at the given position per RFC 6376 §6.1.3.
     */
    private static function verify(string $message, int $index, string $publicKey): bool
    {
        $separator = strpos($message, "\r\n\r\n");
        $header = $separator === false ? $message : substr($message, 0, $separator);
        $body = $separator === false ? '' : substr($message, $separator + 4);

        $fields = self::parseFields($header);
        $signatures = [];
        foreach ($fields as [$name, $raw]) {
            if ($name !== 'dkim-signature') {
                continue;
            }

            $signatures[] = $raw;
        }

        $signature = $signatures[$index];
        $tags = self::parseTags(substr($signature, (int) strpos($signature, ':') + 1));
        [$headerMode, $bodyMode] = array_pad(explode('/', $tags['c'] ?? 'simple'), 2, 'simple');

        // RFC 6376 §3.7 body hash, truncated to "l=" octets when present.
        $canonicalBody = self::canonicalizeBody($body, $bodyMode);
        if (isset($tags['l'])) {
            $canonicalBody = substr($canonicalBody, 0, (int) $tags['l']);
        }

        if (base64_encode(hash('sha256', $canonicalBody, true)) !== $tags['bh']) {
            return false;
        }

        // RFC 6376 §5.4.2: header fields are selected bottom-up; names without a remaining
        // instance contribute nothing.
        $data = '';
        $used = [];
        foreach (explode(':', $tags['h']) as $name) {
            $name = strtolower(trim($name, " \t\r\n"));
            for ($i = count($fields) - 1; $i >= 0; $i--) {
                if (isset($used[$i]) || $fields[$i][0] !== $name) {
                    continue;
                }

                $used[$i] = true;
                $data .= self::canonicalizeHeader($fields[$i][1], $headerMode);
                break;
            }
        }

        // RFC 6376 §3.7: the signature header itself, with an empty "b=" value and without the
        // trailing CRLF.
        $unsigned = (string) preg_replace('/([;:][ \t\r\n]*b[ \t\r\n]*=)[^;]*/', '$1', $signature, 1);
        $data .= substr(self::canonicalizeHeader($unsigned, $headerMode), 0, -2);

        $signatureBytes = (string) base64_decode($tags['b'], true);
        if ($tags['a'] === 'ed25519-sha256') {
            return sodium_crypto_sign_verify_detached(
                $signatureBytes,
                hash('sha256', $data, true),
                (string) base64_decode($publicKey, true),
            );
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($publicKey, 64, "\n") . "-----END PUBLIC KEY-----\n";

        return openssl_verify($data, $signatureBytes, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * @return list<array{string, string}> Lowercase field name and raw (folded) field.
     */
    private static function parseFields(string $header): array
    {
        $fields = [];
        foreach (explode("\r\n", $header) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $fields !== []) {
                $fields[count($fields) - 1][1] .= "\r\n" . $line;
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }

            $fields[] = [strtolower(rtrim(substr($line, 0, $colon), " \t")), $line];
        }

        return $fields;
    }

    /**
     * @return array<string, string> Tag values with all whitespace removed from "b=" and "bh=".
     */
    private static function parseTags(string $value): array
    {
        $tags = [];
        foreach (explode(';', $value) as $spec) {
            $spec = trim($spec, " \t\r\n");
            if ($spec === '') {
                continue;
            }

            [$name, $tagValue] = array_pad(explode('=', $spec, 2), 2, '');
            $name = trim($name, " \t\r\n");
            $tagValue = trim($tagValue, " \t\r\n");
            if ($name === 'b' || $name === 'bh') {
                $tagValue = (string) preg_replace('/[ \t\r\n]+/', '', $tagValue);
            }

            $tags[$name] = $tagValue;
        }

        return $tags;
    }

    /**
     * RFC 6376 §3.4.1 (simple) and §3.4.2 (relaxed) header canonicalization.
     */
    private static function canonicalizeHeader(string $raw, string $mode): string
    {
        if ($mode === 'simple') {
            return $raw . "\r\n";
        }

        $colon = (int) strpos($raw, ':');
        $name = strtolower(rtrim(substr($raw, 0, $colon), " \t"));
        $value = str_replace("\r\n", '', substr($raw, $colon + 1));
        $value = trim((string) preg_replace('/[ \t]+/', ' ', $value), ' ');

        return $name . ':' . $value . "\r\n";
    }

    /**
     * RFC 6376 §3.4.3 (simple) and §3.4.4 (relaxed) body canonicalization.
     */
    private static function canonicalizeBody(string $body, string $mode): string
    {
        if ($mode === 'relaxed') {
            $body = (string) preg_replace('/[ \t]+(?=\r\n|$)/', '', $body);
            $body = (string) preg_replace('/[ \t]+/', ' ', $body);
        }

        while (str_ends_with($body, "\r\n\r\n")) {
            $body = substr($body, 0, -2);
        }

        if ($mode === 'relaxed' && ($body === '' || $body === "\r\n")) {
            return '';
        }

        if (!str_ends_with($body, "\r\n")) {
            $body .= "\r\n";
        }

        return $body;
    }
}
