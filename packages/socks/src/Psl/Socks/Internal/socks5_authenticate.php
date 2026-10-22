<?php

declare(strict_types=1);

namespace Psl\Socks\Internal;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\Socks\Exception;
use SensitiveParameter;

use function chr;
use function strlen;

/**
 * Perform SOCKS5 username/password authentication (RFC 1929).
 *
 * @param non-empty-string $username
 * @param non-empty-string $password
 *
 * @throws Exception\AuthenticationException If authentication fails.
 * @throws Exception\SocksException If the server's reply is not a valid RFC 1929 reply.
 * @throws CancelledException If the cancellation token is cancelled.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
function socks5_authenticate(
    IO\ReadHandleInterface&IO\WriteHandleInterface $stream,
    string $username,
    #[SensitiveParameter]
    string $password,
    CancellationTokenInterface $cancellation = new NullCancellationToken(),
): void {
    $usernameLen = strlen($username);
    $passwordLen = strlen($password);

    if ($usernameLen > 255) {
        throw new Exception\AuthenticationException('SOCKS5 username exceeds maximum length of 255 bytes.');
    }

    if ($passwordLen > 255) {
        throw new Exception\AuthenticationException('SOCKS5 password exceeds maximum length of 255 bytes.');
    }

    $auth = "\x01" . chr($usernameLen) . $username . chr($passwordLen) . $password;
    $stream->writeAll($auth, $cancellation);

    // RFC 1929, section 2: the reply carries the sub-negotiation's version, 1, then its status, 0 for success.
    $response = $stream->readFixedSize(2, $cancellation);
    if ($response[0] !== "\x01") {
        throw new Exception\SocksException('Invalid SOCKS5 authentication version in server response.');
    }

    if ($response[1] !== "\x00") {
        throw new Exception\AuthenticationException('SOCKS5 authentication failed: invalid credentials.');
    }
}
