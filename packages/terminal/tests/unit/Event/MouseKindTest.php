<?php

declare(strict_types=1);

namespace Psl\Terminal\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Psl\Terminal\Event\MouseKind;

final class MouseKindTest extends TestCase
{
    public function testAllValues(): void
    {
        $cases = MouseKind::cases();

        static::assertCount(8, $cases);
        static::assertContains(MouseKind::Press, $cases);
        static::assertContains(MouseKind::Release, $cases);
        static::assertContains(MouseKind::Drag, $cases);
        static::assertContains(MouseKind::ScrollUp, $cases);
        static::assertContains(MouseKind::ScrollDown, $cases);
        static::assertContains(MouseKind::Move, $cases);
        static::assertContains(MouseKind::ScrollLeft, $cases);
        static::assertContains(MouseKind::ScrollRight, $cases);
    }
}
