<?php

declare(strict_types=1);

namespace Psl\Network;

use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * @psalm-immutable
 *
 * @api
 */
final readonly class Address
{
    public const string DEFAULT_HOST = '127.0.0.1';
    public const int DEFAULT_PORT = 0;

    public SocketScheme $scheme;

    /**
     * @var non-empty-string
     */
    public string $host;

    /**
     * @var int<0, 65535>|null
     */
    public null|int $port;

    /**
     * @param SocketScheme $scheme
     * @param non-empty-string $host
     * @param int<0,65535>|null $port
     *
     * @psalm-mutation-free
     */
    private function __construct(SocketScheme $scheme, string $host, null|int $port)
    {
        if (
            $scheme !== SocketScheme::Unix
            && strlen($host) > 2
            && str_starts_with($host, '[')
            && str_ends_with($host, ']')
        ) {
            /** @var non-empty-string $host */
            $host = substr($host, 1, -1);
        }

        $this->scheme = $scheme;
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * @param SocketScheme $scheme
     * @param non-empty-string $host
     * @param int<0,65535>|null $port
     *
     * @pure
     */
    public static function create(SocketScheme $scheme, string $host, null|int $port = null): self
    {
        return new self($scheme, $host, $port);
    }

    /**
     * @param non-empty-string $host
     *
     * @pure
     */
    public static function unix(string $host): self
    {
        return new self(SocketScheme::Unix, $host, null);
    }

    /**
     * @param non-empty-string $host
     * @param int<0,65535> $port
     *
     * @pure
     */
    public static function tcp(string $host = self::DEFAULT_HOST, int $port = self::DEFAULT_PORT): self
    {
        return new self(SocketScheme::Tcp, $host, $port);
    }

    /**
     * @param non-empty-string $host
     * @param int<0,65535> $port
     *
     * @pure
     */
    public static function udp(string $host = self::DEFAULT_HOST, int $port = self::DEFAULT_PORT): self
    {
        return new self(SocketScheme::Udp, $host, $port);
    }

    /**
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    public function toString(): string
    {
        $host = $this->scheme !== SocketScheme::Unix && str_contains($this->host, ':')
            ? "[{$this->host}]"
            : $this->host;
        if (null === $this->port) {
            return "{$this->scheme->value}://{$host}";
        }

        return "{$this->scheme->value}://{$host}:{$this->port}";
    }
}
