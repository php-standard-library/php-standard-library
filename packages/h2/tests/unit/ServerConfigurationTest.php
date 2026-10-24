<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\H2\RateLimiter;
use Psl\H2\ServerConfiguration;
use Psl\H2\Setting;

use const Psl\H2\DEFAULT_MAX_HEADER_BLOCK_SIZE;

final class ServerConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new ServerConfiguration();

        static::assertSame([], $config->settings);
        static::assertNull($config->rateLimiter);
        static::assertSame(DEFAULT_MAX_HEADER_BLOCK_SIZE, $config->maxHeaderBlockSize);
        static::assertNull($config->maxReceiveWindowSize);
        static::assertSame(65_536, $config->writeBufferThreshold);
    }

    public function testWithSettings(): void
    {
        $config = new ServerConfiguration();
        $new = $config->withSettings([Setting::MaxConcurrentStreams->value => 100]);

        static::assertSame([], $config->settings);
        static::assertSame([Setting::MaxConcurrentStreams->value => 100], $new->settings);
        static::assertNull($new->rateLimiter);
        static::assertSame(DEFAULT_MAX_HEADER_BLOCK_SIZE, $new->maxHeaderBlockSize);
        static::assertNull($new->maxReceiveWindowSize);
        static::assertSame(65_536, $new->writeBufferThreshold);
    }

    public function testWithRateLimiter(): void
    {
        $config = new ServerConfiguration();
        $limiter = RateLimiter::default();
        $new = $config->withRateLimiter($limiter);

        static::assertNull($config->rateLimiter);
        static::assertSame($limiter, $new->rateLimiter);
    }

    public function testWithRateLimiterNull(): void
    {
        $limiter = RateLimiter::default();
        $config = new ServerConfiguration(rateLimiter: $limiter);
        $new = $config->withRateLimiter(null);

        static::assertSame($limiter, $config->rateLimiter);
        static::assertNull($new->rateLimiter);
    }

    public function testWithMaxHeaderBlockSize(): void
    {
        $config = new ServerConfiguration();
        $new = $config->withMaxHeaderBlockSize(8192);

        static::assertSame(DEFAULT_MAX_HEADER_BLOCK_SIZE, $config->maxHeaderBlockSize);
        static::assertSame(8192, $new->maxHeaderBlockSize);
    }

    public function testWithMaxReceiveWindowSize(): void
    {
        $config = new ServerConfiguration();
        $new = $config->withMaxReceiveWindowSize(16_777_216);

        static::assertNull($config->maxReceiveWindowSize);
        static::assertSame(16_777_216, $new->maxReceiveWindowSize);
    }

    public function testWithMaxReceiveWindowSizeNull(): void
    {
        $config = new ServerConfiguration(maxReceiveWindowSize: 16_777_216);
        $new = $config->withMaxReceiveWindowSize(null);

        static::assertSame(16_777_216, $config->maxReceiveWindowSize);
        static::assertNull($new->maxReceiveWindowSize);
    }

    public function testWithWriteBufferThreshold(): void
    {
        $config = new ServerConfiguration();
        $new = $config->withWriteBufferThreshold(131_072);

        static::assertSame(65_536, $config->writeBufferThreshold);
        static::assertSame(131_072, $new->writeBufferThreshold);
    }

    public function testChaining(): void
    {
        $limiter = RateLimiter::default();
        $config = new ServerConfiguration()
            ->withSettings([Setting::MaxConcurrentStreams->value => 50])
            ->withRateLimiter($limiter)
            ->withMaxHeaderBlockSize(4096)
            ->withMaxReceiveWindowSize(8_388_608)
            ->withWriteBufferThreshold(32_768);

        static::assertSame([Setting::MaxConcurrentStreams->value => 50], $config->settings);
        static::assertSame($limiter, $config->rateLimiter);
        static::assertSame(4096, $config->maxHeaderBlockSize);
        static::assertSame(8_388_608, $config->maxReceiveWindowSize);
        static::assertSame(32_768, $config->writeBufferThreshold);
    }

    public function testImmutability(): void
    {
        $original = new ServerConfiguration();
        $modified = $original->withMaxHeaderBlockSize(1024);

        static::assertNotSame($original, $modified);
        static::assertSame(DEFAULT_MAX_HEADER_BLOCK_SIZE, $original->maxHeaderBlockSize);
        static::assertSame(1024, $modified->maxHeaderBlockSize);
    }
}
