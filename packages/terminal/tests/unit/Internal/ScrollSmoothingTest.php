<?php

declare(strict_types=1);

namespace Psl\Terminal\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Psl\Terminal\Event;
use Psl\Terminal\Internal\ScrollSmoothing;

final class ScrollSmoothingTest extends TestCase
{
    private ScrollSmoothing $filter;

    protected function setUp(): void
    {
        $this->filter = new ScrollSmoothing();
    }

    public function testFirstScrollEventAlwaysPasses(): void
    {
        static::assertTrue($this->filter->filter(self::scrollDown()));
    }

    public function testSameDirectionEventsPasses(): void
    {
        static::assertTrue($this->filter->filter(self::scrollDown()));
        static::assertTrue($this->filter->filter(self::scrollDown()));
        static::assertTrue($this->filter->filter(self::scrollDown()));
        static::assertTrue($this->filter->filter(self::scrollDown()));
    }

    public function testSingleReversalIsSuppressed(): void
    {
        static::assertTrue($this->filter->filter(self::scrollDown()));
        static::assertTrue($this->filter->filter(self::scrollDown()));
        static::assertFalse($this->filter->filter(self::scrollUp()));
        static::assertTrue($this->filter->filter(self::scrollDown()));
    }

    public function testTwoConsecutiveReversalsSwitchDirection(): void
    {
        static::assertTrue($this->filter->filter(self::scrollDown()));
        static::assertTrue($this->filter->filter(self::scrollDown()));
        static::assertFalse($this->filter->filter(self::scrollUp()));
        static::assertTrue($this->filter->filter(self::scrollUp()));
        static::assertTrue($this->filter->filter(self::scrollUp()));
    }

    public function testNonScrollMouseEventsAlwaysPass(): void
    {
        static::assertTrue($this->filter->filter(self::scrollDown()));

        static::assertTrue($this->filter->filter(new Event\Mouse(Event\MouseKind::Press, 10, 20)));
        static::assertTrue($this->filter->filter(new Event\Mouse(Event\MouseKind::Release, 10, 20)));
        static::assertTrue($this->filter->filter(new Event\Mouse(Event\MouseKind::Move, 10, 20)));
        static::assertTrue($this->filter->filter(new Event\Mouse(Event\MouseKind::Drag, 10, 20)));
    }

    public function testHorizontalScrollEventsAlwaysPassAndKeepVerticalDirection(): void
    {
        static::assertTrue($this->filter->filter(self::scrollDown()));

        static::assertTrue($this->filter->filter(new Event\Mouse(Event\MouseKind::ScrollLeft, 0, 0)));
        static::assertTrue($this->filter->filter(new Event\Mouse(Event\MouseKind::ScrollRight, 0, 0)));
        static::assertTrue($this->filter->filter(new Event\Mouse(Event\MouseKind::ScrollLeft, 0, 0)));

        static::assertTrue($this->filter->filter(self::scrollDown()));
        static::assertFalse($this->filter->filter(self::scrollUp()));
    }

    public function testDirectionResetsAfterAcceptedReversal(): void
    {
        static::assertTrue($this->filter->filter(self::scrollDown()));

        static::assertFalse($this->filter->filter(self::scrollUp()));
        static::assertTrue($this->filter->filter(self::scrollUp()));

        static::assertFalse($this->filter->filter(self::scrollDown()));

        static::assertTrue($this->filter->filter(self::scrollUp()));
    }

    public function testReversalCountResetsOnSameDirection(): void
    {
        static::assertTrue($this->filter->filter(self::scrollDown()));

        static::assertFalse($this->filter->filter(self::scrollUp()));

        static::assertTrue($this->filter->filter(self::scrollDown()));

        static::assertFalse($this->filter->filter(self::scrollUp()));
    }

    private static function scrollUp(): Event\Mouse
    {
        return new Event\Mouse(Event\MouseKind::ScrollUp, 0, 0);
    }

    private static function scrollDown(): Event\Mouse
    {
        return new Event\Mouse(Event\MouseKind::ScrollDown, 0, 0);
    }
}
