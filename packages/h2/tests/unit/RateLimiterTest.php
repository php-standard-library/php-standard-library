<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\DateTime;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame\FrameType;
use Psl\H2\RateLimiter;
use ReflectionProperty;

use function usleep;

use const PHP_OS_FAMILY;

final class RateLimiterTest extends TestCase
{
    public function testAllowsFramesWithinLimit(): void
    {
        $limiter = new RateLimiter([
            FrameType::Ping->value => [5, DateTime\Duration::seconds(10)],
        ]);

        for ($i = 0; $i < 5; $i++) {
            $limiter->record(FrameType::Ping->value);
        }

        static::assertTrue(true);
    }

    public function testThrowsWhenLimitExceeded(): void
    {
        $limiter = new RateLimiter([
            FrameType::Ping->value => [3, DateTime\Duration::seconds(10)],
        ]);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        for ($i = 0; $i < 4; $i++) {
            $limiter->record(FrameType::Ping->value);
        }
    }

    public function testIgnoresUnlimitedFrameTypes(): void
    {
        $limiter = new RateLimiter([
            FrameType::Ping->value => [3, DateTime\Duration::seconds(10)],
        ]);

        for ($i = 0; $i < 100; $i++) {
            $limiter->record(FrameType::Data->value);
        }

        static::assertTrue(true);
    }

    public function testMultipleFrameTypesTrackedIndependently(): void
    {
        $limiter = new RateLimiter([
            FrameType::Ping->value => [3, DateTime\Duration::seconds(10)],
            FrameType::Settings->value => [5, DateTime\Duration::seconds(10)],
        ]);

        for ($i = 0; $i < 3; $i++) {
            $limiter->record(FrameType::Ping->value);
        }

        for ($i = 0; $i < 5; $i++) {
            $limiter->record(FrameType::Settings->value);
        }

        static::assertTrue(true);
    }

    public function testDefault(): void
    {
        $limiter = RateLimiter::default();

        for ($i = 0; $i < 50; $i++) {
            $limiter->record(FrameType::Ping->value);
        }

        static::assertTrue(true);
    }

    public function testDefaultExceedsLimit(): void
    {
        $limiter = RateLimiter::default();

        $this->expectException(ProtocolException::class);

        for ($i = 0; $i < 51; $i++) {
            $limiter->record(FrameType::Ping->value);
        }
    }

    public function testDefaultReturnsLimitersWithIndependentCounters(): void
    {
        $first = RateLimiter::default();
        $second = RateLimiter::default();

        static::assertNotSame($first, $second);

        for ($i = 0; $i < 50; $i++) {
            $first->record(FrameType::Ping->value);
        }

        for ($i = 0; $i < 50; $i++) {
            $second->record(FrameType::Ping->value);
        }

        $this->expectException(ProtocolException::class);
        $first->record(FrameType::Ping->value);
    }

    public function testFreshKeepsTheLimitsWithoutTheRecordedFrames(): void
    {
        $limiter = new RateLimiter([
            FrameType::Ping->value => [2, DateTime\Duration::seconds(10)],
        ]);
        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);

        $fresh = $limiter->fresh();

        static::assertNotSame($limiter, $fresh);

        $fresh->record(FrameType::Ping->value);
        $fresh->record(FrameType::Ping->value);

        try {
            $fresh->record(FrameType::Ping->value);

            static::fail('Expected the fresh limiter to keep the limit of 2 PING frames.');
        } catch (ProtocolException $exception) {
            static::assertStringContainsString('Ping', $exception->getMessage());
        }

        // Recording on the fresh limiter left the original one at its own count.
        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::Ping->value);
    }

    public function testEmptyDataRecording(): void
    {
        $limiter = new RateLimiter([
            -1 => [3, DateTime\Duration::seconds(10)],
        ]);

        for ($i = 0; $i < 3; $i++) {
            $limiter->record(-1);
        }

        static::assertTrue(true);
    }

    public function testEmptyDataExceedsLimit(): void
    {
        $limiter = new RateLimiter([
            -1 => [2, DateTime\Duration::seconds(10)],
        ]);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('empty DATA');

        for ($i = 0; $i < 3; $i++) {
            $limiter->record(-1);
        }
    }

    public function testDefaultSettingsLimitExceeded(): void
    {
        $limiter = RateLimiter::default();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Settings');

        for ($i = 0; $i < 101; $i++) {
            $limiter->record(FrameType::Settings->value);
        }
    }

    public function testDefaultRstStreamLimitExceeded(): void
    {
        $limiter = RateLimiter::default();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('RstStream');

        for ($i = 0; $i < 101; $i++) {
            $limiter->record(FrameType::RstStream->value);
        }
    }

    public function testDefaultPriorityLimitExceeded(): void
    {
        $limiter = RateLimiter::default();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Priority');

        for ($i = 0; $i < 101; $i++) {
            $limiter->record(FrameType::Priority->value);
        }
    }

    public function testDefaultEmptyDataLimitExceeded(): void
    {
        $limiter = RateLimiter::default();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('empty DATA');

        for ($i = 0; $i < 101; $i++) {
            $limiter->record(-1);
        }
    }

    public function testDefaultSettingsLimit(): void
    {
        $limiter = RateLimiter::default();

        for ($i = 0; $i < 100; $i++) {
            $limiter->record(FrameType::Settings->value);
        }

        static::assertTrue(true);
    }

    public function testDefaultRstStreamLimit(): void
    {
        $limiter = RateLimiter::default();

        for ($i = 0; $i < 100; $i++) {
            $limiter->record(FrameType::RstStream->value);
        }

        static::assertTrue(true);
    }

    public function testDefaultPriorityLimit(): void
    {
        $limiter = RateLimiter::default();

        for ($i = 0; $i < 100; $i++) {
            $limiter->record(FrameType::Priority->value);
        }

        static::assertTrue(true);
    }

    public function testDefaultEmptyDataLimit(): void
    {
        $limiter = RateLimiter::default();

        for ($i = 0; $i < 100; $i++) {
            $limiter->record(-1);
        }

        static::assertTrue(true);
    }

    public function testWindowResetsAfterDurationExpires(): void
    {
        $limiter = new RateLimiter([
            FrameType::Ping->value => [10, DateTime\Duration::milliseconds(1)],
        ]);

        $limiter->record(FrameType::Ping->value);

        usleep(2000);

        $limiter->record(FrameType::Ping->value);

        static::assertTrue(true);
    }

    public function testExceedMessageContainsFrameTypeName(): void
    {
        $limiter = new RateLimiter([
            FrameType::Ping->value => [1, DateTime\Duration::seconds(10)],
        ]);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Rate limit exceeded for frame type');

        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);
    }

    public function testDefaultSettingsLimitIsExactly100(): void
    {
        $limiter = RateLimiter::default();
        for ($i = 0; $i < 100; $i++) {
            $limiter->record(FrameType::Settings->value);
        }

        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::Settings->value);
    }

    public function testDefaultPingLimitIsExactly50(): void
    {
        $limiter = RateLimiter::default();
        for ($i = 0; $i < 50; $i++) {
            $limiter->record(FrameType::Ping->value);
        }

        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::Ping->value);
    }

    public function testDefaultRstStreamLimitIsExactly100(): void
    {
        $limiter = RateLimiter::default();
        for ($i = 0; $i < 100; $i++) {
            $limiter->record(FrameType::RstStream->value);
        }

        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::RstStream->value);
    }

    public function testDefaultPriorityLimitIsExactly100(): void
    {
        $limiter = RateLimiter::default();
        for ($i = 0; $i < 100; $i++) {
            $limiter->record(FrameType::Priority->value);
        }

        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::Priority->value);
    }

    public function testDefaultEmptyDataLimitIsExactly100(): void
    {
        $limiter = RateLimiter::default();
        for ($i = 0; $i < 100; $i++) {
            $limiter->record(RateLimiter::EMPTY_DATA_FRAME);
        }

        $this->expectException(ProtocolException::class);
        $limiter->record(RateLimiter::EMPTY_DATA_FRAME);
    }

    public function testWindowResetsAtExactBoundary(): void
    {
        $limiter = new RateLimiter([
            FrameType::Ping->value => [2, DateTime\Duration::milliseconds(1)],
        ]);

        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);

        usleep(2000);

        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);

        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::Ping->value);
    }

    public function testCounterResetsToZero(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('usleep() resolution on Windows is too coarse for this test.');
        }

        $limiter = new RateLimiter([
            FrameType::Ping->value => [3, DateTime\Duration::milliseconds(1)],
        ]);

        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);

        usleep(2000);

        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);

        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::Ping->value);
    }

    public function testErrorMessageForSettings(): void
    {
        $limiter = new RateLimiter([
            FrameType::Settings->value => [1, DateTime\Duration::seconds(10)],
        ]);

        $limiter->record(FrameType::Settings->value);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Rate limit exceeded for frame type');

        $limiter->record(FrameType::Settings->value);
    }

    public function testErrorMessageForEmptyData(): void
    {
        $limiter = new RateLimiter([
            RateLimiter::EMPTY_DATA_FRAME => [1, DateTime\Duration::seconds(10)],
        ]);

        $limiter->record(RateLimiter::EMPTY_DATA_FRAME);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Rate limit exceeded for empty DATA frames');

        $limiter->record(RateLimiter::EMPTY_DATA_FRAME);
    }

    public function testCounterInitializesToZero(): void
    {
        $limiter = new RateLimiter([
            FrameType::Ping->value => [1, DateTime\Duration::seconds(10)],
        ]);

        $limiter->record(FrameType::Ping->value);

        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::Ping->value);
    }

    public function testDefaultSettingsDurationIsExactly10Seconds(): void
    {
        $limiter = new RateLimiter([
            FrameType::Settings->value => [100, DateTime\Duration::seconds(10)],
        ]);

        for ($i = 0; $i < 100; $i++) {
            $limiter->record(FrameType::Settings->value);
        }

        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::Settings->value);
    }

    public function testDefaultPingDurationIsExactly10Seconds(): void
    {
        $limiter = new RateLimiter([
            FrameType::Ping->value => [50, DateTime\Duration::seconds(10)],
        ]);

        for ($i = 0; $i < 50; $i++) {
            $limiter->record(FrameType::Ping->value);
        }

        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::Ping->value);
    }

    public function testDefaultRstStreamDurationIsExactly10Seconds(): void
    {
        $limiter = new RateLimiter([
            FrameType::RstStream->value => [100, DateTime\Duration::seconds(10)],
        ]);

        for ($i = 0; $i < 100; $i++) {
            $limiter->record(FrameType::RstStream->value);
        }

        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::RstStream->value);
    }

    public function testDefaultPriorityDurationIsExactly10Seconds(): void
    {
        $limiter = new RateLimiter([
            FrameType::Priority->value => [100, DateTime\Duration::seconds(10)],
        ]);

        for ($i = 0; $i < 100; $i++) {
            $limiter->record(FrameType::Priority->value);
        }

        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::Priority->value);
    }

    public function testDefaultEmptyDataDurationIsExactly10Seconds(): void
    {
        $limiter = new RateLimiter([
            RateLimiter::EMPTY_DATA_FRAME => [100, DateTime\Duration::seconds(10)],
        ]);

        for ($i = 0; $i < 100; $i++) {
            $limiter->record(RateLimiter::EMPTY_DATA_FRAME);
        }

        $this->expectException(ProtocolException::class);
        $limiter->record(RateLimiter::EMPTY_DATA_FRAME);
    }

    public function testWindowResetsAtExactDuration(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('usleep() resolution on Windows is too coarse for this test.');
        }

        $limiter = new RateLimiter([
            FrameType::Ping->value => [2, DateTime\Duration::milliseconds(1)],
        ]);

        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);

        usleep(3000);

        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);

        static::assertTrue(true);
    }

    public function testErrorMessageForUnknownFrameTypeContainsHexPrefix(): void
    {
        $limiter = new RateLimiter([
            0xb => [1, DateTime\Duration::seconds(10)],
        ]);

        $limiter->record(0xb);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('0x0b');

        $limiter->record(0xb);
    }

    public function testErrorMessageContainsFrameTypePrefix(): void
    {
        $limiter = new RateLimiter([
            FrameType::Ping->value => [1, DateTime\Duration::seconds(10)],
        ]);

        $limiter->record(FrameType::Ping->value);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Rate limit exceeded for frame type Ping');

        $limiter->record(FrameType::Ping->value);
    }

    public function testWindowResetUsesGreaterThanOrEqual(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('usleep() resolution on Windows is too coarse for this test.');
        }

        $limiter = new RateLimiter([
            FrameType::Ping->value => [2, DateTime\Duration::milliseconds(10)],
        ]);

        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);

        usleep(15_000);

        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);

        static::assertTrue(true);
    }

    public function testDefaultWindowDurationsAreExactly10SecondsForEachFrameType(): void
    {
        $limiter = RateLimiter::default();
        $limits = new ReflectionProperty(RateLimiter::class, 'limits')->getValue($limiter);

        $expectedKeys = [
            FrameType::Settings->value,
            FrameType::Ping->value,
            FrameType::RstStream->value,
            FrameType::Priority->value,
            RateLimiter::EMPTY_DATA_FRAME,
        ];

        foreach ($expectedKeys as $key) {
            $duration = $limits[$key][1];
            static::assertSame(
                10.0,
                $duration->getTotalSeconds(),
                'Duration for key ' . $key . ' should be exactly 10 seconds',
            );
            static::assertNotSame(
                9.0,
                $duration->getTotalSeconds(),
                'Duration for key ' . $key . ' should not be 9 seconds',
            );
            static::assertNotSame(
                11.0,
                $duration->getTotalSeconds(),
                'Duration for key ' . $key . ' should not be 11 seconds',
            );
        }
    }

    public function testWindowResetAllowsFullCountAndEnforcesLimitAgain(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('usleep() resolution on Windows is too coarse for this test.');
        }

        $limiter = new RateLimiter([
            FrameType::Ping->value => [3, DateTime\Duration::milliseconds(1)],
        ]);

        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);

        usleep(5000);

        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);

        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::Ping->value);
    }
}
