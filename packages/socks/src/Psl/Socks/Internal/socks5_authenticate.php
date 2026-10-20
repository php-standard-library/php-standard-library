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

    $response = $stream->readFixedSize(2, $cancellation);
    if ($response[1] !== "\x00") {
        throw new Exception\AuthenticationException('SOCKS5 authentication failed: invalid credentials.');
    }
}
