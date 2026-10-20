<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Connection;

use LogicException;
use PHPUnit\Framework\TestCase;
use Psl\HTTP\Client\Connection\ConnectionMetadata;
use Psl\HTTP\Client\Connection\PooledConnector;
use Psl\HTTP\Client\H2ClientConfiguration;
use Psl\HTTP\Client\Internal\H2\H2Session;
use Psl\IO;
use Psl\Network;
use ReflectionProperty;

final class PooledConnectorDestructionTest extends TestCase
{
    public function testDestructorClosesAllIdleStreamsAfterCloseFailure(): void
    {
        $failed = $this->createMock(Network\StreamInterface::class);
        $failed
            ->expects(static::once())
            ->method('close')
            ->willThrowException(new IO\Exception\RuntimeException('close failed'));
        $second = $this->createMock(Network\StreamInterface::class);
        $second->expects(static::once())->method('close');
        $third = $this->createMock(Network\StreamInterface::class);
        $third->expects(static::once())->method('close');
        $metadata = new ConnectionMetadata(Network\Address::tcp(), Network\Address::tcp('192.0.2.1', 80));
        $session = new H2Session(new IO\MemoryHandle(), new H2ClientConfiguration());
        $connector = new PooledConnector();
        $idle = new ReflectionProperty(PooledConnector::class, 'h1Idle');
        $sessions = new ReflectionProperty(PooledConnector::class, 'h2Sessions');
        $idle->setValue($connector, [
            'first' => [[$failed, $metadata], [$second, $metadata]],
            'second' => [[$third, $metadata]],
        ]);
        $sessions->setValue($connector, ['third' => [$session, $metadata]]);

        try {
            $connector->__destruct();

            static::assertSame([], $idle->getValue($connector));
            static::assertSame([], $sessions->getValue($connector));
            static::assertTrue($session->isClosed());
        } finally {
            $idle->setValue($connector, []);
            $sessions->setValue($connector, []);
        }
    }

    public function testDestructorDoesNotHideProgrammingErrors(): void
    {
        $failure = new LogicException('invalid close state');
        $stream = $this->createMock(Network\StreamInterface::class);
        $stream->expects(static::once())->method('close')->willThrowException($failure);
        $metadata = new ConnectionMetadata(Network\Address::tcp(), Network\Address::tcp('192.0.2.1', 80));
        $connector = new PooledConnector();
        $idle = new ReflectionProperty(PooledConnector::class, 'h1Idle');
        $idle->setValue($connector, ['first' => [[$stream, $metadata]]]);
        $this->expectExceptionObject($failure);

        try {
            $connector->__destruct();
        } finally {
            $idle->setValue($connector, []);
        }
    }
}
