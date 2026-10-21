<?php

declare(strict_types=1);

namespace Psl\SMTP\Client\Authentication;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\SMTP\Client\ConnectionInterface;
use Psl\SMTP\Exception\AuthenticationException;
use Random\RandomException;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function hash;
use function hash_equals;
use function hash_hmac;
use function hash_pbkdf2;
use function preg_match;
use function random_bytes;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtoupper;

/**
 * AUTH SCRAM-SHA-256 mechanism per RFC 7677 / RFC 5802.
 *
 * Salted Challenge Response Authentication Mechanism using SHA-256.
 * Provides mutual authentication: the client verifies the server knows
 * the password hash, and the server verifies the client knows the password.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc7677
 * @link https://datatracker.ietf.org/doc/html/rfc5802
 *
 * @api
 */
final readonly class SCRAMSHA256Authenticator implements AuthenticatorInterface
{
    public string $mechanism;

    public function __construct(
        private string $username,
        #[SensitiveParameter]
        private string $password,
    ) {
        $this->mechanism = 'SCRAM-SHA-256';
    }

    /**
     * {@inheritDoc}
     *
     * @throws AuthenticationException If the system cannot generate a secure random nonce, or the server does not offer SCRAM-SHA-256, sends a malformed or unverifiable SCRAM message, or rejects the proof.
     */
    public function authenticate(
        ConnectionInterface $connection,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $authValue = $connection->getCapabilityValue('AUTH');
        if ($authValue === null || !str_contains(' ' . strtoupper($authValue) . ' ', ' ' . $this->mechanism . ' ')) {
            throw AuthenticationException::forUnsupportedMechanism($this->mechanism);
        }

        try {
            $nonce = base64_encode(random_bytes(18));
        } catch (RandomException $e) {
            throw AuthenticationException::forNonceGenerationFailed($this->mechanism, $e);
        }

        $clientFirstBare = 'n=' . self::escapeUsername($this->username) . ',r=' . $nonce;
        $clientFirstMessage = 'n,,' . $clientFirstBare;

        $response = $connection->sendCommand('AUTH SCRAM-SHA-256 ' . base64_encode($clientFirstMessage), $cancellation);

        if ($response->code !== 334) {
            throw AuthenticationException::forRejected($this->mechanism, $response->code, $response->message);
        }

        $serverFirstMessage = base64_decode($response->message, true);
        if ($serverFirstMessage === false) {
            throw AuthenticationException::forRejected($this->mechanism, $response->code, $response->message);
        }

        $serverParams = self::parseServerFirst($serverFirstMessage, $nonce);

        $saltedPassword = hash_pbkdf2(
            'sha256',
            $this->password,
            $serverParams['salt'],
            $serverParams['iterations'],
            0,
            true,
        );

        $clientKey = hash_hmac('sha256', 'Client Key', $saltedPassword, true);
        $storedKey = hash('sha256', $clientKey, true);

        $channelBinding = base64_encode('n,,');
        $clientFinalWithoutProof = 'c=' . $channelBinding . ',r=' . $serverParams['nonce'];
        $authMessage = $clientFirstBare . ',' . $serverFirstMessage . ',' . $clientFinalWithoutProof;

        $clientSignature = hash_hmac('sha256', $authMessage, $storedKey, true);
        $clientProof = $clientKey ^ $clientSignature;

        $clientFinalMessage = $clientFinalWithoutProof . ',p=' . base64_encode($clientProof);

        $response = $connection->sendCommand(base64_encode($clientFinalMessage), $cancellation);

        if (!$response->isPositiveCompletion()) {
            throw AuthenticationException::forRejected($this->mechanism, $response->code, $response->message);
        }

        $serverFinalMessage = base64_decode($response->message, true);
        if ($serverFinalMessage !== false && $serverFinalMessage !== '') {
            $serverKey = hash_hmac('sha256', 'Server Key', $saltedPassword, true);
            $serverSignature = hash_hmac('sha256', $authMessage, $serverKey, true);

            if (!self::verifyServerFinal($serverFinalMessage, $serverSignature)) {
                throw AuthenticationException::forRejected(
                    $this->mechanism,
                    $response->code,
                    'Server verification failed',
                );
            }
        }
    }

    /**
     * Escape username per RFC 5802 SS5.1: "=" becomes "=3D", "," becomes "=2C".
     */
    private static function escapeUsername(string $username): string
    {
        $username = str_replace('=', '=3D', $username);

        return str_replace(',', '=2C', $username);
    }

    /**
     * Parse the server-first-message to extract nonce, salt, and iteration count.
     *
     * @return array{nonce: string, salt: string, iterations: positive-int}
     *
     * @throws AuthenticationException If the server response is malformed.
     */
    private static function parseServerFirst(string $message, string $clientNonce): array
    {
        $matches = null;
        if (!preg_match('/r=([^,]+),s=([^,]+),i=(\d+)/', $message, $matches)) {
            throw AuthenticationException::forRejected('SCRAM-SHA-256', 0, 'Malformed server-first-message');
        }

        $serverNonce = $matches[1];
        if (!str_starts_with($serverNonce, $clientNonce)) {
            throw AuthenticationException::forRejected(
                'SCRAM-SHA-256',
                0,
                'Server nonce does not start with client nonce',
            );
        }

        $salt = base64_decode($matches[2], true);
        if ($salt === false) {
            throw AuthenticationException::forRejected('SCRAM-SHA-256', 0, 'Invalid salt encoding');
        }

        $iterations = (int) $matches[3];
        if ($iterations < 1) {
            throw AuthenticationException::forRejected('SCRAM-SHA-256', 0, 'Invalid iteration count');
        }

        return [
            'nonce' => $serverNonce,
            'salt' => $salt,
            'iterations' => $iterations,
        ];
    }

    /**
     * Verify the server-final-message contains the expected server signature.
     */
    private static function verifyServerFinal(string $message, string $expectedSignature): bool
    {
        $matches = null;
        if (!preg_match('/v=([^,]+)/', $message, $matches)) {
            return false;
        }

        $serverSignature = base64_decode($matches[1], true);
        if ($serverSignature === false) {
            return false;
        }

        return hash_equals($expectedSignature, $serverSignature);
    }
}
