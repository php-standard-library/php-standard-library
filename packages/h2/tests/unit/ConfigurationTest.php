<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\H2\Configuration;
use Psl\H2\RateLimiter;

use const Psl\H2\DEFAULT_MAX_HEADER_BLOCK_SIZE;

final class ConfigurationTest extends TestCase
{
    public function testDefaultConstructor(): void
    {
        $config = new Configuration();

        static::assertSame([], $config->settings);
        static::assertNull($config->rateLimiter);
        static::assertSame(DEFAULT_MAX_HEADER_BLOCK_SIZE, $config->maxHeaderBlockSize);
        static::assertNull($config->maxReceiveWindowSize);
        static::assertSame(65_536, $config->writeBufferThreshold);
    }

    public function testDefaultFactoryReturnsSameAsConstructor(): void
    {
        $config = Configuration::default();

        static::assertSame([], $config->settings);
        static::assertNull($config->rateLimiter);
        static::assertSame(DEFAULT_MAX_HEADER_BLOCK_SIZE, $config->maxHeaderBlockSize);
        static::assertNull($config->maxReceiveWindowSize);
        static::assertSame(65_536, $config->writeBufferThreshold);
    }

    public function testWithSettings(): void
    {
        $config = new Configuration();
        $new = $config->withSettings([1 => 4096, 3 => 100]);

        static::assertSame([], $config->settings);
        static::assertSame([1 => 4096, 3 => 100], $new->settings);
        static::assertNull($new->rateLimiter);
        static::assertSame(DEFAULT_MAX_HEADER_BLOCK_SIZE, $new->maxHeaderBlockSize);
        static::assertNull($new->maxReceiveWindowSize);
        static::assertSame(65_536, $new->writeBufferThreshold);
    }

    public function testWithRateLimiter(): void
    {
        $limiter = RateLimiter::default();
        $config = new Configuration();
        $new = $config->withRateLimiter($limiter);

        static::assertNull($config->rateLimiter);
        static::assertSame($limiter, $new->rateLimiter);
    }

    public function testWithRateLimiterNull(): void
    {
        $limiter = RateLimiter::default();
        $config = new Configuration(rateLimiter: $limiter);
        $new = $config->withRateLimiter(null);

        static::assertSame($limiter, $config->rateLimiter);
        static::assertNull($new->rateLimiter);
    }

    public function testWithMaxHeaderBlockSize(): void
    {
        $config = new Configuration();
        $new = $config->withMaxHeaderBlockSize(8192);

        static::assertSame(DEFAULT_MAX_HEADER_BLOCK_SIZE, $config->maxHeaderBlockSize);
        static::assertSame(8192, $new->maxHeaderBlockSize);
    }

    public function testWithMaxReceiveWindowSize(): void
    {
        $config = new Configuration();
        $new = $config->withMaxReceiveWindowSize(16_777_216);

        static::assertNull($config->maxReceiveWindowSize);
        static::assertSame(16_777_216, $new->maxReceiveWindowSize);
    }

    public function testWithMaxReceiveWindowSizeNull(): void
    {
        $config = new Configuration(maxReceiveWindowSize: 1_000_000);
        $new = $config->withMaxReceiveWindowSize(null);

        static::assertSame(1_000_000, $config->maxReceiveWindowSize);
        static::assertNull($new->maxReceiveWindowSize);
    }

    public function testWithWriteBufferThreshold(): void
    {
        $config = new Configuration();
        $new = $config->withWriteBufferThreshold(131_072);

        static::assertSame(65_536, $config->writeBufferThreshold);
        static::assertSame(131_072, $new->writeBufferThreshold);
    }

    public function testWithMethodsPreserveOtherFields(): void
    {
        $limiter = RateLimiter::default();
        $config = new Configuration(
            settings: [1 => 100],
            rateLimiter: $limiter,
            maxHeaderBlockSize: 4096,
            maxReceiveWindowSize: 1_000_000,
            writeBufferThreshold: 32_768,
        );

        $new = $config->withSettings([2 => 200]);
        static::assertSame($limiter, $new->rateLimiter);
        static::assertSame(4096, $new->maxHeaderBlockSize);
        static::assertSame(1_000_000, $new->maxReceiveWindowSize);
        static::assertSame(32_768, $new->writeBufferThreshold);

        $new = $config->withMaxHeaderBlockSize(9999);
        static::assertSame([1 => 100], $new->settings);
        static::assertSame($limiter, $new->rateLimiter);
        static::assertSame(1_000_000, $new->maxReceiveWindowSize);
        static::assertSame(32_768, $new->writeBufferThreshold);

        $new = $config->withWriteBufferThreshold(1);
        static::assertSame([1 => 100], $new->settings);
        static::assertSame($limiter, $new->rateLimiter);
        static::assertSame(4096, $new->maxHeaderBlockSize);
        static::assertSame(1_000_000, $new->maxReceiveWindowSize);
    }

    public function testImmutability(): void
    {
        $config = new Configuration();

        $a = $config->withSettings([1 => 1]);
        $b = $config->withSettings([2 => 2]);

        static::assertSame([], $config->settings);
        static::assertSame([1 => 1], $a->settings);
        static::assertSame([2 => 2], $b->settings);
    }
}
