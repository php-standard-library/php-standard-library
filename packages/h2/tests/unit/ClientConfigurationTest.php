<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\H2\ClientConfiguration;
use Psl\H2\RateLimiter;
use Psl\H2\Setting;

use const Psl\H2\DEFAULT_MAX_HEADER_BLOCK_SIZE;

final class ClientConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new ClientConfiguration();

        static::assertSame([], $config->settings);
        static::assertNull($config->rateLimiter);
        static::assertSame(DEFAULT_MAX_HEADER_BLOCK_SIZE, $config->maxHeaderBlockSize);
        static::assertSame(65_536, $config->writeBufferThreshold);
    }

    public function testWithSettings(): void
    {
        $config = new ClientConfiguration();
        $new = $config->withSettings([Setting::EnablePush->value => 0]);

        static::assertSame([], $config->settings);
        static::assertSame([Setting::EnablePush->value => 0], $new->settings);
        static::assertNull($new->rateLimiter);
        static::assertSame(DEFAULT_MAX_HEADER_BLOCK_SIZE, $new->maxHeaderBlockSize);
        static::assertSame(65_536, $new->writeBufferThreshold);
    }

    public function testWithRateLimiter(): void
    {
        $config = new ClientConfiguration();
        $limiter = RateLimiter::default();
        $new = $config->withRateLimiter($limiter);

        static::assertNull($config->rateLimiter);
        static::assertSame($limiter, $new->rateLimiter);
    }

    public function testWithRateLimiterNull(): void
    {
        $limiter = RateLimiter::default();
        $config = new ClientConfiguration(rateLimiter: $limiter);
        $new = $config->withRateLimiter(null);

        static::assertSame($limiter, $config->rateLimiter);
        static::assertNull($new->rateLimiter);
    }

    public function testWithMaxHeaderBlockSize(): void
    {
        $config = new ClientConfiguration();
        $new = $config->withMaxHeaderBlockSize(8192);

        static::assertSame(DEFAULT_MAX_HEADER_BLOCK_SIZE, $config->maxHeaderBlockSize);
        static::assertSame(8192, $new->maxHeaderBlockSize);
    }

    public function testWithWriteBufferThreshold(): void
    {
        $config = new ClientConfiguration();
        $new = $config->withWriteBufferThreshold(131_072);

        static::assertSame(65_536, $config->writeBufferThreshold);
        static::assertSame(131_072, $new->writeBufferThreshold);
    }

    public function testChaining(): void
    {
        $limiter = RateLimiter::default();
        $config = new ClientConfiguration()
            ->withSettings([Setting::EnablePush->value => 0])
            ->withRateLimiter($limiter)
            ->withMaxHeaderBlockSize(4096)
            ->withWriteBufferThreshold(32_768);

        static::assertSame([Setting::EnablePush->value => 0], $config->settings);
        static::assertSame($limiter, $config->rateLimiter);
        static::assertSame(4096, $config->maxHeaderBlockSize);
        static::assertSame(32_768, $config->writeBufferThreshold);
    }

    public function testImmutability(): void
    {
        $original = new ClientConfiguration();
        $modified = $original->withMaxHeaderBlockSize(1024);

        static::assertNotSame($original, $modified);
        static::assertSame(DEFAULT_MAX_HEADER_BLOCK_SIZE, $original->maxHeaderBlockSize);
        static::assertSame(1024, $modified->maxHeaderBlockSize);
    }
}
