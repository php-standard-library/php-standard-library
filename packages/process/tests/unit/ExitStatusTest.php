<?php

declare(strict_types=1);

namespace Psl\Process\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Process\ExitStatus;
use Psl\Process\Signal;

use function extension_loaded;

use const SIGUSR1;
use const SIGUSR2;

final class ExitStatusTest extends TestCase
{
    public function testSuccessfulExit(): void
    {
        $status = new ExitStatus(0);

        static::assertTrue($status->isSuccessful());
        static::assertSame(0, $status->getCode());
        static::assertFalse($status->hasBeenSignaled());
        static::assertNull($status->getTerminationSignal());
    }

    public function testFailedExit(): void
    {
        $status = new ExitStatus(1);

        static::assertFalse($status->isSuccessful());
        static::assertSame(1, $status->getCode());
        static::assertFalse($status->hasBeenSignaled());
        static::assertNull($status->getTerminationSignal());
    }

    public function testSignaledExit(): void
    {
        $status = new ExitStatus(137, true, 9);

        static::assertFalse($status->isSuccessful());
        static::assertSame(137, $status->getCode());
        static::assertTrue($status->hasBeenSignaled());
        static::assertSame(Signal::Kill, $status->getTerminationSignal());
    }

    public function testSignaledWithPlatformUserSignals(): void
    {
        if (!extension_loaded('pcntl')) {
            static::markTestSkipped('The pcntl extension is required to know the platform signal numbers.');
        }

        static::assertSame(Signal::User1, new ExitStatus(128 + SIGUSR1, true, SIGUSR1)->getTerminationSignal());
        static::assertSame(Signal::User2, new ExitStatus(128 + SIGUSR2, true, SIGUSR2)->getTerminationSignal());

        // On platforms where SIGUSR1 is not 10 (e.g. SIGBUS on macOS), 10 must not decode as User1.
        if (SIGUSR1 !== Signal::User1->value) {
            static::assertNotSame(Signal::User1, new ExitStatus(128 + 10, true, 10)->getTerminationSignal());
        }

        if (SIGUSR2 !== Signal::User2->value) {
            static::assertNotSame(Signal::User2, new ExitStatus(128 + 12, true, 12)->getTerminationSignal());
        }
    }

    public function testSignaledWithUnknownSignal(): void
    {
        $status = new ExitStatus(200, true, 999);

        static::assertTrue($status->hasBeenSignaled());
        static::assertNull($status->getTerminationSignal());
    }
}
