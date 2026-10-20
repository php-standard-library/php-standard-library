<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\Origin;
use Psl\HTTP\Client\Connection\PooledConnector;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\HTTP\Message\Request;
use Psl\Unix;
use Psl\URL;

use function file_exists;
use function tempnam;
use function unlink;

use const PHP_OS_FAMILY;

final class PoolIdentityUnixTest extends TestCase
{
    public function testUnixSessionsStaySeparateAndReuseTheirOwnSocket(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('Unix sockets are not supported on Windows.');
        }

        $firstPath = tempnam('/tmp', 'psl-pool-first-');
        $secondPath = tempnam('/tmp', 'psl-pool-second-');
        static::assertNotFalse($firstPath);
        static::assertNotFalse($secondPath);
        unlink($firstPath);
        unlink($secondPath);
        $firstListener = null;
        $secondListener = null;

        try {
            $firstListener = Unix\listen($firstPath);
            $secondListener = Unix\listen($secondPath);
            $pool = new PooledConnector();
            $origin = new Origin('http', 'localhost', 80);
            $request = new Request(method: 'GET', url: URL\parse('http://localhost/'));
            $configuration = new ClientConfiguration(protocolVersions: [ProtocolVersion::V20], unixSocket: $firstPath);
            $first = $pool->connect($origin, $request, $configuration);
            $second = $pool->connect($origin, $request, $configuration->withUnixSocket($secondPath));
            $again = $pool->connect($origin, $request, $configuration);

            static::assertSame($firstPath, $first->metadata->peerAddress->host);
            static::assertSame($secondPath, $second->metadata->peerAddress->host);
            static::assertNotSame($first->metadata, $second->metadata);
            static::assertSame($first->metadata, $again->metadata);
        } finally {
            $firstListener?->close();
            $secondListener?->close();
            if (file_exists($firstPath)) {
                unlink($firstPath);
            }

            if (file_exists($secondPath)) {
                unlink($secondPath);
            }
        }
    }
}
