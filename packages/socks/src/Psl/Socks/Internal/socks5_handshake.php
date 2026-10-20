<?php

declare(strict_types=1);

namespace Psl\Socks\Internal;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\Socks\Configuration;
use Psl\Socks\Exception;

use function chr;
use function inet_pton;
use function ord;
use function pack;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Perform a SOCKS5 handshake on an existing stream.
 *
 * Implements RFC 1928 (SOCKS5) and RFC 1929 (username/password auth).
 *
 * @throws Exception\SocksException If the SOCKS5 handshake fails.
 * @throws Exception\AuthenticationException If authentication fails.
 * @throws CancelledException If the cancellation token is cancelled.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
function socks5_handshake(
    IO\ReadHandleInterface&IO\WriteHandleInterface $stream,
    string $host,
    int $port,
    Configuration $configuration,
    CancellationTokenInterface $cancellation = new NullCancellationToken(),
): void {
    $username = $configuration->username;
    $password = $configuration->password;

    if ($username !== null && $password !== null) {
        $stream->write("\x05\x02\x00\x02", $cancellation);
    } else {
        $stream->write("\x05\x01\x00", $cancellation);
    }

    $response = $stream->readFixedSize(2, $cancellation);
    if ($response[0] !== "\x05") {
        throw new Exception\SocksException('Invalid SOCKS version in server response.');
    }

    $method = ord($response[1]);
    if ($method === 0xFF) {
        throw new Exception\SocksException('SOCKS5 server rejected all offered authentication methods.');
    }

    if ($method === 0x02) {
        if ($username === null || $password === null) {
            throw new Exception\AuthenticationException(
                'SOCKS5 server requires authentication but no credentials were provided.',
            );
        }

        namespace\socks5_authenticate($stream, $username, $password, $cancellation);
    } elseif ($method !== 0x00) {
        throw new Exception\SocksException("SOCKS5 server selected unsupported authentication method: {$method}.");
    }

    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);
    }

    $request = "\x05\x01\x00";
    $packed = inet_pton($host);
    if ($packed !== false && strlen($packed) === 4) {
        $request .= "\x01" . $packed;
    } elseif ($packed !== false && strlen($packed) === 16) {
        $request .= "\x04" . $packed;
    } else {
        $hostLen = strlen($host);
        if ($hostLen > 255) {
            throw new Exception\SocksException('Domain name exceeds maximum length of 255 bytes.');
        }

        $request .= "\x03" . chr($hostLen) . $host;
    }

    $request .= pack('n', $port);
    $stream->write($request, $cancellation);

    $response = $stream->readFixedSize(4, $cancellation);
    if ($response[0] !== "\x05") {
        throw new Exception\SocksException('Invalid SOCKS version in connection response.');
    }

    $reply = ord($response[1]);
    if ($reply !== 0x00) {
        throw new Exception\SocksException(namespace\reply_message($reply));
    }

    $addressType = ord($response[3]);
    match ($addressType) {
        0x01 => $stream->readFixedSize(4, $cancellation),
        0x04 => $stream->readFixedSize(16, $cancellation),
        0x03 => (static function () use ($stream, $cancellation): string {
            $len = ord($stream->readFixedSize(1, $cancellation));
            if ($len === 0) {
                throw new Exception\SocksException('SOCKS5 server returned empty domain in bound address.');
            }

            return $stream->readFixedSize($len, $cancellation);
        })(),
        default => throw new Exception\SocksException("Unknown address type in SOCKS5 response: {$addressType}."),
    };

    $stream->readFixedSize(2, $cancellation);
}
