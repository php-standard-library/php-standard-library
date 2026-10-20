<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Internal\StreamTable;
use Psl\H2\StreamState;

use const PHP_INT_MAX;

final class StreamTableTest extends TestCase
{
    public function testOpenAndGet(): void
    {
        $table = new StreamTable();
        $entry = $table->open(1);

        static::assertSame(StreamState::Open, $entry->state);
        static::assertSame($entry, $table->get(1));
    }

    public function testGetReturnsNullForUnknown(): void
    {
        $table = new StreamTable();

        static::assertNull($table->get(999));
    }

    public function testMaxConcurrentStreams(): void
    {
        $table = new StreamTable(2);
        $table->open(1);
        $table->open(3);

        $this->expectException(FlowControlException::class);
        $table->open(5);
    }

    public function testCloseDecrementsActive(): void
    {
        $table = new StreamTable(2);
        $table->open(1);
        $table->open(3);
        $table->close(1);

        $entry = $table->open(5);
        static::assertSame(StreamState::Open, $entry->state);
    }

    public function testGetOrCreate(): void
    {
        $table = new StreamTable();
        $entry = $table->getOrCreate(1);

        static::assertSame(StreamState::Idle, $entry->state);
        static::assertSame($entry, $table->getOrCreate(1));
    }

    public function testMarkHalfClosed(): void
    {
        $table = new StreamTable();
        $table->open(1);
        $table->markHalfClosed(1, StreamState::HalfClosedLocal);

        static::assertSame(StreamState::HalfClosedLocal, $table->get(1)?->state);
    }

    public function testAdjustSendWindows(): void
    {
        $table = new StreamTable();
        $entry = $table->open(1);
        $originalWindow = $entry->sendWindow;

        $table->adjustSendWindows(1000);

        static::assertSame($originalWindow + 1000, $entry->sendWindow);
    }

    public function testActiveCount(): void
    {
        $table = new StreamTable();

        static::assertSame(0, $table->activeCount());

        $table->open(1);
        static::assertSame(1, $table->activeCount());

        $table->open(3);
        static::assertSame(2, $table->activeCount());

        $table->close(1);
        static::assertSame(1, $table->activeCount());
    }

    public function testCloseAlreadyClosedIsNoop(): void
    {
        $table = new StreamTable();
        $table->open(1);
        $table->close(1);
        $table->close(1);

        static::assertSame(0, $table->activeCount());
    }

    public function testCloseNonExistentIsNoop(): void
    {
        $table = new StreamTable();
        $table->close(999);

        static::assertSame(0, $table->activeCount());
    }

    public function testCanAcceptPeerStream(): void
    {
        $table = new StreamTable(peerMaxConcurrent: 2);

        static::assertTrue($table->canAcceptPeerStream());

        $table->open(2);
        static::assertTrue($table->canAcceptPeerStream());

        $table->open(4);
        static::assertFalse($table->canAcceptPeerStream());
    }

    public function testIncrementActive(): void
    {
        $table = new StreamTable();

        static::assertSame(0, $table->activeCount());

        $table->incrementActive(2);
        static::assertSame(1, $table->activeCount());

        $table->incrementActive(2);
        static::assertSame(2, $table->activeCount());
    }

    public function testSetMaxConcurrent(): void
    {
        $table = new StreamTable(maxConcurrent: 1);
        $table->open(1);

        $this->expectException(FlowControlException::class);
        $table->open(3);
    }

    public function testSetMaxConcurrentIncrease(): void
    {
        $table = new StreamTable(maxConcurrent: 1);
        $table->open(1);

        $table->setMaxConcurrent(5);

        $entry = $table->open(3);
        static::assertSame(StreamState::Open, $entry->state);
    }

    public function testSetInitialSendWindow(): void
    {
        $table = new StreamTable();
        $table->setInitialSendWindow(1000);

        $entry = $table->open(1);
        static::assertSame(1000, $entry->sendWindow);
    }

    public function testSetInitialReceiveWindow(): void
    {
        $table = new StreamTable();
        $table->setInitialReceiveWindow(2000);

        $entry = $table->open(1);
        static::assertSame(2000, $entry->receiveWindow);
    }

    public function testAdjustSendWindowsOverflow(): void
    {
        $table = new StreamTable(maxConcurrent: PHP_INT_MAX, initialSendWindow: 2_147_483_640);
        $table->open(1);

        $this->expectException(FlowControlException::class);

        $table->adjustSendWindows(100);
    }

    public function testAdjustSendWindowsSkipsNonOpenStreams(): void
    {
        $table = new StreamTable();
        $entry = $table->open(1);
        $table->markHalfClosed(1, StreamState::HalfClosedLocal);
        $originalWindow = $entry->sendWindow;

        $table->adjustSendWindows(1000);

        static::assertSame($originalWindow, $entry->sendWindow);
    }

    public function testAdjustSendWindowsAffectsHalfClosedRemote(): void
    {
        $table = new StreamTable();
        $entry = $table->open(1);
        $table->markHalfClosed(1, StreamState::HalfClosedRemote);
        $originalWindow = $entry->sendWindow;

        $table->adjustSendWindows(500);

        static::assertSame($originalWindow + 500, $entry->sendWindow);
    }

    public function testCloseHalfClosedLocalDecrementsActive(): void
    {
        $table = new StreamTable();
        $table->open(1);
        $table->markHalfClosed(1, StreamState::HalfClosedLocal);

        static::assertSame(1, $table->activeCount());

        $table->close(1);

        static::assertSame(0, $table->activeCount());
    }

    public function testCloseHalfClosedRemoteDecrementsActive(): void
    {
        $table = new StreamTable();
        $table->open(1);
        $table->markHalfClosed(1, StreamState::HalfClosedRemote);

        static::assertSame(1, $table->activeCount());

        $table->close(1);

        static::assertSame(0, $table->activeCount());
    }

    public function testCloseReservedLocalDoesNotDecrementActive(): void
    {
        $table = new StreamTable();
        $entry = $table->getOrCreate(1);
        $entry->state = StreamState::ReservedLocal;
        $table->open(3);

        static::assertSame(1, $table->activeCount());

        $table->close(1);

        static::assertSame(1, $table->activeCount());
    }

    public function testCloseReservedRemoteDoesNotDecrementActive(): void
    {
        $table = new StreamTable();
        $entry = $table->getOrCreate(1);
        $entry->state = StreamState::ReservedRemote;
        $table->open(3);

        static::assertSame(1, $table->activeCount());

        $table->close(1);

        static::assertSame(1, $table->activeCount());
    }

    public function testCloseIdleStreamDoesNotDecrementActive(): void
    {
        $table = new StreamTable();
        $table->getOrCreate(1);

        static::assertSame(0, $table->activeCount());

        $table->close(1);

        static::assertSame(0, $table->activeCount());
    }

    public function testMarkHalfClosedNonExistentIsNoop(): void
    {
        $table = new StreamTable();
        $table->markHalfClosed(999, StreamState::HalfClosedLocal);

        static::assertNull($table->get(999));
    }
}
